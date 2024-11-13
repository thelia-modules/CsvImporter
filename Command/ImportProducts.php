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
        $baseDir = $input->getArgument(self::DIR_PATH);

        $finder = Finder::create()
            ->files()
            ->in($baseDir)
            ->depth(0)
            ->name('*.csv')
        ;

        $output->writeln("<info>Fetching directory $baseDir</info>");

        $return = Command::SUCCESS;

        $count = $errors = 0;

        foreach ($finder->getIterator() as $file) {
            $output->writeln("<info>Starting to import  : ".$file->getBasename()."</info>");

            $count++;

            try {
                $this->csvProductImporterService->importProductsFromCsv($file->getPathname());

                $output->writeln('<info>Import is a success !</info>');
            } catch (\Exception $e) {
                Tlog::getInstance()->addError("Erreur lors de l'importation : ".$e->getMessage());
                $output->writeln('<error>Error : '.$e->getMessage().'</error>');
                if ($e->getPrevious()) {
                    $output->writeln('<error>Caused by : '.$e->getPrevious()->getMessage().'</error>');
                }

                $return = Command::FAILURE;

                $errors++;
            }
        }

        $output->writeln("<info>$count file(s) processed, $errors error(s).</info>");

        return $return;
    }
}
