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

use CsvImporter\Import\CatalogImportRunner;
use CsvImporter\Import\ImportMode;
use CsvImporter\Import\RowOutcome;
use CsvImporter\Job\ImportJobRepository;
use CsvImporter\Job\JobStatus;
use CsvImporter\Mapping\HeaderGuesser;
use CsvImporter\Mapping\ImportMapping;
use CsvImporter\Model\CsvImporterJob;
use CsvImporter\Model\CsvImporterJobRowQuery;
use CsvImporter\Profile\ImportProfile;
use CsvImporter\Profile\ProfileRepository;
use CsvImporter\Reader\CsvFileReader;
use CsvImporter\Reader\UnreadableFileException;
use CsvImporter\Report\ReportMessage;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thelia\Command\ContainerAwareCommand;

#[AsCommand(
    name: 'csvimporter:import-catalog',
    description: 'Imports or simulates the import of a catalog CSV file.',
)]
final class ImportCatalogCommand extends ContainerAwareCommand
{
    private const REPORT_LINES_SHOWN = 50;

    public const EXIT_ROWS_FAILED = 3;

    public function __construct(
        private readonly CatalogImportRunner $runner,
        private readonly ProfileRepository $profiles,
        private readonly ImportJobRepository $jobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path of the CSV file.')
            ->addOption('mapping', null, InputOption::VALUE_REQUIRED, 'Id or name of the mapping profile to use.')
            ->addOption('save-mapping', null, InputOption::VALUE_REQUIRED, 'Guess the mapping from the header of the file and keep it under this new profile name.')
            ->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'create_or_update, update_only or create_only.', ImportMode::CreateOrUpdate->value)
            ->addOption('images-dir', null, InputOption::VALUE_REQUIRED, 'Directory holding the image files named in the image column.')
            ->addOption('images-only', null, InputOption::VALUE_NONE, 'Load only the images of a file whose products already exist, without touching the rest.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate: report what would happen without writing to the catalog.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initRequest();

        $style = new SymfonyStyle($input, $output);
        $mode = ImportMode::tryFrom((string) $input->getOption('mode'));

        if (null === $mode) {
            $style->error(\sprintf('The mode must be one of: %s.', implode(', ', array_map(static fn (ImportMode $case): string => $case->value, ImportMode::cases()))));

            return self::INVALID;
        }

        $path = realpath((string) $input->getArgument('file'));

        if (false === $path || !is_file($path) || !is_readable($path)) {
            $style->error('The file does not exist or cannot be read.');

            return self::INVALID;
        }

        $profile = $this->profileOf($input, $path, $style);

        if (null === $profile) {
            return self::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $job = $this->jobs->create($profile->id, $path, basename($path), $mode, $dryRun, null);

        $style->writeln(\sprintf('%s of "%s" with the profile "%s" (%s)', $dryRun ? 'Simulation' : 'Import', OutputFormatter::escape(basename($path)), OutputFormatter::escape($profile->name), $mode->value));

        $this->runner->run($job, null, null === $input->getOption('images-dir') ? null : (string) $input->getOption('images-dir'), (bool) $input->getOption('images-only'));
        $job->reload();

        return $this->conclude($job, $style);
    }

    private function profileOf(InputInterface $input, string $path, SymfonyStyle $style): ?ImportProfile
    {
        $reference = $input->getOption('mapping');
        $newName = $input->getOption('save-mapping');

        if (null !== $reference && null !== $newName) {
            $style->error('Use --mapping to reuse a profile or --save-mapping to create one, not both.');

            return null;
        }

        if (null !== $reference) {
            $reference = (string) $reference;
            $profile = ctype_digit($reference) ? $this->profiles->find((int) $reference) : $this->profiles->findByName($reference);

            if (null === $profile) {
                $style->error(\sprintf('No profile matches "%s".', $reference));
            }

            return $profile;
        }

        if (null === $newName || '' === trim((string) $newName)) {
            $style->error('Give --mapping to reuse a mapping or --save-mapping=<name> to guess one from the file.');

            return null;
        }

        if (null !== $this->profiles->findByName((string) $newName)) {
            $style->error(\sprintf('A profile named "%s" already exists: use --mapping to reuse it.', $newName));

            return null;
        }

        try {
            $headers = (new CsvFileReader($path))->headers();
        } catch (UnreadableFileException $unreadable) {
            $style->error($this->render(new ReportMessage($unreadable->getMessage(), $unreadable->parameters)));

            return null;
        }

        $mapping = ImportMapping::fromProposal((new HeaderGuesser())->guess($headers));
        $profile = $this->profiles->save(new ImportProfile(null, (string) $newName, null, null, null, $mapping));

        $style->section('Guessed mapping, kept as a profile');
        $style->table(['Column', 'Field'], array_map(
            static fn (string $header): array => [OutputFormatter::escape($header), $mapping->targetOf($header)->field->value.(null === $mapping->targetOf($header)->name ? '' : OutputFormatter::escape(' ('.$mapping->targetOf($header)->name.')'))],
            $mapping->headers(),
        ));

        $ignored = array_values(array_diff($headers, $mapping->headers()));

        if ([] !== $ignored) {
            $style->note('Columns not recognised, ignored: '.OutputFormatter::escape(implode(', ', $ignored)));
        }

        return $profile;
    }

    private function conclude(CsvImporterJob $job, SymfonyStyle $style): int
    {
        $style->definitionList(
            ['Rows read' => (string) $job->getProcessedRows().' / '.(string) $job->getTotalRows()],
            ['Created' => (string) $job->getCreatedRows()],
            ['Updated' => (string) $job->getUpdatedRows()],
            ['Unchanged' => (string) $job->getUnchangedRows()],
            ['Skipped' => (string) $job->getSkippedRows()],
            ['Failed' => (string) $job->getFailedRows()],
        );

        $this->printReport($job, $style);

        if (JobStatus::Failed->value === $job->getStatus()) {
            $style->error(null === $job->getError() ? 'The import did not run.' : $this->render(ReportMessage::fromJson($job->getError())));

            return self::FAILURE;
        }

        if (JobStatus::Done->value !== $job->getStatus()) {
            $style->warning('The import did not start: it is already taken by another process.');

            return self::FAILURE;
        }

        if ($job->getFailedRows() > 0) {
            $style->warning('The import is over, some rows were refused.');

            return self::EXIT_ROWS_FAILED;
        }

        $style->success($job->getDryRun() ? 'Simulation over, nothing was written.' : 'Import over.');

        return self::SUCCESS;
    }

    private function printReport(CsvImporterJob $job, SymfonyStyle $style): void
    {
        $query = CsvImporterJobRowQuery::create()
            ->filterByJobId($job->getId())
            ->filterByOutcome([RowOutcome::Created->value, RowOutcome::Updated->value, RowOutcome::Unchanged->value], Criteria::NOT_IN)
            ->orderByLineNumber()
            ->orderById();
        $total = $query->count();

        if (0 === $total) {
            return;
        }

        $lines = $query->limit(self::REPORT_LINES_SHOWN)->find();
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [
                0 === $line->getLineNumber() ? '-' : (string) $line->getLineNumber(),
                $line->getOutcome(),
                OutputFormatter::escape((string) $line->getReference()),
                null === $line->getMessage() ? '' : $this->render(ReportMessage::fromJson($line->getMessage())),
            ];
        }

        $style->table(['Line', 'Outcome', 'Reference', 'Message'], $rows);

        if ($total > self::REPORT_LINES_SHOWN) {
            $style->writeln(\sprintf('%d more lines in the report of job %d.', $total - self::REPORT_LINES_SHOWN, $job->getId()));
        }
    }

    private function render(ReportMessage $message): string
    {
        return OutputFormatter::escape(strtr($message->key, $message->parameters));
    }
}
