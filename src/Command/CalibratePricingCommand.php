<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Request;
use App\Enum\BidStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:calibrate-pricing',
    description: 'Genera un ajuste automático del CSV de precios de Gemini en base a las pujas aceptadas.',
)]
final class CalibratePricingCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'since',
                null,
                InputOption::VALUE_OPTIONAL,
                'Fecha desde la que analizar solicitudes (YYYY-MM-DD). Por defecto, primeros de mes anterior.',
                null
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Solo muestra el informe sin modificar el CSV.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sinceOpt = $input->getOption('since');
        if ($sinceOpt) {
            $since = new \DateTimeImmutable($sinceOpt . ' 00:00:00');
        } else {
            $firstDayLastMonth = new \DateTimeImmutable('first day of last month 00:00:00');
            $since = $firstDayLastMonth;
        }

        $io->title('Calibración automática de precios (Gemini CSV)');
        $io->writeln(sprintf('Analizando requests desde: <info>%s</info>', $since->format('Y-m-d')));

        // 1) Recoger datos: requests con diagnosis de IA + puja aceptada.
        $qb = $this->em->createQueryBuilder();
        $qb
            ->select('r', 'b')
            ->from(Request::class, 'r')
            ->join('r.bids', 'b')
            ->where('b.status = :accepted')
            ->andWhere('r.aiDiagnosis IS NOT NULL')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('accepted', BidStatus::ACCEPTED)
            ->setParameter('since', $since);

        /** @var Request[] $requests */
        $requests = $qb->getQuery()->getResult();

        if (count($requests) === 0) {
            $io->warning('No se encontraron requests con pujas aceptadas en el rango indicado.');
            return Command::SUCCESS;
        }

        // 2) Agregar estadísticas por subcategoría (usando la sub_category devuelta por Gemini).
        $stats = [];
        foreach ($requests as $request) {
            $diag = $request->getAiDiagnosis() ?? [];
            $min = $diag['estimated_price_min'] ?? null;
            $max = $diag['estimated_price_max'] ?? null;
            $subCategory = $diag['sub_category'] ?? null;
            $riskLevel = $diag['risk_level'] ?? null;
            if (!\is_int($min) || !\is_int($max) || $min <= 0 || $max <= 0) {
                continue;
            }
            if (!\is_string($subCategory) || $subCategory === '') {
                // Requests antiguas sin sub_category o diagnosis incompleta: las ignoramos para calibración fina.
                continue;
            }
            $aiMid = ($min + $max) / 2.0;

            foreach ($request->getBids() as $bid) {
                if ($bid->getStatus() !== BidStatus::ACCEPTED) {
                    continue;
                }
                $accepted = $bid->getPriceQuote();
                if (!\is_int($accepted) || $accepted <= 0) {
                    continue;
                }

                $key = trim($subCategory);
                if (!isset($stats[$key])) {
                    $stats[$key] = [
                        'count' => 0,
                        'sum_ai_mid' => 0.0,
                        'sum_accepted' => 0.0,
                        'category' => $request->getCategory()->value,
                        'risk_counts' => [
                            'LOW' => 0,
                            'MEDIUM' => 0,
                            'HIGH' => 0,
                        ],
                    ];
                }
                $stats[$key]['count']++;
                $stats[$key]['sum_ai_mid'] += $aiMid;
                $stats[$key]['sum_accepted'] += $accepted;

                if (\is_string($riskLevel)) {
                    $riskKey = strtoupper($riskLevel);
                    if (isset($stats[$key]['risk_counts'][$riskKey])) {
                        $stats[$key]['risk_counts'][$riskKey]++;
                    }
                }
            }
        }

        if (empty($stats)) {
            $io->warning('No se pudieron calcular estadísticas (diagnosis incompletas o sin pujas aceptadas válidas).');
            return Command::SUCCESS;
        }

        $io->section('Estadísticas por subcategoría (Gemini sub_category)');
        $rows = [];
        foreach ($stats as $subCategoryKey => $s) {
            $count = $s['count'];
            $avgAiMid = $s['sum_ai_mid'] / $count;
            $avgAccepted = $s['sum_accepted'] / $count;
            $factor = $avgAccepted > 0 ? $avgAccepted / $avgAiMid : 1.0;
            $rows[] = [
                $subCategoryKey,
                $count,
                number_format($avgAiMid / 100, 2) . ' €',
                number_format($avgAccepted / 100, 2) . ' €',
                sprintf('%+.1f %%', ($factor - 1.0) * 100),
            ];
        }
        $io->table(['Subcategoría', 'Nº trabajos', 'AI medio', 'Aceptado medio', 'Desviación'], $rows);

        // 3) Leer CSV actual.
        $csvPath = $this->projectDir . '/config/gemini_pricing.csv';
        if (!is_file($csvPath)) {
            $io->error(sprintf('No se ha encontrado el CSV de precios en %s', $csvPath));
            return Command::FAILURE;
        }

        $original = file($csvPath, FILE_IGNORE_NEW_LINES);
        if ($original === false || count($original) === 0) {
            $io->error('No se pudo leer gemini_pricing.csv o está vacío.');
            return Command::FAILURE;
        }

        // 4) Calcular factores por subcategoría y aplicarlos a las filas del CSV.
        //    Para no sobre-reaccionar: clamp entre 0.7 y 1.3.
        $factors = [];
        foreach ($stats as $subCategoryKey => $s) {
            $count = $s['count'];
            if ($count < 3) {
                // Muy pocos datos, no ajustamos esa subcategoría aún.
                continue;
            }
            $avgAiMid = $s['sum_ai_mid'] / $count;
            $avgAccepted = $s['sum_accepted'] / $count;
            if ($avgAiMid <= 0 || $avgAccepted <= 0) {
                continue;
            }
            $rawFactor = $avgAccepted / $avgAiMid;
            $factor = max(0.7, min(1.3, $rawFactor));
            $factors[$subCategoryKey] = $factor;
        }

        if (empty($factors)) {
            $io->warning('No hay suficientes datos por subcategoría como para proponer ajustes (mínimo 3 trabajos por subcategoría).');
            return Command::SUCCESS;
        }

        $io->section('Factores de ajuste propuestos por subcategoría');
        $rows = [];
        foreach ($factors as $subCategoryKey => $factor) {
            $rows[] = [$subCategoryKey, sprintf('x %.3f', $factor), sprintf('%+.1f %%', ($factor - 1.0) * 100)];
        }
        $io->table(['Subcategoría (Gemini)', 'Factor', 'Variación'], $rows);

        // Procesar líneas del CSV (sin tocar cabecera) y registrar qué subcategorías existen ya.
        $newLines = [];
        $header = array_shift($original);
        $newLines[] = $header;

        $existingSubcategories = [];

        foreach ($original as $line) {
            if (trim($line) === '') {
                $newLines[] = $line;
                continue;
            }
            $cols = str_getcsv($line);
            if (count($cols) < 7) {
                $newLines[] = $line;
                continue;
            }

            [$categoria, $subcategoria, $zona, $minStr, $maxStr, $unidad, $complejidad] = $cols;
            $subcategoriaTrim = trim($subcategoria);

            if ($subcategoriaTrim !== '') {
                $existingSubcategories[$subcategoriaTrim] = true;
            }

            // Solo ajustar si tenemos factor para esa subcategoría (por nombre).
            if (!isset($factors[$subcategoriaTrim])) {
                $newLines[] = $line;
                continue;
            }

            $factor = $factors[$subcategoriaTrim];
            $min = (int) $minStr;
            $max = (int) $maxStr;
            if ($min <= 0 || $max <= 0) {
                $newLines[] = $line;
                continue;
            }

            $newMin = (int) round($min * $factor);
            $newMax = (int) round($max * $factor);
            if ($newMin < 0) {
                $newMin = 0;
            }
            if ($newMax <= $newMin) {
                $newMax = $newMin + 1;
            }

            $newCols = [
                $categoria,
                $subcategoriaTrim,
                $zona,
                (string) $newMin,
                (string) $newMax,
                $unidad,
                $complejidad,
            ];
            $newLines[] = implode(',', $newCols);
        }

        // 4b) Añadir líneas nuevas para subcategorías que no existan aún en el CSV.
        foreach ($factors as $subCategoryKey => $factor) {
            if (isset($existingSubcategories[$subCategoryKey])) {
                continue;
            }

            // Usar categoría (enum) asociada a esta subcategoría como base.
            $baseCategory = $stats[$subCategoryKey]['category'] ?? 'DIY';
            $count = $stats[$subCategoryKey]['count'];
            $avgAccepted = $stats[$subCategoryKey]['sum_accepted'] / max(1, $count);

            // Crear un rango razonable alrededor del precio medio aceptado (±20%).
            $newMin = (int) round($avgAccepted * 0.8);
            $newMax = (int) round($avgAccepted * 1.2);
            if ($newMin < 0) {
                $newMin = 0;
            }
            if ($newMax <= $newMin) {
                $newMax = $newMin + 1;
            }

            // Zona: estamos centrados en Córdoba → zona "Córdoba".
            $zona = 'Córdoba';
            $unidad = 'Servicio';

            // Complejidad en base al riesgo predominante de esa subcategoría.
            $riskCounts = $stats[$subCategoryKey]['risk_counts'] ?? ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0];
            arsort($riskCounts);
            $topRisk = array_key_first($riskCounts);
            $complejidad = match ($topRisk) {
                'HIGH' => 'Alta',
                'MEDIUM' => 'Media',
                default => 'Baja',
            };

            $newLines[] = implode(',', [
                $baseCategory,
                $subCategoryKey,
                $zona,
                (string) $newMin,
                (string) $newMax,
                $unidad,
                $complejidad,
            ]);
        }

        if ($input->getOption('dry-run')) {
            $io->warning('Dry-run activado: no se ha modificado el CSV. Vista previa de las primeras filas ajustadas:');
            $preview = array_slice($newLines, 0, 10);
            foreach ($preview as $l) {
                $io->writeln($l);
            }
            return Command::SUCCESS;
        }

        // 5) Backup y escritura.
        $backupPath = $csvPath . '.' . (new \DateTimeImmutable())->format('Ymd_His') . '.bak';
        if (!@copy($csvPath, $backupPath)) {
            $io->warning(sprintf('No se pudo crear backup en %s. Se continuará igualmente.', $backupPath));
        } else {
            $io->writeln(sprintf('Backup creado en: <info>%s</info>', $backupPath));
        }

        $result = @file_put_contents($csvPath, implode(PHP_EOL, $newLines) . PHP_EOL);
        if ($result === false) {
            $io->error('Error al escribir el CSV actualizado.');
            return Command::FAILURE;
        }

        $io->success('CSV de precios actualizado correctamente. Recuerda regenerar la cache de Gemini para que use estos nuevos rangos.');

        return Command::SUCCESS;
    }
}

