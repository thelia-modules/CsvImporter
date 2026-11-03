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

namespace CsvImporter\Image;

use CsvImporter\Import\RowOutcome;
use CsvImporter\Job\ImportJobRepository;
use CsvImporter\Mapping\CatalogField;
use CsvImporter\Mapping\ImportMapping;
use CsvImporter\Model\CsvImporterJob;
use CsvImporter\Model\CsvImporterJobRowQuery;
use CsvImporter\Reader\CsvFileReader;
use CsvImporter\Report\ReportMessage;
use Thelia\Log\Tlog;
use Thelia\Model\ProductQuery;

final readonly class ImagePass
{
    private const TOUCH_STEP = 25;

    public function __construct(
        private ImageSourceParser $parser,
        private ImageFetcher $fetcher,
        private ProductImageLoader $loader,
        private ImportJobRepository $jobs,
    ) {
    }

    public function run(CsvImporterJob $job, CsvFileReader $reader, ImportMapping $mapping, bool $dryRun, string $locale, ?string $imagesDirectory, bool $skipRefusedRows = true): bool
    {
        $header = $mapping->headerFor(CatalogField::Image);
        $referenceHeader = $mapping->headerFor(CatalogField::Reference);

        if (null === $header || null === $referenceHeader) {
            return true;
        }

        $left = $skipRefusedRows ? $this->refusedLines($job) : [];
        $loaded = $already = $refused = $remote = $scanned = 0;

        foreach ($reader->rows() as $row) {
            if (0 === ++$scanned % self::TOUCH_STEP && !$this->jobs->touch($job)) {
                return false;
            }

            $cell = trim($row->cells[$header] ?? '');
            $reference = trim($row->cells[$referenceHeader] ?? '');

            if (null !== $row->problem || '' === $cell || '' === $reference || isset($left[$row->lineNumber])) {
                continue;
            }

            try {
                $sources = $this->parser->parse($cell);
            } catch (ImageRefusal $refusal) {
                ++$refused;
                $this->jobs->addLine($job, $row->lineNumber, 'image', $reference, $refusal->report);

                continue;
            }

            $product = ProductQuery::create()->findOneByRef($reference);

            if (null === $product && !$dryRun) {
                continue;
            }

            foreach ($sources as $source) {
                if (!$this->jobs->touch($job)) {
                    return false;
                }

                try {
                    if ($dryRun) {
                        $source->remote ? ++$remote : $this->fetcher->check($source, $imagesDirectory);

                        continue;
                    }

                    $this->loader->load($product->getId(), $this->fetcher->fetch($source, $imagesDirectory), $locale) ? ++$loaded : ++$already;
                } catch (ImageRefusal $refusal) {
                    ++$refused;
                    $this->jobs->addLine($job, $row->lineNumber, 'image', $reference, $refusal->report);
                } catch (\Throwable $failure) {
                    ++$refused;
                    Tlog::getInstance()->error(\sprintf('Catalog import, image of line %d: %s', $row->lineNumber, $failure::class));
                    $this->jobs->addLine($job, $row->lineNumber, 'image', $reference, new ReportMessage('The image "%source" could not be added to the product', ['%source' => ImageSource::redact($source->location)]));
                }
            }
        }

        $summary = $dryRun
            ? new ReportMessage('Images: %remote addresses would be downloaded, %refused refused', ['%remote' => (string) $remote, '%refused' => (string) $refused])
            : new ReportMessage('Images: %loaded added, %already already there, %refused refused', ['%loaded' => (string) $loaded, '%already' => (string) $already, '%refused' => (string) $refused]);
        $this->jobs->addLine($job, 0, 'info', null, $summary);

        return true;
    }

    private function refusedLines(CsvImporterJob $job): array
    {
        $lines = CsvImporterJobRowQuery::create()
            ->filterByJobId($job->getId())
            ->filterByOutcome([RowOutcome::Failed->value, RowOutcome::Skipped->value])
            ->select('LineNumber')
            ->find()
            ->getData();

        return array_fill_keys($lines, true);
    }
}
