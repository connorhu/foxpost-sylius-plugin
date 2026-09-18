<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Command;

use CodeConjure\SyliusFoxPostPlugin\FoxPostTrackingSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'foxpost:tracking:sync', description: 'Sync FoxPost tracking statuses')]
final class FoxPostTrackingSyncCommand extends Command
{
    public function __construct(private readonly FoxPostTrackingSyncService $syncService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->syncService->syncActive();
        $output->writeln('FoxPost tracking sync complete.');

        return Command::SUCCESS;
    }
}
