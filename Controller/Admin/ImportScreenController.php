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

namespace CsvImporter\Controller\Admin;

use CsvImporter\Admin\ImageArchiveExtractor;
use CsvImporter\Admin\JobStarter;
use CsvImporter\Admin\MappingFormReader;
use CsvImporter\Admin\ReportPresenter;
use CsvImporter\Admin\ScreenAccess;
use CsvImporter\Admin\ScreenRenderer;
use CsvImporter\Admin\UploadRefused;
use CsvImporter\Admin\UploadStore;
use CsvImporter\Import\ImportMode;
use CsvImporter\Job\ImportJobRepository;
use CsvImporter\Job\JobStatus;
use CsvImporter\Mapping\HeaderGuesser;
use CsvImporter\Mapping\ImportMapping;
use CsvImporter\Mapping\MappingValidator;
use CsvImporter\Model\CsvImporterJob;
use CsvImporter\Profile\ImportProfile;
use CsvImporter\Profile\ProfileRepository;
use CsvImporter\Reader\CsvDialectDetector;
use CsvImporter\Reader\CsvFileReader;
use CsvImporter\Reader\CsvFormula;
use CsvImporter\Reader\UnreadableFileException;
use CsvImporter\Report\ReportMessage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Tools\TokenProvider;

/**
 * Tools > Catalog import: stage a file, map its columns, simulate, run, read the report.
 * Every write is a POST carrying its token in the body.
 */
