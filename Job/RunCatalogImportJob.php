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

use Thelia\Messenger\Message\DescribedJob;
use Thelia\Messenger\Message\ReplayableJob;

/**
 * Runs the catalog import job of one csv_importer_job row.
 *
 * Only the id travels: what to import and how is read from the row when the job runs.
 */
final readonly class RunCatalogImportJob implements DescribedJob, ReplayableJob
{
    public function __construct(public int $jobId)
    {
    }

    public function describe(): string
    {
        return \sprintf('Catalog import job %d', $this->jobId);
    }

    public function forReplay(): static
    {
        return new static($this->jobId);
    }
}
