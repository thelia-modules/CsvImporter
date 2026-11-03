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

use CsvImporter\Model\CsvImporterJob;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs a job in a console process of its own, so that a long import neither holds a web
 * request nor dies with it. It is what starts a job on a shop without a queue
 * ({@see QueueJobStarter}); the console command remains the fallback.
 */
final readonly class BackgroundLauncher implements JobStarter
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDirectory,
        #[Autowire('%kernel.environment%')] private string $environment,
    ) {
    }

    public function start(CsvImporterJob $job): bool
    {
        $php = (new PhpExecutableFinder())->find(false);

        if (false === $php) {
            return false;
        }

        $process = Process::fromShellCommandline(
            'nohup "$CSV_IMPORTER_PHP" "$CSV_IMPORTER_CONSOLE" csvimporter:run-job "$CSV_IMPORTER_JOB" --env="$CSV_IMPORTER_ENV" --no-interaction >/dev/null 2>&1 &',
            $this->projectDirectory,
            [
                'CSV_IMPORTER_PHP' => $php,
                'CSV_IMPORTER_CONSOLE' => $this->projectDirectory.'/bin/console',
                'CSV_IMPORTER_JOB' => (string) $job->getId(),
                'CSV_IMPORTER_ENV' => $this->environment,
            ],
        );

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }
}
