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

namespace CsvImporter\Job;

use CsvImporter\Admin\UploadStore;
use CsvImporter\Import\CatalogImportRunner;
use CsvImporter\Model\CsvImporterJob;
use CsvImporter\Profile\ProfileRepository;
use CsvImporter\Report\ReportMessage;

/**
 * Starts a queued job the way every launcher does, a console process or a queue worker alike.
 */
final readonly class QueuedJobExecutor
{
    public function __construct(
        private CatalogImportRunner $runner,
        private ImportJobRepository $jobs,
        private ProfileRepository $profiles,
        private UploadStore $uploads,
    ) {
    }

    public function execute(CsvImporterJob $job): ?JobRefusal
    {
        if (JobStatus::Queued->value !== $job->getStatus()) {
            return JobRefusal::NotQueued;
        }

        if (0 === $job->getDryRun() && $this->mappingChangedSinceRequested($job)) {
            $this->jobs->markFailed($job, new ReportMessage('The mapping changed after the import was requested: simulate again'));

            return JobRefusal::MappingChanged;
        }

        $this->runner->run($job, null, $this->uploads->imagesDirectoryOf($job->getSourcePath()));

        return null;
    }

    /**
     * The import runs the profile as it is when it starts, not as it was when the
     * administrator asked: a change in between is a mapping nobody simulated.
     */
    private function mappingChangedSinceRequested(CsvImporterJob $job): bool
    {
        return $this->profiles->changedSince($job->getProfileId(), $job->getCreatedAt());
    }
}
