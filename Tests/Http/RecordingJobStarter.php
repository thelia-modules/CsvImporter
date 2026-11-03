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

namespace CsvImporter\Tests\Http;

use CsvImporter\Admin\JobStarter;
use CsvImporter\Model\CsvImporterJob;

final class RecordingJobStarter implements JobStarter
{
    /** @var list<int> */
    public array $started = [];

    public function start(CsvImporterJob $job): bool
    {
        $this->started[] = (int) $job->getId();

        return true;
    }
}
