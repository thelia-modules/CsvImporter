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

use CsvImporter\Admin\ImageArchiveExtractor;
use CsvImporter\Admin\JobStarter;
use CsvImporter\Admin\UploadStore;
use CsvImporter\CsvImporter;
use CsvImporter\Import\CatalogImportRunner;
use CsvImporter\Model\CsvImporterJob;
use CsvImporter\Model\CsvImporterJobQuery;
use CsvImporter\Model\CsvImporterJobRowQuery;
use CsvImporter\Model\CsvImporterProfileQuery;
use CsvImporter\Model\Map\CsvImporterJobTableMap;
use CsvImporter\Profile\ProfileRepository;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Admin;
use Thelia\Model\AdminLogQuery;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProfileModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

final class ImportScreensTest extends WebIntegrationTestCase
{
    private const HEADER = "Référence;Titre;Prix HT;Catégorie\n";

    private AdminSessionInjector $injector;
    private FixtureFactory $factory;
    private RecordingJobStarter $starter;
    private ?string $token = null;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AdminSessionInjector();
        $this->getService('event_dispatcher')->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->starter = new RecordingJobStarter();
        static::getContainer()->set(JobStarter::class, $this->starter);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();

        foreach (CsvImporterJobQuery::create()->find() as $job) {
            $path = $job->getSourcePath();

            if (str_contains($path, '/var/csv-importer/uploads/')) {
                @unlink($path);
                (new ImageArchiveExtractor())->remove(substr($path, 0, -4).'.images');
            }
        }

        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testTheToolsPageLinksToTheScreenAndTheScreenOffersTheUpload(): void
    {
        $this->loginAs($this->factory->admin());

        $tools = $this->get('/admin/tools');
        self::assertCount(1, $tools->filter('[data-testid="csv-importer-tools-entry"] a[href$="/admin/csv-importer"]'));

        $page = $this->get('/admin/csv-importer');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('form[data-testid="csv-importer-upload-form"][enctype="multipart/form-data"]'));
        self::assertSame('Catalog import', trim($page->filter('h1')->text()));
    }

    public function testTheUploadFormDoesNotSendTheImagesFieldWhenNoArchiveIsChosen(): void
    {
        $this->loginAs($this->factory->admin());

        $form = $this->get('/admin/csv-importer')->filter('form[data-testid="csv-importer-upload-form"]');

        self::assertCount(1, $form->filter('input[type="file"][name="images"]'));
        self::assertStringContainsString('elements.images', (string) $form->attr('onsubmit'));
        self::assertStringContainsString('images.disabled = true', (string) $form->attr('onsubmit'));
    }

    public function testTheToolsMenuOffersTheScreenOnlyToAnAdminWhoMayView(): void
    {
        $this->loginAs($this->factory->admin());
        $page = $this->get('/admin/tools');
        self::assertCount(1, $page->filter('a#csv-importer-menu-entry[href$="/admin/csv-importer"]'));

        $this->loginAs($this->factory->restrictedAdmin(['admin.tools' => [AccessManager::VIEW]]));
        $page = $this->get('/admin/tools');
        self::assertCount(1, $page->filter('[data-testid="bo-nav-tools"]'));
        self::assertCount(0, $page->filter('#csv-importer-menu-entry'));
    }

    public function testWithoutTheModuleAccessTheScreenAndEveryActionAreForbidden(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([]));

        $this->get('/admin/csv-importer');
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->factory->restrictedAdmin(['admin.tools' => [AccessManager::VIEW]]));
        $page = $this->get('/admin/tools');
        self::assertCount(1, $page->filter('[data-testid="bo-nav-tools"]'));
        self::assertCount(0, $page->filter('[data-testid="csv-importer-tools-entry"]'));

        $this->post('/admin/csv-importer/upload', ['mode' => 'create_or_update', '_token' => 'x'], $this->upload("Référence;Titre\nF-1;x\n"));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, CsvImporterJobQuery::create()->count());
    }

    public function testViewingDoesNotAllowStagingOrRunningAndTheScreenDoesNotOfferIt(): void
    {
        $this->loginAs($this->factory->admin());
        $simulation = $this->finishedSimulation();
        $this->token = null;
        $this->loginAs($this->moduleAdmin([AccessManager::VIEW]));
        $started = $this->starter->started;

        $index = $this->get('/admin/csv-importer');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $index->filter('form[data-testid="csv-importer-upload-form"]'));

        $job = $this->get('/admin/csv-importer/job/'.$simulation->getId());
        self::assertCount(0, $job->filter('[data-testid="csv-importer-run-form"]'));

        $this->post('/admin/csv-importer/upload', ['mode' => 'create_or_update', '_token' => 'x'], $this->upload("Référence;Titre\nF-1;x\n"));
        self::assertResponseStatusCodeSame(403);

        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($started, $this->starter->started);
    }

    public function testCreatingAllowsStagingAndSimulatingButNotWritingToTheCatalog(): void
    {
        $this->loginAs($this->factory->admin());
        $simulation = $this->finishedSimulation();
        $this->token = null;
        $this->loginAs($this->moduleAdmin([AccessManager::VIEW, AccessManager::CREATE]));

        $job = $this->get('/admin/csv-importer/job/'.$simulation->getId());
        self::assertCount(0, $job->filter('[data-testid="csv-importer-run-form"]'));

        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull(ProductQuery::create()->findOneByRef('SCR-1'));
    }

    public function testAWriteWithoutTheTokenInTheBodyChangesNothing(): void
    {
        $this->loginAs($this->factory->admin());
        $this->get('/admin/csv-importer');

        $this->post('/admin/csv-importer/upload', ['mode' => 'create_or_update'], $this->upload($this->products()));
        self::assertResponseRedirects('/admin/csv-importer');
        $this->client->followRedirect();
        self::assertSelectorExists('[data-testid="bo-flash-error"]');
        self::assertSame(0, CsvImporterJobQuery::create()->count());
        self::assertSame([], $this->storedFiles());

        $token = $this->token();
        $this->post('/admin/csv-importer/upload?_token='.$token, ['mode' => 'create_or_update'], $this->upload($this->products()));
        self::assertResponseRedirects('/admin/csv-importer');
        self::assertSame(0, CsvImporterJobQuery::create()->count(), 'a token in the URL is not accepted');
        self::assertSame([], $this->storedFiles());
    }

    public function testNoOtherWriteWorksWithoutAValidToken(): void
    {
        $this->loginAs($this->factory->admin());
        $simulation = $this->finishedSimulation();
        $draft = CsvImporterJobQuery::create()->filterByStatus('draft')->findOne();
        \assert(null !== $draft);
        $started = $this->starter->started;
        $jobs = CsvImporterJobQuery::create()->count();

        foreach ([[], ['_token' => 'forged']] as $token) {
            $this->post('/admin/csv-importer/job/'.$draft->getId().'/prepare', $this->mappingOf($draft) + ['mode' => 'create_or_update', 'intent' => 'simulate'] + $token);
            self::assertResponseRedirects('/admin/csv-importer/job/'.$draft->getId());
            $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1'] + $token);
            self::assertResponseRedirects('/admin/csv-importer/job/'.$simulation->getId());
        }

        self::assertSame($started, $this->starter->started);
        self::assertSame($jobs, CsvImporterJobQuery::create()->count());
    }

    public function testAFileIsStagedOutOfTheDocumentRootAndItsMappingIsGuessed(): void
    {
        $this->loginAs($this->factory->admin());

        $job = $this->stage();

        self::assertSame('draft', $job->getStatus());
        self::assertSame('products.csv', $job->getSourceName());
        self::assertStringContainsString('/var/csv-importer/uploads/', $job->getSourcePath());
        self::assertStringNotContainsString('/public/', $job->getSourcePath());
        self::assertSame('0600', substr(\sprintf('%o', fileperms($job->getSourcePath())), -4));
        self::assertSame(1, preg_match('#/[0-9a-f]{32}\.csv$#', $job->getSourcePath()));

        $page = $this->get('/admin/csv-importer/job/'.$job->getId());
        self::assertCount(4, $page->filter('[data-testid="csv-importer-mapping-row"]'));
        self::assertSame('reference', $page->filter('select[name="columns[0][field]"] option[selected]')->attr('value'));
        self::assertSame('price', $page->filter('select[name="columns[2][field]"] option[selected]')->attr('value'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedUploads')]
    public function testARefusedFileCreatesNoJobAndLeavesNoFile(string $name, string $content): void
    {
        $this->loginAs($this->factory->admin());
        $this->get('/admin/csv-importer');

        $this->post('/admin/csv-importer/upload', ['mode' => 'create_or_update', 'profile' => 'new', '_token' => $this->token()], $this->upload($content, $name));

        self::assertResponseRedirects('/admin/csv-importer');
        self::assertSame(0, CsvImporterJobQuery::create()->count());
        self::assertSame([], $this->storedFiles());
        $this->client->followRedirect();
        self::assertSelectorExists('[data-testid="bo-flash-error"]', 'the refusal is shown');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedUploads(): iterable
    {
        yield 'wrong extension' => ['products.php', "Référence;Titre\nF-1;x\n"];
        yield 'empty file' => ['products.csv', ''];
        yield 'binary file' => ['products.csv', "PK\x03\x04\x00\x00\x00binary"];
    }

    public function testASimulationRunsThenTheRealImportWritesWhatTheSimulationShowed(): void
    {
        $this->loginAs($this->factory->admin());
        $draft = $this->stage();

        $this->post('/admin/csv-importer/job/'.$draft->getId().'/prepare', $this->mappingOf($draft) + ['mode' => 'create_or_update', 'intent' => 'simulate', '_token' => $this->token()]);
        $simulation = $this->latestJob();
        self::assertResponseRedirects('/admin/csv-importer/job/'.$simulation->getId());
        self::assertSame(1, $simulation->getDryRun());
        self::assertSame('queued', $simulation->getStatus());
        self::assertSame([$simulation->getId()], $this->starter->started);

        $waiting = $this->get('/admin/csv-importer/job/'.$simulation->getId());
        self::assertCount(1, $waiting->filter('[data-testid="csv-importer-progress"]'));
        self::assertStringContainsString('http-equiv="refresh"', (string) $this->client->getResponse()->getContent());

        $this->execute($simulation);

        $report = $this->get('/admin/csv-importer/job/'.$simulation->getId());
        self::assertSame('2', trim($report->filter('[data-testid="csv-importer-count-created"]')->text()));
        self::assertCount(1, $report->filter('[data-testid="csv-importer-simulation-notice"]'));
        self::assertNull(ProductQuery::create()->findOneByRef('SCR-1'), 'a simulation writes nothing');

        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['_token' => $this->token()]);
        self::assertNull(ProductQuery::create()->findOneByRef('SCR-1'), 'the box was not ticked');
        self::assertCount(1, $this->starter->started);

        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);
        $import = $this->latestJob();
        self::assertSame(0, $import->getDryRun());
        self::assertSame([$simulation->getId(), $import->getId()], $this->starter->started);

        $this->execute($import);

        self::assertNotNull(ProductQuery::create()->findOneByRef('SCR-1'));
        self::assertNotNull(ProductQuery::create()->findOneByRef('SCR-2'));
        $done = $this->get('/admin/csv-importer/job/'.$import->getId());
        self::assertSame('2', trim($done->filter('[data-testid="csv-importer-count-created"]')->text()));
        self::assertCount(0, $done->filter('[data-testid="csv-importer-run-form"]'));
    }

    public function testAnImportCannotStartFromAJobThatIsNotAFinishedSimulation(): void
    {
        $this->loginAs($this->factory->admin());
        $draft = $this->stage();
        $realImport = $this->finishedSimulation();
        $realImport->setDryRun(0)->save();
        $this->post('/admin/csv-importer/job/'.$realImport->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);
        $started = $this->starter->started;
        $jobs = CsvImporterJobQuery::create()->count();

        // The other guards (finish date, profile) are satisfied, so only the kind of job refuses
        foreach ([$draft, $realImport] as $job) {
            $job->setFinishedAt(new \DateTimeImmutable('+1 hour'))->save();
            $this->post('/admin/csv-importer/job/'.$job->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);
        }

        self::assertSame($started, $this->starter->started);
        self::assertSame($jobs, CsvImporterJobQuery::create()->count());
    }

    public function testAChangeOfTheMappingAfterTheSimulationForbidsTheImport(): void
    {
        $this->loginAs($this->factory->admin());
        $simulation = $this->finishedSimulation();
        $this->getPropelConnection()->exec('UPDATE csv_importer_job SET created_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = '.(int) $simulation->getId());
        CsvImporterJobTableMap::clearInstancePool();

        $page = $this->get('/admin/csv-importer/job/'.$simulation->getId());
        self::assertCount(1, $page->filter('[data-testid="csv-importer-profile-changed"]'));
        self::assertCount(0, $page->filter('[data-testid="csv-importer-run-form"]'));

        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);

        self::assertSame([], \array_slice($this->starter->started, 1));
        self::assertNull(ProductQuery::create()->findOneByRef('SCR-1'));
    }

    public function testTheSameFileCannotBeImportedTwiceAtOnce(): void
    {
        $this->loginAs($this->factory->admin());
        $simulation = $this->finishedSimulation();

        $this->get('/admin/csv-importer/job/'.$simulation->getId());
        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);
        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);

        self::assertSame(1, CsvImporterJobQuery::create()->filterByDryRun(0)->count());
    }

    public function testAnOldSimulationCannotBeImported(): void
    {
        $this->loginAs($this->factory->admin());
        $simulation = $this->finishedSimulation();
        $this->getPropelConnection()->exec('UPDATE csv_importer_job SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = '.(int) $simulation->getId());
        $this->getPropelConnection()->exec('UPDATE csv_importer_profile SET updated_at = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = '.(int) $simulation->getProfileId());
        CsvImporterJobTableMap::clearInstancePool();
        \CsvImporter\Model\Map\CsvImporterProfileTableMap::clearInstancePool();

        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);

        self::assertSame(0, CsvImporterJobQuery::create()->filterByDryRun(0)->count());
    }

    public function testASimulationAlreadyImportedCannotBeImportedAgain(): void
    {
        $this->loginAs($this->factory->admin());
        $simulation = $this->finishedSimulation();
        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);
        $this->execute($this->latestJob());

        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);

        self::assertSame(1, CsvImporterJobQuery::create()->filterByDryRun(0)->count());
    }

    public function testTheMappingCannotBeChangedWhileAJobRunsOnTheFile(): void
    {
        $this->loginAs($this->factory->admin());
        $draft = $this->stage();
        $this->post('/admin/csv-importer/job/'.$draft->getId().'/prepare', $this->mappingOf($draft) + ['mode' => 'create_or_update', 'intent' => 'simulate', '_token' => $this->token()]);
        $jobs = CsvImporterJobQuery::create()->count();

        $this->post('/admin/csv-importer/job/'.$draft->getId().'/prepare', $this->mappingOf($draft) + ['mode' => 'create_or_update', 'intent' => 'simulate', '_token' => $this->token()]);

        self::assertSame($jobs, CsvImporterJobQuery::create()->count());
    }

    public function testTheBackgroundRunRefusesAnImportWhoseMappingChangedAfterTheRequest(): void
    {
        $this->loginAs($this->factory->admin());
        $simulation = $this->finishedSimulation();
        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);
        $real = $this->latestJob();
        $this->getPropelConnection()->exec('UPDATE csv_importer_job SET created_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = '.(int) $real->getId());
        CsvImporterJobTableMap::clearInstancePool();

        $command = new \CsvImporter\Command\RunJobCommand(new \CsvImporter\Job\ImportJobRepository(), new \CsvImporter\Job\QueuedJobExecutor($this->getService(CatalogImportRunner::class), new \CsvImporter\Job\ImportJobRepository(), new ProfileRepository(), $this->uploadStore()));
        $command->setContainer(static::getContainer());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $tester->execute(['job' => (string) $real->getId()]);

        self::assertSame('failed', CsvImporterJobQuery::create()->findPk($real->getId())->getStatus());
        self::assertNull(ProductQuery::create()->findOneByRef('SCR-1'));
    }

    public function testAnImagesArchiveIsUnpackedBesideTheStagedFileAndOutOfTheDocumentRoot(): void
    {
        $this->loginAs($this->factory->admin());
        $this->get('/admin/csv-importer');

        $this->post('/admin/csv-importer/upload', ['mode' => 'create_or_update', 'profile' => 'new', 'profile_name' => 'zip-'.bin2hex(random_bytes(4)), '_token' => $this->token()], $this->upload($this->products()), $this->zip(['mug.png' => $this->png(), 'photos/tasse.png' => $this->png('x')]));

        $job = $this->latestJob();
        $images = substr($job->getSourcePath(), 0, -4).'.images';

        self::assertStringContainsString('/var/csv-importer/uploads/', $images);
        self::assertFileExists($images.'/mug.png');
        self::assertFileExists($images.'/photos/tasse.png');
        self::assertSame($images, $this->uploadStore()->imagesDirectoryOf($job->getSourcePath()));
    }

    /**
     * @param ?array<string, string> $files
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedArchives')]
    public function testARefusedImagesArchiveCreatesNoJobAndLeavesNothingBehind(?array $files, string $name, ?string $raw): void
    {
        $archive = self::archiveUpload($files, $name, $raw);
        $this->temporaryFiles[] = $archive->getPathname();
        $this->loginAs($this->factory->admin());
        $this->get('/admin/csv-importer');

        $this->post('/admin/csv-importer/upload', ['mode' => 'create_or_update', 'profile' => 'new', '_token' => $this->token()], $this->upload($this->products()), $archive);

        self::assertResponseRedirects('/admin/csv-importer');
        self::assertSame(0, CsvImporterJobQuery::create()->count());
        self::assertSame([], $this->storedFiles());
        $this->client->followRedirect();
        self::assertSelectorExists('[data-testid="bo-flash-error"]');
    }

    /**
     * @return iterable<string, array{?array<string, string>, string, ?string}>
     */
    public static function refusedArchives(): iterable
    {
        yield 'path that leaves the folder' => [['../evil.png' => 'x'], 'images.zip', null];
        yield 'not a zip' => [null, 'images.zip', 'plain text'];
        yield 'wrong extension' => [['a.png' => 'x'], 'images.tar', null];
    }

    public function testTheImagesOfTheArchiveReachTheJobRunInTheBackground(): void
    {
        $this->loginAs($this->factory->admin());
        $this->get('/admin/csv-importer');
        $csv = "Référence;Titre;Prix HT;Catégorie;Image\nZIP-1;Mug;12,50;Cuisine ZIP;photos/mug.png\nZIP-2;Tasse;3,00;Cuisine ZIP;absent.png\n";
        $this->post('/admin/csv-importer/upload', ['mode' => 'create_or_update', 'profile' => 'new', 'profile_name' => 'zip-'.bin2hex(random_bytes(4)), '_token' => $this->token()], $this->upload($csv), $this->zip(['photos/mug.png' => $this->png()]));
        $draft = $this->latestJob();
        $this->post('/admin/csv-importer/job/'.$draft->getId().'/prepare', $this->mappingOf($draft) + ['mode' => 'create_or_update', 'intent' => 'simulate', '_token' => $this->token()]);
        $simulation = $this->latestJob();
        $this->getService(CatalogImportRunner::class)->run($simulation, null, $this->uploadStore()->imagesDirectoryOf($simulation->getSourcePath()));
        $simulation->reload();

        $lines = array_map(static fn ($line): string => (string) $line->getMessage(), CsvImporterJobRowQuery::create()->filterByJobId($simulation->getId())->filterByOutcome('image')->find()->getData());
        self::assertCount(1, $lines, 'only the file the archive does not hold is refused');
        self::assertStringContainsString('absent.png', $lines[0]);
        self::assertStringContainsString('is not in the images directory', $lines[0]);

        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/run', ['confirm' => '1', '_token' => $this->token()]);
        $this->runInTheBackground($this->latestJob());

        self::assertSame(1, ProductImageQuery::create()->filterByProductId(ProductQuery::create()->findOneByRef('ZIP-1')->getId())->count());
        self::assertSame(0, ProductImageQuery::create()->filterByProductId(ProductQuery::create()->findOneByRef('ZIP-2')->getId())->count());
    }

    public function testAJobThatDoesNotMoveCanBeAbandonedAndOnlyThen(): void
    {
        $this->loginAs($this->factory->admin());
        $simulation = $this->finishedSimulation();
        $simulation->setStatus('running')->save();

        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/abandon', ['_token' => $this->token()]);
        self::assertSame('running', CsvImporterJobQuery::create()->findPk($simulation->getId())->getStatus());

        $this->getPropelConnection()->exec('UPDATE csv_importer_job SET updated_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id = '.(int) $simulation->getId());
        CsvImporterJobTableMap::clearInstancePool();

        self::assertCount(1, $this->get('/admin/csv-importer/job/'.$simulation->getId())->filter('[data-testid="csv-importer-abandon-form"]'));

        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/abandon', ['_token' => 'wrong']);
        self::assertSame('running', CsvImporterJobQuery::create()->findPk($simulation->getId())->getStatus());

        $this->post('/admin/csv-importer/job/'.$simulation->getId().'/abandon', ['_token' => $this->token()]);
        self::assertSame('failed', CsvImporterJobQuery::create()->findPk($simulation->getId())->getStatus());
    }

    public function testAnArrayOfFilesIsRefusedWithoutAnError(): void
    {
        $this->loginAs($this->factory->admin());
        $this->get('/admin/csv-importer');

        $this->send('POST', '/admin/csv-importer/upload', ['mode' => 'create_or_update', 'profile' => 'new', 'profile_name' => 'arr', '_token' => $this->token()], ['file' => [$this->upload($this->products())]]);

        self::assertResponseRedirects('/admin/csv-importer');
        self::assertSame(0, CsvImporterJobQuery::create()->count());
    }

    public function testAFileWithTooManyColumnsIsRefused(): void
    {
        $this->loginAs($this->factory->admin());
        $this->get('/admin/csv-importer');
        $header = implode(';', array_map(static fn (int $i): string => 'c'.$i, range(1, 250)));

        $this->post('/admin/csv-importer/upload', ['mode' => 'create_or_update', 'profile' => 'new', 'profile_name' => 'wide', '_token' => $this->token()], $this->upload($header."\n".$header."\n"));

        self::assertSame(0, CsvImporterJobQuery::create()->count());
        self::assertSame([], $this->storedFiles());
    }

    public function testTheReportStreamsEveryLineWhateverTheirNumber(): void
    {
        $this->loginAs($this->factory->admin());
        $simulation = $this->finishedSimulation();
        for ($i = 1; $i <= 1100; ++$i) {
            $this->getService(\CsvImporter\Job\ImportJobRepository::class)->addLine($simulation, $i, 'created', 'R'.$i, null);
        }

        $this->get('/admin/csv-importer/job/'.$simulation->getId().'/report');
        $content = $this->client->getInternalResponse()->getContent();

        self::assertGreaterThanOrEqual(1100, substr_count($content, "\n"));
        self::assertStringContainsString('R1100', $content);
    }

    public function testAMappingWithoutReferenceIsRefusedAndTheProfileKeepsItsMapping(): void
    {
        $this->loginAs($this->factory->admin());
        $draft = $this->stage();
        $before = CsvImporterProfileQuery::create()->findPk($draft->getProfileId())->getMapping();
        $columns = $this->mappingOf($draft);
        $columns['columns'][0]['field'] = '';

        $this->post('/admin/csv-importer/job/'.$draft->getId().'/prepare', $columns + ['mode' => 'create_or_update', 'intent' => 'simulate', '_token' => $this->token()]);

        self::assertResponseRedirects('/admin/csv-importer/job/'.$draft->getId());
        self::assertSame($before, CsvImporterProfileQuery::create()->findPk($draft->getProfileId())->getMapping());
        self::assertSame([], $this->starter->started);
        $page = $this->get('/admin/csv-importer/job/'.$draft->getId());
        self::assertCount(1, $page->filter('[data-testid="bo-flash-error"]'));
    }

    public function testAnUnknownFieldOrAFeatureWithoutNameIsRefused(): void
    {
        $this->loginAs($this->factory->admin());
        $draft = $this->stage();
        $before = CsvImporterProfileQuery::create()->findPk($draft->getProfileId())->getMapping();

        foreach ([['field' => 'nonsense'], ['field' => 'feature', 'name' => '  '], ['field' => 'attribute']] as $bad) {
            $columns = $this->mappingOf($draft);
            $columns['columns'][3] = $bad;
            $this->post('/admin/csv-importer/job/'.$draft->getId().'/prepare', $columns + ['mode' => 'create_or_update', 'intent' => 'save', '_token' => $this->token()]);
            self::assertSame($before, CsvImporterProfileQuery::create()->findPk($draft->getProfileId())->getMapping(), json_encode($bad));
        }
    }

    public function testTheTextOfAFileAndOfItsRowsIsEscapedInThePageAndProtectedInTheCsvReport(): void
    {
        $this->loginAs($this->factory->admin());
        $hostile = '<img src=x onerror=alert(1)>';
        $draft = $this->stage(self::HEADER."=cmd|x;<b>bold</b>;abc;Cat\n", $hostile.'.csv');

        self::assertSame($hostile.'.csv', $draft->getSourceName());
        $this->post('/admin/csv-importer/job/'.$draft->getId().'/prepare', $this->mappingOf($draft) + ['mode' => 'create_or_update', 'intent' => 'simulate', '_token' => $this->token()]);
        $simulation = $this->latestJob();
        $this->execute($simulation);

        $this->get('/admin/csv-importer/job/'.$simulation->getId());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertSame(1, $this->client->getCrawler()->filter('[data-testid="csv-importer-report-line"][data-outcome="failed"]')->count());

        $this->get('/admin/csv-importer/job/'.$simulation->getId().'/report');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $csv = $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString(";'=cmd|x;", $csv);
        self::assertStringNotContainsString(';=cmd|x;', $csv);
    }

    public function testTheAdminLogRecordsTheActionsWithoutCookiesNorFileContent(): void
    {
        $admin = $this->factory->admin();
        $this->loginAs($admin);
        $draft = $this->stage(self::HEADER."LOG-1;Mug;3,5;Cuisine LOG\n");
        $this->post('/admin/csv-importer/job/'.$draft->getId().'/prepare', $this->mappingOf($draft) + ['mode' => 'create_or_update', 'intent' => 'simulate', '_token' => $this->token()]);

        $logs = AdminLogQuery::create()->filterByAdminLogin($admin->getLogin())->filterByMessage('Catalog import%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)->find();

        self::assertGreaterThanOrEqual(3, $logs->count());
        $messages = array_map(static fn ($log): string => $log->getMessage(), $logs->getData());
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'staged')));
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'simulation')));

        foreach ($logs as $log) {
            self::assertStringNotContainsStringIgnoringCase('cookie', $log->getRequest());
            self::assertStringNotContainsString('LOG-1', $log->getRequest());
            self::assertStringNotContainsString($this->token(), $log->getRequest());
        }
    }

    public function testAJobThatNeverStartedTellsHowToStartIt(): void
    {
        $this->loginAs($this->factory->admin());
        $simulation = $this->finishedSimulation();
        $simulation->setStatus('queued')->setFinishedAt(null)->save();
        $this->getPropelConnection()->exec('UPDATE csv_importer_job SET updated_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = '.(int) $simulation->getId());
        CsvImporterJobTableMap::clearInstancePool();

        $page = $this->get('/admin/csv-importer/job/'.$simulation->getId());

        self::assertStringContainsString('csvimporter:run-job '.$simulation->getId(), $page->filter('[data-testid="csv-importer-not-started"]')->text());
    }

    public function testAnUnknownJobGoesBackToTheList(): void
    {
        $this->loginAs($this->factory->admin());

        $this->get('/admin/csv-importer/job/999999');

        self::assertResponseRedirects('/admin/csv-importer');
    }

    private function moduleAdmin(array $accesses): Admin
    {
        $profile = $this->factory->profile();
        $module = ModuleQuery::create()->findOneByCode(CsvImporter::getModuleCode());
        \assert($module instanceof Module);
        $manager = new AccessManager(0);
        $manager->build($accesses);

        (new ProfileModule())->setProfileId($profile->getId())->setModuleId($module->getId())->setAccess($manager->getAccessValue())->save($this->getPropelConnection());

        return $this->factory->admin(['profile' => $profile]);
    }

    private function products(): string
    {
        return self::HEADER."SCR-1;Mug;12,50;Cuisine SCR\nSCR-2;Tasse;3,00;Cuisine SCR\n";
    }

    private function stage(?string $content = null, string $name = 'products.csv'): CsvImporterJob
    {
        $this->get('/admin/csv-importer');
        $this->post('/admin/csv-importer/upload', ['mode' => 'create_or_update', 'profile' => 'new', 'profile_name' => 'screen-'.bin2hex(random_bytes(4)), '_token' => $this->token()], $this->upload($content ?? $this->products(), $name));

        return $this->latestJob();
    }

    private function finishedSimulation(): CsvImporterJob
    {
        $draft = $this->stage();
        $this->post('/admin/csv-importer/job/'.$draft->getId().'/prepare', $this->mappingOf($draft) + ['mode' => 'create_or_update', 'intent' => 'simulate', '_token' => $this->token()]);
        $simulation = $this->latestJob();
        $this->execute($simulation);

        return $simulation;
    }

    private function execute(CsvImporterJob $job): void
    {
        $this->getService(CatalogImportRunner::class)->run($job);
        $job->reload();
    }

    private function latestJob(): CsvImporterJob
    {
        $job = CsvImporterJobQuery::create()->orderById('DESC')->findOne();
        \assert($job instanceof CsvImporterJob);

        return $job;
    }

    /**
     * @return array{columns: array<int, array{field: string, name: string}>}
     */
    private function mappingOf(CsvImporterJob $draft): array
    {
        $profile = (new ProfileRepository())->find((int) $draft->getProfileId());
        \assert(null !== $profile);
        $columns = [];

        foreach (array_values($this->headersOf($draft)) as $index => $header) {
            $target = $profile->mapping->targetOf($header);
            $columns[$index] = ['field' => null === $target ? '' : $target->field->value, 'name' => $target?->name ?? ''];
        }

        return ['columns' => $columns];
    }

    /**
     * @return list<string>
     */
    private function headersOf(CsvImporterJob $job): array
    {
        $first = (string) strtok((string) file_get_contents($job->getSourcePath()), "\n");

        return explode(';', rtrim($first, "\r"));
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        return glob($this->projectDirectory().'/var/csv-importer/uploads/*') ?: [];
    }

    private function uploadStore(): UploadStore
    {
        return new UploadStore($this->projectDirectory(), new ImageArchiveExtractor());
    }

    private function projectDirectory(): string
    {
        return (string) static::getContainer()->getParameter('kernel.project_dir');
    }

    private function runInTheBackground(CsvImporterJob $job): void
    {
        $command = new \CsvImporter\Command\RunJobCommand(new \CsvImporter\Job\ImportJobRepository(), new \CsvImporter\Job\QueuedJobExecutor($this->getService(CatalogImportRunner::class), new \CsvImporter\Job\ImportJobRepository(), new ProfileRepository(), $this->uploadStore()));
        $command->setContainer(static::getContainer());
        (new \Symfony\Component\Console\Tester\CommandTester($command))->execute(['job' => (string) $job->getId()]);
    }

    private function png(string $variant = ''): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true).$variant;
    }

    /**
     * @param array<string, string> $files
     */
    private function zip(array $files, string $name = 'images.zip'): UploadedFile
    {
        $archive = self::archiveUpload($files, $name);
        $this->temporaryFiles[] = $archive->getPathname();

        return $archive;
    }

    /**
     * @param ?array<string, string> $files
     */
    private static function archiveUpload(?array $files, string $name, ?string $raw = null): UploadedFile
    {
        $path = sys_get_temp_dir().'/csv-importer-archive-'.bin2hex(random_bytes(6));

        if (null !== $raw) {
            file_put_contents($path, $raw);
        } else {
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::CREATE);

            foreach ($files as $entry => $content) {
                $zip->addFromString($entry, $content);
            }

            $zip->close();
        }

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    private function token(): string
    {
        $this->token ??= (string) $this->get('/admin/csv-importer')->filter('meta[name="bo-token"]')->attr('content');

        return $this->token;
    }

    private function upload(string $content, string $name = 'products.csv'): UploadedFile
    {
        $path = sys_get_temp_dir().'/csv-importer-screen-'.bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function get(string $uri): Crawler
    {
        return $this->send('GET', $uri);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function post(string $uri, array $parameters, ?UploadedFile $file = null, ?UploadedFile $images = null): Crawler
    {
        // The browser kit leaves the raw body empty: give it, as a browser does, so that what is logged is the real thing
        $raw = null === $file ? http_build_query($parameters) : null;
        $files = null === $file ? [] : ['file' => $file];
        null === $images || $files['images'] = $images;

        return $this->send('POST', $uri, $parameters, $files, $raw);
    }

    private function send(string $method, string $uri, array $parameters = [], array $files = [], ?string $content = null): Crawler
    {
        $requestStack = $this->getService(RequestStack::class);

        while (($request = $requestStack->getCurrentRequest()) instanceof Request && !$request->hasSession()) {
            $requestStack->pop();
        }

        return $this->client->request($method, $uri, $parameters, $files, null === $content ? [] : ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $content);
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }
}
