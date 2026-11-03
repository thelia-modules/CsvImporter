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

use CsvImporter\Job\ImportJobRepository;
use CsvImporter\Job\JobStatus;
use CsvImporter\Model\CsvImporterJob;
use CsvImporter\Model\CsvImporterJobQuery;
use Propel\Runtime\Propel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thelia\Test\IntegrationTestCase;

final class AbandonedJobTest extends IntegrationTestCase
{
    use ImportTestSupport;

    private const HEADER = ['Référence', 'Titre', 'Prix HT', 'Catégorie'];

    private function abandonLatestJob(): void
    {
        $job = CsvImporterJobQuery::create()->orderById('desc')->findOne();
        (new ImportJobRepository())->abandon($job);
    }

    private function runningJob(): CsvImporterJob
    {
        $job = (new ImportJobRepository())->create(null, '/tmp/none.csv', 'none.csv', \CsvImporter\Import\ImportMode::CreateOrUpdate, false, null);
        $job->setStatus(JobStatus::Running->value)->save();

        return $job;
    }

    public function testAnImportAbandonedWhileItRunsStopsWritingAndStaysAbandoned(): void
    {
        $rows = [self::HEADER];

        for ($line = 1; $line <= 80; ++$line) {
            $rows[] = ['ABD-'.$line, 'Produit '.$line, '5,00', 'Abandon CSV'];
        }

        $abandoned = false;
        $job = $this->importCsv($this->csv($rows), onRow: function () use (&$abandoned): void {
            if (!$abandoned && 3 === \count($this->results)) {
                $abandoned = true;
                $this->abandonLatestJob();
            }
        });

        self::assertSame('failed', $job->getStatus());
        self::assertStringContainsString('abandoned', (string) $job->getError());
        self::assertNotNull($this->product('ABD-1'));
        self::assertNull($this->product('ABD-80'), 'the process kept writing after the abandon');
        self::assertLessThanOrEqual(25, \count($this->results), 'the abandon is seen at the next progress step');
    }

    public function testAnImportAbandonedDuringTheImagePassStopsLoadingImages(): void
    {
        $requests = 0;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        $this->imageClient = new MockHttpClient(function () use (&$requests, $png): MockResponse {
            ++$requests;
            $this->abandonLatestJob();

            return new MockResponse($png.$requests, ['http_code' => 200]);
        });

        $job = $this->importCsv($this->csv([[...self::HEADER, 'Image'], ['ABD-I1', 'Un', '5,00', 'Abandon CSV', 'https://cdn.example.com/un.png'], ['ABD-I2', 'Deux', '5,00', 'Abandon CSV', 'https://cdn.example.com/deux.png']]));

        self::assertSame(1, $requests, 'a second image was fetched after the abandon');
        self::assertSame('failed', $job->getStatus());
    }

    public function testAFinishedRunDoesNotOverwriteAnAbandonedJob(): void
    {
        $repository = new ImportJobRepository();
        $job = $this->runningJob();
        $repository->abandon(CsvImporterJobQuery::create()->findPk($job->getId()));

        $repository->markDone($job);

        self::assertSame('failed', $job->getStatus());
    }

    public function testTouchRefreshesTheLastActivityOfARunningJobOnly(): void
    {
        $repository = new ImportJobRepository();
        $job = $this->runningJob();
        Propel::getConnection()->exec('UPDATE csv_importer_job SET updated_at = \'2020-01-01 00:00:00\' WHERE id = '.$job->getId());

        self::assertTrue($repository->touch($job));
        $job->reload();
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $job->getUpdatedAt());

        $repository->abandon($job);
        Propel::getConnection()->exec('UPDATE csv_importer_job SET updated_at = \'2020-01-01 00:00:00\' WHERE id = '.$job->getId());

        self::assertFalse($repository->touch($job));
        $job->reload();
        self::assertSame('2020-01-01', $job->getUpdatedAt()->format('Y-m-d'));
    }

    public function testAnImportAbandonedWhileTheImagePassScansRowsWithoutImagesStops(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        $this->imageClient = new MockHttpClient(function () use ($png): MockResponse {
            $this->abandonLatestJob();

            return new MockResponse($png, ['http_code' => 200]);
        });
        $rows = [[...self::HEADER, 'Image'], ['ABD-S0', 'Zero', '5,00', 'Abandon CSV', 'https://cdn.example.com/zero.png']];

        for ($line = 1; $line <= 40; ++$line) {
            $rows[] = ['ABD-S'.$line, 'Produit '.$line, '5,00', 'Abandon CSV', ''];
        }

        $job = $this->importCsv($this->csv($rows));

        self::assertSame('failed', $job->getStatus());
        self::assertSame([], $this->reportLines($job, 'info'), 'the image pass ran to its end after the abandon');
    }

    public function testAnAbandonOnlyTakesAJobThatHasBeenIdleLongEnough(): void
    {
        $repository = new ImportJobRepository();
        $job = $this->runningJob();

        self::assertFalse($repository->abandon($job, 300));
        self::assertSame('running', $job->getStatus());

        Propel::getConnection()->exec('UPDATE csv_importer_job SET updated_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id = '.$job->getId());

        self::assertTrue($repository->abandon($job, 300));
        self::assertSame('failed', $job->getStatus());
    }

    public function testClaimingAJobThatWaitedStampsItAsAlive(): void
    {
        $repository = new ImportJobRepository();
        $job = (new ImportJobRepository())->create(null, '/tmp/none.csv', 'none.csv', \CsvImporter\Import\ImportMode::CreateOrUpdate, false, null);
        Propel::getConnection()->exec('UPDATE csv_importer_job SET updated_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id = '.$job->getId());

        self::assertTrue($repository->claim($job));

        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $job->getUpdatedAt());
    }

    public function testARequeuedJobStartsFromZeroWithoutTheLinesOfItsFailedRun(): void
    {
        $repository = new ImportJobRepository();
        $job = $this->runningJob();
        $repository->addLine($job, 3, 'failed', 'X', null);
        $repository->saveProgress($job, 9, ['failed' => 4]);
        $repository->markFailed($job, new \CsvImporter\Report\ReportMessage('boom'));

        self::assertTrue($repository->requeue($job));

        self::assertSame('queued', $job->getStatus());
        self::assertSame(0, $job->getProcessedRows());
        self::assertSame(0, $job->getFailedRows());
        self::assertNull($job->getError());
        self::assertSame(0, $repository->countLines($job, null));
        self::assertFalse($repository->requeue($job), 'a job that is not failed is not requeued');
    }
}
