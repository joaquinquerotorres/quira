<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GeminiCacheService;
use App\Service\PricingCatalogService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:pricing:seed-from-csv',
    description: 'Siembra/actualiza pricing_rate desde config/gemini_pricing.csv',
)]
final class PricingSeedFromCsvCommand extends Command
{
    public function __construct(
        private readonly PricingCatalogService $pricingCatalogService,
        private readonly GeminiCacheService $geminiCacheService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'replace',
            null,
            InputOption::VALUE_NONE,
            'Borra tarifas existentes antes de importar'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $replace = (bool) $input->getOption('replace');
        $written = $this->pricingCatalogService->seedFromCsv($replace);
        if ($written > 0) {
            $this->geminiCacheService->invalidateAll();
        }
        $io->success(sprintf('Importadas/actualizadas %d filas de precios.%s', $written, $written > 0 ? ' Caché Gemini invalidada.' : ' (tabla ya tenía datos; usa --replace para forzar)'));

        return Command::SUCCESS;
    }
}
