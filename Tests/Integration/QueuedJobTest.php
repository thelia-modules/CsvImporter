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

namespace CsvImporter\Tests\Integration;

use CsvImporter\Admin\ImageArchiveExtractor;
use CsvImporter\Admin\JobStarter;
use CsvImporter\Admin\QueueJobStarter;
use CsvImporter\Admin\UploadStore;
use CsvImporter\Import\CatalogImportRunner;
use CsvImporter\Import\ImportMode;
use CsvImporter\Job\ImportJobRepository;
use CsvImporter\Job\JobRefusal;
use CsvImporter\Job\QueuedJobExecutor;
use CsvImporter\Job\RunCatalogImportJob;
use CsvImporter\Job\RunCatalogImportJobHandler;
use CsvImporter\Mapping\HeaderGuesser;
use CsvImporter\Mapping\ImportMapping;
use CsvImporter\Model\CsvImporterJob;
use CsvImporter\Profile\ImportProfile;
use CsvImporter\Profile\ProfileRepository;
use CsvImporter\Reader\CsvFileReader;
use CsvImporter\Report\ReportMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Thelia\Messenger\Serializer\AllowedClassesSerializer;
use Thelia\Messenger\Transport\ConfiguredQueues;
use Thelia\Messenger\Transport\ShopDatabaseConnection;
use Thelia\Test\IntegrationTestCase;

final class QueuedJobTest extends IntegrationTestCase
{
    use ImportTestSupport;

    private function queuedJob(string $reference): CsvImporterJob
    {
        $path = sys_get_temp_dir().'/csv-importer-it-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, $this->csv([['Référence', 'Titre', 'Prix HT', 'Catégorie'], [$reference, 'Produit '.$reference, '5,00', 'Queue CSV']]));
        $this->temporaryFiles[] = $path;
        $mapping = ImportMapping::fromProposal((new HeaderGuesser())->guess((new CsvFileReader($path))->headers()));
        $profile = (new ProfileRepository())->save(new ImportProfile(null, 'it-'.bin2hex(random_bytes(6)), null, null, null, $mapping));

        return (new ImportJobRepository())->create($profile->id, $path, 'file.csv', ImportMode::CreateOrUpdate, false, null);
    }

    private function executor(): QueuedJobExecutor
    {
        return new QueuedJobExecutor(
            static::getContainer()->get(CatalogImportRunner::class),
            new ImportJobRepository(),
            new ProfileRepository(),
            new UploadStore((string) static::getContainer()->getParameter('kernel.project_dir'), new ImageArchiveExtractor()),
        );
    }

    private function queues(string $heavyDsn): ConfiguredQueues
    {
        return new ConfiguredQueues(new ShopDatabaseConnection(), 'doctrine://default', $heavyDsn, 'doctrine://default?queue_name=failed');
    }

    public function testTheStarterTheScreensGetIsTheQueueStarterBuiltWithTheRealServices(): void
    {
        self::assertInstanceOf(QueueJobStarter::class, static::getContainer()->get(JobStarter::class));
    }

    public function testAQueuedJobRunsWhenAWorkerHandsItToTheHandler(): void
    {
        $job = $this->queuedJob('QUE-1');

        (new RunCatalogImportJobHandler(new ImportJobRepository(), $this->executor(), static::getContainer()->get('thelia.url.manager')))(new RunCatalogImportJob($job->getId()));

        $job->reload();
        self::assertSame('done', $job->getStatus());
        self::assertNotNull($this->product('QUE-1'));
    }

    public function testAJobHandedOutTwiceIsRefusedTheSecondTimeAndTheHandlerStaysQuiet(): void
    {
        $job = $this->queuedJob('QUE-2');
        $handler = new RunCatalogImportJobHandler(new ImportJobRepository(), $this->executor(), static::getContainer()->get('thelia.url.manager'));

        $handler(new RunCatalogImportJob($job->getId()));
        $job->reload();

        self::assertSame(JobRefusal::NotQueued, $this->executor()->execute($job));
        $handler(new RunCatalogImportJob($job->getId()));
        self::assertSame('done', $job->getStatus());
    }

    public function testAJobTheRunnerStoppedOnAnUnexpectedErrorRunsAgainWhenItsMessageIsReplayed(): void
    {
        $job = $this->queuedJob('QUE-5');
        $job->setStatus('failed')->setError((new ReportMessage(CatalogImportRunner::UNEXPECTED_ERROR))->toJson())->save();

        (new RunCatalogImportJobHandler(new ImportJobRepository(), $this->executor(), static::getContainer()->get('thelia.url.manager')))(new RunCatalogImportJob($job->getId()));

        $job->reload();
        self::assertSame('done', $job->getStatus());
        self::assertNull($job->getError());
        self::assertNotNull($this->product('QUE-5'));
    }

