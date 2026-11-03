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

use CsvImporter\Import\CatalogImportRunner;
use CsvImporter\Import\ImportMode;
use CsvImporter\Model\CsvImporterJob;
use CsvImporter\Report\ReportMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Thelia\Log\Tlog;
use Thelia\Tools\URL;

/**
 * A queue may hand a job out twice: the second delivery finds a job that is no longer
 * queued, and the runner claims a job only once, so it runs nothing.
 */
#[AsMessageHandler]
final readonly class RunCatalogImportJobHandler
{
    public function __construct(
        private ImportJobRepository $jobs,
        private QueuedJobExecutor $executor,
        #[Autowire(service: 'thelia.url.manager')] private URL $urlManagerThatTheRewritingReadsThroughItsInstance,
    ) {
    }

    public function __invoke(RunCatalogImportJob $message): void
    {
        $job = $this->jobs->find($message->jobId);

        if (null === $job) {
            return;
        }

        if (JobStatus::Failed->value === $job->getStatus() && $this->stoppedOnAnUnexpectedError($job)) {
            ImportMode::CreateOnly->value === $job->getMode()
                ? $this->jobs->refuseReplay($job, new ReportMessage('The import is not replayed: its first run may already have created products'))
                : $this->jobs->requeue($job);
        }

        $refusal = $this->executor->execute($job);

        if (JobRefusal::NotQueued === $refusal && JobStatus::Failed->value === $job->getStatus()) {
            Tlog::getInstance()->warning(\sprintf('Catalog import %d is not replayed: it failed for a reason a new delivery does not change', $job->getId()));
        }
    }

    private function stoppedOnAnUnexpectedError(CsvImporterJob $job): bool
    {
        return (new ReportMessage(CatalogImportRunner::UNEXPECTED_ERROR))->toJson() === $job->getError();
    }
}
