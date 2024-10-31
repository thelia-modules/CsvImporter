<?php

namespace CsvImporter\Command;

use CsvImporter\Service\CsvProductImporterService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thelia\Command\ContainerAwareCommand;
use Thelia\Log\Tlog;

class ImportProducts extends ContainerAwareCommand
{
    public const FILE_PATH = 'filePath';
    public function __construct(private CsvProductImporterService $csvProductImporterService)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->setName('csvimporter:import-products')
            ->addArgument(self::FILE_PATH, InputArgument::REQUIRED, 'Le chemin vers le fichier CSV à importer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initRequest();
        $filePath = $input->getArgument(self::FILE_PATH);

        $output->writeln("<info>Début de l'importation des produits depuis le fichier CSV : $filePath</info>");

        try {
            $this->csvProductImporterService->importProductsFromCsv($filePath);
            $output->writeln('<info>Importation terminée avec succès !</info>');
        } catch (\Exception $e) {
            Tlog::getInstance()->addError("Erreur lors de l'importation : ".$e->getMessage());
            $output->writeln('<error>Erreur : '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

}
