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

namespace CsvImporter\Import;

use CsvImporter\Image\ImagePass;
use CsvImporter\Job\ImportJobRepository;
use CsvImporter\Job\JobStatus;
use CsvImporter\Lookup\CatalogLookupFactory;
use CsvImporter\Mapping\CatalogField;
use CsvImporter\Mapping\MappingValidator;
use CsvImporter\Model\CsvImporterJob;
use CsvImporter\Profile\ProfileRepository;
use CsvImporter\Reader\CsvDialect;
use CsvImporter\Reader\CsvDialectDetector;
use CsvImporter\Reader\CsvFileReader;
use CsvImporter\Reader\UnreadableFileException;
use CsvImporter\Report\ReportMessage;
use Propel\Runtime\Propel;
use Symfony\Component\Lock\LockFactory;
use Thelia\Log\Tlog;
use Thelia\Model\Currency;
use Thelia\Model\Lang;

final readonly class CatalogImportRunner
{
    private const PROGRESS_STEP = 25;

    public function __construct(
        private ProfileRepository $profiles,
        private ImportJobRepository $jobs,
        private MappingValidator $validator,
        private RowProcessor $processor,
        private CatalogLookupFactory $lookups,
        private LockFactory $locks,
        private ImagePass $images,
    ) {
    }

    public const LOCK_KEY = 'csv-importer-catalog';

    public const UNEXPECTED_ERROR = 'The import stopped on an unexpected error';

    private const LOCK_TTL_SECONDS = 21600;

    public function run(CsvImporterJob $job, ?\Closure $onRow = null, ?string $imagesDirectory = null, bool $imagesOnly = false): void
    {
        try {
            $this->execute($job, $onRow, $imagesDirectory, $imagesOnly);
        } catch (\Throwable $failure) {
            $job->reload();

            if (JobStatus::Running->value === $job->getStatus()) {
                $this->jobs->markFailed($job, new ReportMessage(self::UNEXPECTED_ERROR));
            }

            throw $failure;
        }
    }

    private function execute(CsvImporterJob $job, ?\Closure $onRow, ?string $imagesDirectory, bool $imagesOnly): void
    {
        if (!$this->jobs->claim($job)) {
            return;
        }

        $mode = ImportMode::tryFrom($job->getMode());

        if (null === $mode) {
            $this->jobs->markFailed($job, new ReportMessage('The import mode is unknown'));

            return;
        }

        $profile = null === $job->getProfileId() ? null : $this->profiles->find($job->getProfileId());

        if (null === $profile) {
            $this->jobs->markFailed($job, new ReportMessage('The import profile does not exist anymore'));

            return;
        }

        $issues = $this->validator->validate($profile->mapping, $mode);

        if ([] !== $issues) {
            $this->refuse($job, $issues, 'The columns of the file cannot be imported as mapped');

            return;
        }

        try {
            $reader = new CsvFileReader($job->getSourcePath(), $this->dialectOf($job->getSourcePath(), $profile->delimiter, $profile->encoding));
        } catch (UnreadableFileException $unreadable) {
            $this->jobs->markFailed($job, new ReportMessage($unreadable->getMessage(), $unreadable->parameters));

            return;
        }

        $missing = array_values(array_diff($profile->mapping->headers(), $reader->headers()));

        if ([] !== $missing) {
            $this->refuse($job, array_map(static fn (string $column): ReportMessage => new ReportMessage('The column "%column" of the profile is not in the file', ['%column' => $column]), $missing), 'The file does not match the profile');

            return;
        }

        $dryRun = 1 === $job->getDryRun();
        $lock = $dryRun ? null : $this->locks->createLock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        if (null !== $lock && !$lock->acquire()) {
            $this->jobs->markFailed($job, new ReportMessage('Another catalog import is running: start this one again when it is over'));

            return;
        }

        $poolingWasEnabled = Propel::isInstancePoolingEnabled();
        Propel::disableInstancePooling();

        try {
            $options = new ImportOptions($mode, $dryRun, Lang::getDefaultLanguage()->getLocale(), Currency::getDefaultCurrency()->getId(), $profile->decimalSeparator);
            $this->jobs->markRunning($job, $this->count($reader));

            if (!$imagesOnly && !$this->process($job, $reader, $profile->mapping, $options, $onRow)) {
                return;
            }

            if (!$this->images->run($job, $reader, $profile->mapping, $dryRun, $options->locale, $imagesDirectory, !$imagesOnly)) {
                return;
            }

            $this->jobs->markDone($job);
        } catch (UnreadableFileException $unreadable) {
            $this->jobs->markFailed($job, new ReportMessage($unreadable->getMessage(), $unreadable->parameters));
        } catch (\Throwable $failure) {
            Tlog::getInstance()->error(\sprintf('Catalog import %d stopped: %s: %s', $job->getId(), $failure::class, $failure->getMessage()));
            $this->jobs->markFailed($job, new ReportMessage(self::UNEXPECTED_ERROR));

            throw $failure;
        } finally {
            $poolingWasEnabled && Propel::enableInstancePooling();
            $lock?->release();
        }
    }

    private function refuse(CsvImporterJob $job, array $issues, string $summary): void
    {
        foreach ($issues as $issue) {
            $this->jobs->addLine($job, 0, RowOutcome::Failed->value, null, $issue);
        }

        $this->jobs->markFailed($job, new ReportMessage($summary));
    }

    private function process(CsvImporterJob $job, CsvFileReader $reader, $mapping, ImportOptions $options, ?\Closure $onRow): bool
    {
        $lookup = $this->lookups->create($options);
        $referenceHeader = $mapping->headerFor(CatalogField::Reference);
        $counts = [];
        $seen = [];
        $processed = 0;

        foreach ($reader->rows() as $row) {
            $reference = null === $referenceHeader ? '' : ($row->cells[$referenceHeader] ?? '');
            $key = mb_strtolower($reference);

            if (null === $row->problem && '' !== $reference && isset($seen[$key])) {
                $result = new RowResult($row->lineNumber, RowOutcome::Skipped, $reference, new ReportMessage('The reference was already processed at line %line', ['%line' => $seen[$key]]));
            } else {
                $result = $this->processor->process($row, $mapping, $options, $lookup);

                if ('' !== $reference && RowOutcome::Failed !== $result->outcome) {
                    $seen[$key] = $row->lineNumber;
                }
            }

            ++$processed;
            $counts[$result->outcome->value] = ($counts[$result->outcome->value] ?? 0) + 1;
            $this->report($job, $result);
            null === $onRow || $onRow($result);

            if (0 === $processed % self::PROGRESS_STEP) {
                $this->jobs->saveProgress($job, $processed, $counts);

                if (!$this->jobs->touch($job)) {
                    return false;
                }
            }
        }

        $this->jobs->saveProgress($job, $processed, $counts);

        return true;
    }

    private function report(CsvImporterJob $job, RowResult $result): void
    {
        $this->jobs->addLine($job, $result->lineNumber, $result->outcome->value, $result->reference, $result->message);

        foreach ($result->warnings as $warning) {
            $this->jobs->addLine($job, $result->lineNumber, 'warning', $result->reference, $warning);
        }

        foreach ($result->creations as $creation) {
            $this->jobs->addLine($job, $result->lineNumber, 'creation', $result->reference, new ReportMessage('The %kind "%name" is created', ['%kind' => $creation->kind, '%name' => $creation->name]));
        }
    }

    private function count(CsvFileReader $reader): int
    {
        $count = 0;

        foreach ($reader->rows() as $unused) {
            ++$count;
        }

        return $count;
    }

    private function dialectOf(string $path, ?string $delimiter, ?string $encoding): CsvDialect
    {
        return (new CsvDialectDetector())->resolve($path, $delimiter, $encoding);
    }
}