final readonly class ImportScreenController
{
    private const DOMAIN = ReportPresenter::DOMAIN;
    private const PAGE_SIZE = 50;
    private const RECENT_JOBS = 20;
    private const REFRESH_SECONDS = 3;
    private const MAX_REFRESH_ROUNDS = 200;
    private const WAITING_SECONDS = 60;
    private const STALLED_SECONDS = 300;
    private const NAME_LENGTH = 100;
    private const SIMULATION_LIFETIME = 86400;
    private const OUTCOMES = ['created', 'updated', 'unchanged', 'skipped', 'failed', 'warning', 'creation', 'image', 'info'];

    public function __construct(
        private ScreenAccess $access,
        private TokenProvider $tokens,
        private ScreenRenderer $screens,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private UploadStore $uploads,
        private ImportJobRepository $jobs,
        private ProfileRepository $profiles,
        private MappingValidator $validator,
        private MappingFormReader $mappingReader,
        private JobStarter $launcher,
        private ReportPresenter $presenter,
    ) {
    }

    #[Route('/admin/csv-importer', name: 'csvimporter.index', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->check(AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->screens->render('CsvImporter/index.html.twig', [
            'profiles' => $this->profiles->all(),
            'jobs' => $this->jobs->recent(self::RECENT_JOBS),
            'modes' => ImportMode::cases(),
            'max_megabytes' => UploadStore::MAX_BYTES / 1024 / 1024,
            'images_megabytes' => ImageArchiveExtractor::MAX_ARCHIVE_BYTES / 1024 / 1024,
            'can_create' => $this->access->can(AccessManager::CREATE),
        ]));
    }

    #[Route('/admin/csv-importer/upload', name: 'csvimporter.upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        if ($denied = $this->access->check(AccessManager::CREATE)) {
            return $denied;
        }

        if (!$this->tokenIsValid($request)) {
            return $this->back($request, 'csvimporter.index', [], 'The security token is not valid: reload the page and try again');
        }

        $mode = ImportMode::tryFrom((string) $request->request->get('mode', ''));

        if (null === $mode) {
            return $this->back($request, 'csvimporter.index', [], 'Choose what the import may do');
        }

        try {
            $stored = $this->uploads->store($this->uploadedFile($request));
        } catch (UploadRefused $refused) {
            return $this->back($request, 'csvimporter.index', [], $refused->getMessage(), $refused->parameters);
        }

        try {
            $this->uploads->storeImages($this->uploadedImages($request), $stored['path']);
        } catch (UploadRefused $refused) {
            $this->uploads->discard($stored['path']);

            return $this->back($request, 'csvimporter.index', [], $refused->getMessage(), $refused->parameters);
        }

        $profile = $this->profileForUpload($request, $stored['name'], $stored['headers']);

        if ($profile instanceof ReportMessage) {
            $this->uploads->discard($stored['path']);

            return $this->back($request, 'csvimporter.index', [], $profile->key, $profile->parameters);
        }

        try {
            $job = $this->jobs->createDraft((int) $profile->id, $stored['path'], $stored['name'], $mode, $this->access->adminId());
        } catch (\Throwable $failure) {
            $this->uploads->discard($stored['path']);

            throw $failure;
        }

        $this->access->log(AccessManager::CREATE, \sprintf('Catalog import: file "%s" staged with the profile "%s"', $stored['name'], $profile->name), $job->getId());

        return new RedirectResponse($this->urls->generate('csvimporter.job', ['id' => $job->getId()]));
    }

    #[Route('/admin/csv-importer/job/{id}', name: 'csvimporter.job', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function job(int $id, Request $request): Response
    {
        if ($denied = $this->access->check(AccessManager::VIEW)) {
            return $denied;
        }

        $job = $this->jobs->find($id);

        if (null === $job) {
            return $this->back($request, 'csvimporter.index', [], 'The job does not exist');
        }

        $status = JobStatus::tryFrom($job->getStatus());
        $profile = null === $job->getProfileId() ? null : $this->profiles->find($job->getProfileId());
        $context = [
            'job' => $job,
            'profile' => $profile,
            'mode' => ImportMode::tryFrom($job->getMode()),
            'modes' => ImportMode::cases(),
            'error' => $this->presenter->textOfJson($job->getError()),
            'can_create' => $this->access->can(AccessManager::CREATE),
            'can_update' => $this->access->can(AccessManager::UPDATE),
        ];

        $context += match ($status) {
            JobStatus::Draft => $this->draftContext($job, $profile),
            JobStatus::Queued, JobStatus::Running => $this->progressContext($job, (int) $request->query->get('round', 0)),
            default => $this->reportContext($job, $request),
        };

        return new Response($this->screens->render('CsvImporter/job.html.twig', $context), Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/admin/csv-importer/job/{id}/prepare', name: 'csvimporter.prepare', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function prepare(int $id, Request $request): Response
    {
        if ($denied = $this->access->check(AccessManager::CREATE)) {
            return $denied;
        }

        $job = $this->jobs->find($id);

        if (null === $job || JobStatus::Draft->value !== $job->getStatus()) {
            return $this->back($request, 'csvimporter.index', [], 'This file is not waiting for its mapping any more');
        }

        $target = ['id' => $id];

        if (!$this->tokenIsValid($request)) {
            return $this->back($request, 'csvimporter.job', $target, 'The security token is not valid: reload the page and try again');
        }

        if ($this->jobs->hasLiveJobOn($job->getSourcePath())) {
            return $this->back($request, 'csvimporter.job', $target, 'A job on this file is still running: wait for it to end');
        }

        $mode = ImportMode::tryFrom((string) $request->request->get('mode', ''));
        $profile = null === $job->getProfileId() ? null : $this->profiles->find($job->getProfileId());

        if (null === $mode || null === $profile) {
            return $this->back($request, 'csvimporter.job', $target, null === $profile ? 'The import profile does not exist anymore' : 'Choose what the import may do');
        }

        try {
            $headers = $this->headersOf($job, $profile);
        } catch (UnreadableFileException $unreadable) {
            return $this->back($request, 'csvimporter.job', $target, $unreadable->getMessage(), $unreadable->parameters);
        }

        ['mapping' => $mapping, 'errors' => $errors] = $this->mappingReader->read($headers, $request->request->all('columns'));
        $issues = [...$errors, ...$this->validator->validate($mapping, $mode)];

        if ([] !== $issues) {
            foreach ($issues as $issue) {
                $this->flash($request, 'error', $issue->key, $issue->parameters);
            }

            return new RedirectResponse($this->urls->generate('csvimporter.job', $target));
        }

        $this->profiles->save(new ImportProfile($profile->id, $profile->name, $profile->delimiter, $profile->encoding, $profile->decimalSeparator, $mapping));
        $this->jobs->changeMode($job, $mode);
        $this->access->log(AccessManager::CREATE, \sprintf('Catalog import: mapping of the profile "%s" saved', $profile->name), $id);

        if ('simulate' !== $request->request->get('intent')) {
            return $this->back($request, 'csvimporter.job', $target, 'The mapping is saved', [], 'success');
        }

        return $this->launch($request, $job, true);
    }

    #[Route('/admin/csv-importer/job/{id}/run', name: 'csvimporter.run', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function run(int $id, Request $request): Response
    {
        if ($denied = $this->access->check(AccessManager::UPDATE)) {
            return $denied;
        }

        $simulation = $this->jobs->find($id);

        if (null === $simulation) {
            return $this->back($request, 'csvimporter.index', [], 'The job does not exist');
        }

        $target = ['id' => $id];

        if (!$this->tokenIsValid($request)) {
            return $this->back($request, 'csvimporter.job', $target, 'The security token is not valid: reload the page and try again');
        }

        $refusal = $this->refusalToRun($simulation, '1' === $request->request->get('confirm'));

        if (null !== $refusal) {
            return $this->back($request, 'csvimporter.job', $target, $refusal);
        }

        return $this->launch($request, $simulation, false);
    }

    #[Route('/admin/csv-importer/job/{id}/abandon', name: 'csvimporter.abandon', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function abandon(int $id, Request $request): Response
    {
        if ($denied = $this->access->check(AccessManager::UPDATE)) {
            return $denied;
        }

        $job = $this->jobs->find($id);

        if (null === $job) {
            return $this->back($request, 'csvimporter.index', [], 'The job does not exist');
        }

        $target = ['id' => $id];

        if (!$this->tokenIsValid($request)) {
            return $this->back($request, 'csvimporter.job', $target, 'The security token is not valid: reload the page and try again');
        }

        if (!$this->isStuck($job)) {
            return $this->back($request, 'csvimporter.job', $target, 'Only a job that does not move any more can be abandoned');
        }

        $idleSeconds = JobStatus::Queued->value === $job->getStatus() ? self::WAITING_SECONDS : self::STALLED_SECONDS;

        if (!$this->jobs->abandon($job, $idleSeconds + 1)) {
            return $this->back($request, 'csvimporter.job', $target, 'Only a job that does not move any more can be abandoned');
        }

        $this->access->log(AccessManager::UPDATE, \sprintf('Catalog import: job abandoned for "%s"', $job->getSourceName()), $id);

        return $this->back($request, 'csvimporter.job', $target, 'The job was abandoned', [], 'success');
    }

    #[Route('/admin/csv-importer/job/{id}/report', name: 'csvimporter.report', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function report(int $id, Request $request): Response
    {
        if ($denied = $this->access->check(AccessManager::VIEW)) {
            return $denied;
        }

        $job = $this->jobs->find($id);

        if (null === $job) {
            return $this->back($request, 'csvimporter.index', [], 'The job does not exist');
        }

        $response = new StreamedResponse(function () use ($job): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['line', 'outcome', 'reference', 'message'], ';', '"', '');

            $after = 0;
            $pooling = \Propel\Runtime\Propel::isInstancePoolingEnabled();
            \Propel\Runtime\Propel::disableInstancePooling();

            try {
                do {
                    $lines = $this->jobs->linesAfter($job, $after, 500);

                    foreach ($lines as $row) {
                        $after = $row->getId();
                        $line = $this->presenter->line($row);
                        fputcsv($out, [(string) $line['line'], $line['outcome'], CsvFormula::protect($line['reference']), CsvFormula::protect($line['message'])], ';', '"', '');
                    }
                } while (500 === \count($lines));
            } finally {
                if ($pooling) {
                    \Propel\Runtime\Propel::enableInstancePooling();
                }
            }

            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="import-report-%d.csv"', $job->getId()));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function idleSeconds(CsvImporterJob $job): int
    {
        return time() - ($job->getUpdatedAt()?->getTimestamp() ?? time());
    }

    private function isStuck(CsvImporterJob $job): bool
    {
        return match ($job->getStatus()) {
            JobStatus::Queued->value => $this->idleSeconds($job) > self::WAITING_SECONDS,
            JobStatus::Running->value => $this->idleSeconds($job) > self::STALLED_SECONDS,
            default => false,
        };
    }

    private function uploadedFile(Request $request): ?UploadedFile
    {
        $file = $request->files->get('file');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function uploadedImages(Request $request): ?UploadedFile
    {
        $file = $request->files->get('images');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function launch(Request $request, CsvImporterJob $from, bool $dryRun): Response
    {
        $job = $this->jobs->create($from->getProfileId(), $from->getSourcePath(), $from->getSourceName(), ImportMode::from($from->getMode()), $dryRun, $this->access->adminId());
        $this->access->log($dryRun ? AccessManager::CREATE : AccessManager::UPDATE, \sprintf('Catalog import: %s of "%s" started', $dryRun ? 'simulation' : 'import', $from->getSourceName()), $job->getId());

        if (!$this->launcher->start($job)) {
            $this->flash($request, 'warning', 'The job could not be started in the background: it waits, start it with "bin/console csvimporter:run-job %job"', ['%job' => (string) $job->getId()]);
        }

        return new RedirectResponse($this->urls->generate('csvimporter.job', ['id' => $job->getId()]));
    }

    private function refusalToRun(CsvImporterJob $simulation, bool $confirmed): ?string
    {
        if (1 !== $simulation->getDryRun() || JobStatus::Done->value !== $simulation->getStatus()) {
            return 'Only a finished simulation can be run for real';
        }

        if (!$confirmed) {
            return 'Tick the box to confirm that the simulation report was read';
        }

        if (!$this->uploads->owns($simulation->getSourcePath())) {
            return 'The file is no longer available: stage it again';
        }

        $profile = null === $simulation->getProfileId() ? null : $this->profiles->find($simulation->getProfileId());

        if (null === $profile) {
            return 'The import profile does not exist anymore';
        }

        if ($this->profileChangedSince($simulation)) {
            return 'The mapping changed since the simulation: simulate again';
        }

        if ($this->jobs->hasLiveRealRun($simulation->getSourcePath())) {
            return 'This file is already being imported';
        }

        $created = $simulation->getCreatedAt();

        if (null === $created || $created->getTimestamp() < time() - self::SIMULATION_LIFETIME) {
            return 'The simulation is too old: simulate again';
        }

        if ($this->jobs->hasRealRunSince($simulation->getSourcePath(), $created)) {
            return 'This simulation was already imported: simulate again to import again';
        }

        return null;
    }

    private function profileChangedSince(CsvImporterJob $simulation): bool
    {
        return $this->profiles->changedSince($simulation->getProfileId(), $simulation->getCreatedAt());
    }

    /**
     * @param list<string> $headers
     */
    private function profileForUpload(Request $request, string $fileName, array $headers): ImportProfile|ReportMessage
    {
        $choice = (string) $request->request->get('profile', 'new');

        if ('new' !== $choice) {
            $existing = ctype_digit($choice) ? $this->profiles->find((int) $choice) : null;

            return $existing ?? new ReportMessage('The import profile does not exist anymore');
        }

        $name = trim((string) $request->request->get('profile_name', ''));
        $name = '' === $name ? pathinfo($fileName, \PATHINFO_FILENAME) : $name;

        if ('' === $name || mb_strlen($name) > self::NAME_LENGTH) {
            return new ReportMessage('Name the new profile, in 100 characters at most');
        }

        if (null !== $this->profiles->findByName($name)) {
            return new ReportMessage('A profile named "%name" already exists: choose it in the list or give another name', ['%name' => $name]);
        }

        return $this->profiles->save(new ImportProfile(null, $name, null, null, null, ImportMapping::fromProposal((new HeaderGuesser())->guess($headers))));
    }

    /**
     * @return array<string, mixed>
     */
    private function draftContext(CsvImporterJob $job, ?ImportProfile $profile): array
    {
        if (null === $profile) {
            return ['file_problem' => $this->presenter->text(new ReportMessage('The import profile does not exist anymore'))];
        }

        try {
            $headers = $this->headersOf($job, $profile);
        } catch (UnreadableFileException $unreadable) {
            return ['file_problem' => $this->presenter->text(new ReportMessage($unreadable->getMessage(), $unreadable->parameters))];
        }

        $columns = [];

        foreach ($headers as $index => $header) {
            $target = $profile->mapping->targetOf($header);
            $columns[] = ['index' => $index, 'header' => $header, 'field' => null === $target ? MappingFormReader::IGNORED : $target->field->value, 'name' => null === $target ? '' : (string) $target->name];
        }

        $mode = ImportMode::tryFrom($job->getMode()) ?? ImportMode::CreateOrUpdate;

        return [
            'columns' => $columns,
            'fields' => MappingFormReader::offeredFields(),
            'missing_columns' => array_values(array_diff($profile->mapping->headers(), $headers)),
            'mapping_issues' => array_map($this->presenter->text(...), $this->validator->validate($profile->mapping, $mode)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function progressContext(CsvImporterJob $job, int $round): array
    {
        $age = $this->idleSeconds($job);
        $queued = JobStatus::Queued->value === $job->getStatus();

        return [
            'can_abandon' => $this->isStuck($job) && $this->access->can(AccessManager::UPDATE),
            'refresh_seconds' => self::REFRESH_SECONDS,
            'refresh_round' => $round,
            'auto_refresh' => $round < self::MAX_REFRESH_ROUNDS,
            'max_refresh_round' => self::MAX_REFRESH_ROUNDS,
            'not_started' => $queued && $age > self::WAITING_SECONDS,
            'stalled' => !$queued && $age > self::STALLED_SECONDS,
            'console_command' => 'bin/console csvimporter:run-job '.$job->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportContext(CsvImporterJob $job, Request $request): array
    {
        $outcome = (string) $request->query->get('outcome', '');
        $outcome = \in_array($outcome, self::OUTCOMES, true) ? $outcome : null;
        $total = $this->jobs->countLines($job, $outcome);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pages, max(1, (int) $request->query->get('page', 1)));

        return [
            'outcome' => $outcome,
            'outcomes' => self::OUTCOMES,
            'outcome_counts' => $this->jobs->lineCountsByOutcome($job),
            'lines' => array_map($this->presenter->line(...), $this->jobs->lines($job, $outcome, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE)),
            'page' => $page,
            'pages' => $pages,
            'total_lines' => $total,
            'file_available' => $this->uploads->owns($job->getSourcePath()),
            'profile_changed' => 1 === $job->getDryRun() && JobStatus::Done->value === $job->getStatus() && $this->profileChangedSince($job),
        ];
    }

    /**
     * @return list<string>
     *
     * @throws UnreadableFileException
     */
    private function headersOf(CsvImporterJob $job, ImportProfile $profile): array
    {
        if (!$this->uploads->owns($job->getSourcePath())) {
            throw new UnreadableFileException('The file is no longer available: stage it again');
        }

        return (new CsvFileReader($job->getSourcePath(), (new CsvDialectDetector())->resolve($job->getSourcePath(), $profile->delimiter, $profile->encoding)))->headers();
    }

    private function tokenIsValid(Request $request): bool
    {
        try {
            return $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            return false;
        }
    }

    /**
     * @param array<string, scalar> $routeParameters
     * @param array<string, string> $messageParameters
     */
    private function back(Request $request, string $route, array $routeParameters, string $message, array $messageParameters = [], string $type = 'error'): RedirectResponse
    {
        $this->flash($request, $type, $message, $messageParameters);

        return new RedirectResponse($this->urls->generate($route, $routeParameters));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function flash(Request $request, string $type, string $message, array $parameters = []): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $this->translator->trans($message, $parameters, self::DOMAIN));
        }
    }
}
