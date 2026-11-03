<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CsvImporter\Command;

use CsvImporter\Export\CatalogExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thelia\Command\ContainerAwareCommand;
use Thelia\Model\Currency;
use Thelia\Model\Lang;

#[AsCommand(
    name: 'csvimporter:export-catalog',
    description: 'Exports the catalog to a CSV file that csvimporter:import-catalog reads back.',
)]
final class ExportCatalogCommand extends ContainerAwareCommand
{
    public function __construct(private readonly CatalogExporter $exporter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path of the CSV file to write.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initRequest();

        $style = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('file');

        if (is_dir($path) || !is_dir(\dirname($path)) || !is_writable(\dirname($path)) || (file_exists($path) && !is_writable($path))) {
            $style->error('The file cannot be written there.');

            return self::INVALID;
        }

        $result = $this->exporter->export($path, Lang::getDefaultLanguage()->getLocale(), Currency::getDefaultCurrency()->getId());

        $style->success(\sprintf('%d products exported.', $result['exported']));

        if ($result['withCombinations'] > 0) {
            $style->warning(\sprintf('%d products with combinations were left out: this version imports simple products only.', $result['withCombinations']));
        }

        return self::SUCCESS;
    }
}
