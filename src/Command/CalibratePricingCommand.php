<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PricingRate;
use App\Entity\Request;
use App\Enum\BidStatus;
use App\Repository\PricingRateRepository;
use App\Service\GeminiCacheService;
use App\Service\PricingCatalogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:calibrate-pricing',
    description: 'Ajusta tarifas en BD (pricing_rate) según pujas aceptadas e invalida la caché Gemini.',
)]
final class CalibratePricingCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PricingRateRepository $pricingRateRepository,
        private readonly PricingCatalogService $pricingCatalogService,
        private readonly GeminiCacheService $geminiCacheService,
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
                'Solo muestra el informe sin modificar la BD.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sinceOpt = $input->getOption('since');
        if ($sinceOpt) {
            $since = new \DateTimeImmutable($sinceOpt . ' 00:00:00');
        } else {
            $since = new \DateTimeImmutable('first day of last month 00:00:00');
        }

        $io->title('Calibración automática de precios (BD pricing_rate)');
        $io->writeln(sprintf('Analizando requests desde: <info>%s</info>', $since->format('Y-m-d')));

        $this->pricingCatalogService->ensureSeeded();

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

        /** @var array<string, array{count: int, sum_ai_mid: float, sum_accepted: float, category: string, risk_counts: array{LOW: int, MEDIUM: int, HIGH: int}}> $stats */
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

        if ($stats === []) {
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

        $factors = [];
        foreach ($stats as $subCategoryKey => $s) {
            if ($s['count'] < 3) {
                continue;
            }
            $avgAiMid = $s['sum_ai_mid'] / $s['count'];
            $avgAccepted = $s['sum_accepted'] / $s['count'];
            if ($avgAiMid <= 0 || $avgAccepted <= 0) {
                continue;
            }
            $factors[$subCategoryKey] = max(0.7, min(1.3, $avgAccepted / $avgAiMid));
        }

        if ($factors === []) {
            $io->warning('No hay suficientes datos por subcategoría (mínimo 3 trabajos).');

            return Command::SUCCESS;
        }

        $io->section('Factores de ajuste propuestos');
        $factorRows = [];
        foreach ($factors as $subCategoryKey => $factor) {
            $factorRows[] = [$subCategoryKey, sprintf('x %.3f', $factor), sprintf('%+.1f %%', ($factor - 1.0) * 100)];
        }
        $io->table(['Subcategoría', 'Factor', 'Variación'], $factorRows);

        if ($input->getOption('dry-run')) {
            $io->warning('Dry-run: no se modificó la BD ni la caché Gemini.');

            return Command::SUCCESS;
        }

        $updated = 0;
        $created = 0;
        $existingBySub = [];
        foreach ($this->pricingRateRepository->findAllOrdered() as $rate) {
            $existingBySub[$rate->getSubcategory()] = true;
            if (!isset($factors[$rate->getSubcategory()])) {
                continue;
            }
            $factor = $factors[$rate->getSubcategory()];
            $newMin = (int) round($rate->getPriceMin() * $factor);
            $newMax = (int) round($rate->getPriceMax() * $factor);
            if ($newMin < 0) {
                $newMin = 0;
            }
            if ($newMax <= $newMin) {
                $newMax = $newMin + 1;
            }
            $rate->setPriceMin($newMin);
            $rate->setPriceMax($newMax);
            ++$updated;
        }

        foreach ($factors as $subCategoryKey => $factor) {
            if (isset($existingBySub[$subCategoryKey])) {
                continue;
            }
            $baseCode = $stats[$subCategoryKey]['category'] ?? 'DIY';
            $count = $stats[$subCategoryKey]['count'];
            $avgAccepted = $stats[$subCategoryKey]['sum_accepted'] / max(1, $count);
            $newMin = (int) round($avgAccepted * 0.8);
            $newMax = (int) round($avgAccepted * 1.2);
            if ($newMax <= $newMin) {
                $newMax = $newMin + 1;
            }

            $riskCounts = $stats[$subCategoryKey]['risk_counts'];
            arsort($riskCounts);
            $topRisk = array_key_first($riskCounts);
            $complejidad = match ($topRisk) {
                'HIGH' => 'Alta',
                'MEDIUM' => 'Media',
                default => 'Baja',
            };

            $rate = new PricingRate();
            $rate->setCategoryCode($baseCode);
            $rate->setCategoryLabel($this->pricingCatalogService->labelForCode($baseCode));
            $rate->setSubcategory($subCategoryKey);
            $rate->setZone('Córdoba');
            $rate->setPriceMin(max(0, $newMin));
            $rate->setPriceMax($newMax);
            $rate->setUnit('Servicio');
            $rate->setComplexity($complejidad);
            $this->em->persist($rate);
            ++$created;
        }

        $this->em->flush();
        $invalidated = $this->geminiCacheService->invalidateAll();

        $io->success(sprintf(
            'Tarifas actualizadas: %d ajustadas, %d nuevas. Cachés Gemini invalidadas: %d.',
            $updated,
            $created,
            $invalidated
        ));

        return Command::SUCCESS;
    }
}
