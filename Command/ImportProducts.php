<?php

namespace CsvImporter\Command;

use CsvImporter\Service\CsvProductImporterService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Thelia\Command\ContainerAwareCommand;
use Thelia\Log\Tlog;

class ImportProducts extends ContainerAwareCommand
{
    public const DIR_PATH = 'dirPath';
    public function __construct(private readonly CsvProductImporterService $csvProductImporterService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('csvimporter:import-catalog')
            ->addArgument(self::DIR_PATH, InputArgument::REQUIRED, 'Path to a directory which contains CSV file(s) and Images directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initRequest();

        return $this->csvProductImporterService->importFromDirectory($input->getArgument(self::DIR_PATH)) ?
            Command::SUCCESS :
            Command::FAILURE;
    }
}
