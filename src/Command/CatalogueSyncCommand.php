<?php

namespace App\Command;

use App\Catalogue\CatalogueSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Copies the main store's products (Shopify products.json). Also a daily Coolify task. */
#[AsCommand(name: 'app:catalogue:sync', description: 'Copy products, prices, pack sizes and energy values from the Shopify store')]
final class CatalogueSyncCommand extends Command
{
    public function __construct(private readonly CatalogueSync $sync)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $r = $this->sync->sync();
        $io->success(sprintf('%d products from %s (%d new, %d no longer in the store); %d with an energy value.', $r['found'], $this->sync->storeUrl(), $r['created'], $r['gone'], $r['withKcal']));
        return Command::SUCCESS;
    }
}
