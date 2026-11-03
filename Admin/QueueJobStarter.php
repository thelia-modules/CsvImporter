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

namespace CsvImporter\Admin;

use CsvImporter\Job\RunCatalogImportJob;
use CsvImporter\Model\CsvImporterJob;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Thelia\Messenger\Transport\ConfiguredQueues;

/**
 * Hands a job to the heavy queue of the shop for a worker to take. A shop that names no
 * queue has no worker: the job then runs in a console process of its own.
 */
#[AsAlias(JobStarter::class, public: true)]
final readonly class QueueJobStarter implements JobStarter
{
    public function __construct(
        private ConfiguredQueues $queues,
        private MessageBusInterface $bus,
        #[Autowire(service: BackgroundLauncher::class)]
        private JobStarter $launcher,
    ) {
    }

    public function start(CsvImporterJob $job): bool
    {
        if ($this->queues->heavyJobsRunInline()) {
            return $this->launcher->start($job);
        }

        try {
            $this->bus->dispatch(new RunCatalogImportJob($job->getId()));
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
