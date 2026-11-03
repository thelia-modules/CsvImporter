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

use CsvImporter\Job\ImportJobRepository;
use CsvImporter\Job\JobRefusal;
use CsvImporter\Job\JobStatus;
use CsvImporter\Job\QueuedJobExecutor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thelia\Command\ContainerAwareCommand;

#[AsCommand(
    name: 'csvimporter:run-job',
    description: 'Runs a queued catalog import job, as the screens start it.',
)]
final class RunJobCommand extends ContainerAwareCommand
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly QueuedJobExecutor $executor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, 'Id of the job.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initRequest();

        $id = (string) $input->getArgument('job');
        $job = ctype_digit($id) ? $this->jobs->find((int) $id) : null;

        if (null === $job) {
            $output->writeln('No such job.');

            return self::INVALID;
        }

        $refusal = $this->executor->execute($job);

        if (JobRefusal::NotQueued === $refusal) {
            $output->writeln(\sprintf('The job is %s, only a queued job can be started.', $job->getStatus()));

            return self::FAILURE;
        }

        if (JobRefusal::MappingChanged === $refusal) {
            $output->writeln('The mapping changed after the import was requested.');

            return self::FAILURE;
        }

        $job->reload();

        return JobStatus::Done->value === $job->getStatus() ? self::SUCCESS : self::FAILURE;
    }
}