    public function testAJobAnAdministratorAbandonedIsNotBroughtBackByAReplay(): void
    {
        $job = $this->queuedJob('QUE-6');
        (new ImportJobRepository())->abandon($job);

        (new RunCatalogImportJobHandler(new ImportJobRepository(), $this->executor(), static::getContainer()->get('thelia.url.manager')))(new RunCatalogImportJob($job->getId()));

        $job->reload();
        self::assertSame('failed', $job->getStatus());
        self::assertStringContainsString('abandoned', (string) $job->getError());
        self::assertNull($this->product('QUE-6'));
    }

    public function testACreateOnlyJobIsNotReplayedBecauseItsFirstRunMayHaveCreatedProducts(): void
    {
        $job = $this->queuedJob('QUE-7');
        $job->setMode('create_only')->setStatus('failed')->setError((new ReportMessage(CatalogImportRunner::UNEXPECTED_ERROR))->toJson())->save();

        (new RunCatalogImportJobHandler(new ImportJobRepository(), $this->executor(), static::getContainer()->get('thelia.url.manager')))(new RunCatalogImportJob($job->getId()));

        $job->reload();
        self::assertSame('failed', $job->getStatus());
        self::assertNull($this->product('QUE-7'));
        self::assertStringContainsString('not replayed', (string) $job->getError());
    }

    public function testAJobThatNoLongerExistsIsLeftAlone(): void
    {
        (new RunCatalogImportJobHandler(new ImportJobRepository(), $this->executor(), static::getContainer()->get('thelia.url.manager')))(new RunCatalogImportJob(987654321));

        $this->addToAssertionCount(1);
    }

    public function testTheMessageTravelsThroughTheQueueSerializerAndHasItsHandler(): void
    {
        $serializer = static::getContainer()->get(AllowedClassesSerializer::class);

        $decoded = $serializer->decode($serializer->encode(new Envelope(new RunCatalogImportJob(42))));

        self::assertEquals(new RunCatalogImportJob(42), $decoded->getMessage());
    }

    public function testTheMessageIsRoutedToTheHeavyQueue(): void
    {
        $locator = static::getContainer()->get('messenger.senders_locator');

        self::assertSame(['async_heavy'], array_keys(iterator_to_array($locator->getSenders(new Envelope(new RunCatalogImportJob(1))))));
    }

    public function testWithAQueueTheJobIsDispatchedAndNoProcessIsStarted(): void
    {
        $bus = $this->spyBus();
        $launcher = $this->spyLauncher();
        $job = $this->queuedJob('QUE-3');

        self::assertTrue((new QueueJobStarter($this->queues('doctrine://default?queue_name=heavy'), $bus, $launcher))->start($job));

        self::assertEquals([new RunCatalogImportJob($job->getId())], $bus->dispatched);
        self::assertSame([], $launcher->started);
    }

    public function testWithoutAQueueTheJobRunsInAProcessOfItsOwn(): void
    {
        $bus = $this->spyBus();
        $launcher = $this->spyLauncher();
        $job = $this->queuedJob('QUE-4');

        self::assertTrue((new QueueJobStarter($this->queues('sync://'), $bus, $launcher))->start($job));

        self::assertSame([], $bus->dispatched);
        self::assertSame([$job->getId()], $launcher->started);
    }

    public function testAQueueThatRefusesTheJobIsReportedAsNotStarted(): void
    {
        $bus = $this->spyBus(true);

        self::assertFalse((new QueueJobStarter($this->queues('doctrine://default?queue_name=heavy'), $bus, $this->spyLauncher()))->start($this->queuedJob('QUE-5')));
    }

    private function spyBus(bool $refuses = false): MessageBusInterface
    {
        return new class($refuses) implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function __construct(private readonly bool $refuses)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($this->refuses) {
                    throw new \RuntimeException('queue down');
                }

                $this->dispatched[] = $message;

                return new Envelope($message);
            }
        };
    }

    private function spyLauncher(): JobStarter
    {
        return new class implements JobStarter {
            /** @var list<int> */
            public array $started = [];

            public function start(CsvImporterJob $job): bool
            {
                $this->started[] = $job->getId();

                return true;
            }
        };
    }
}
