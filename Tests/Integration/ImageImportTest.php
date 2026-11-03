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

use CsvImporter\Image\ImageFetcher;
use CsvImporter\Image\ImageHttpClientFactory;
use CsvImporter\Model\CsvImporterJobRowQuery;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thelia\Model\ProductImageQuery;
use Thelia\Test\IntegrationTestCase;

final class ImageImportTest extends IntegrationTestCase
{
    use ImportTestSupport;

    private const HEADER = ['Référence', 'Titre', 'Prix HT', 'Catégorie', 'Image'];

    private function png(string $variant = ''): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true).$variant;
    }

    private function row(string $reference, string $image): array
    {
        return [$reference, 'Produit '.$reference, '5,00', 'Images CSV', $image];
    }

    private function imageCount(string $reference): int
    {
        return ProductImageQuery::create()->filterByProductId($this->product($reference)->getId())->count();
    }

    private function imagesDirectoryWith(array $files): string
    {
        $directory = sys_get_temp_dir().'/csv-importer-images-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o700);

        foreach ($files as $name => $content) {
            file_put_contents($directory.'/'.$name, $content);
        }

        $this->temporaryFiles[] = $directory;

        return $directory;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_dir($path)) {
                foreach (glob($path.'/*') ?: [] as $entry) {
                    @unlink($entry);
                }
                @rmdir($path);
            }
        }

        parent::tearDown();
    }

    private function imageLines($job): array
    {
        return array_map(static fn ($line): string => (string) $line->getMessage(), CsvImporterJobRowQuery::create()->filterByJobId($job->getId())->filterByOutcome('image')->find()->getData());
    }

    public function testARemoteImageIsDownloadedAfterTheProductAndNotDuplicatedOnASecondRun(): void
    {
        $requests = [];
        $this->imageClient = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;

            return new MockResponse($this->png(), ['http_code' => 200]);
        });

        $this->importCsv($this->csv([self::HEADER, $this->row('IMG-1', 'https://cdn.example.com/photos/mug.png')]));

        self::assertSame(1, $this->imageCount('IMG-1'));
        self::assertSame(['https://cdn.example.com/photos/mug.png'], $requests);

        $second = $this->importCsv($this->csv([self::HEADER, $this->row('IMG-1', 'https://cdn.example.com/photos/mug.png')]));

        self::assertSame(1, $this->imageCount('IMG-1'));
        self::assertSame([], $this->imageLines($second));
    }

    public function testAFailedDownloadKeepsTheProductAndIsReportedOnItsLine(): void
    {
        $this->imageClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('nope', ['http_code' => 404]));

        $job = $this->importCsv($this->csv([self::HEADER, $this->row('IMG-2', 'https://cdn.example.com/missing.png')]));

        self::assertNotNull($this->product('IMG-2'));
        self::assertSame(0, $this->imageCount('IMG-2'));
        self::assertCount(1, $this->imageLines($job));
        self::assertStringContainsString('status', $this->imageLines($job)[0]);
    }

    public function testWhatIsNotAnImageIsRefusedWhateverItsName(): void
    {
        $this->imageClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('<?php echo 1;', ['http_code' => 200]));

        $job = $this->importCsv($this->csv([self::HEADER, $this->row('IMG-3', 'https://cdn.example.com/shell.png')]));

        self::assertSame(0, $this->imageCount('IMG-3'));
        self::assertStringContainsString('not a JPEG, PNG, GIF or WebP', $this->imageLines($job)[0]);
    }

    public function testAnImageAboveTheLimitIsCutAndRefused(): void
    {
        $this->imageClient = new MockHttpClient(fn (): MockResponse => new MockResponse($this->png(str_repeat('0', ImageFetcher::MAX_BYTES)), ['http_code' => 200]));

        $job = $this->importCsv($this->csv([self::HEADER, $this->row('IMG-4', 'https://cdn.example.com/huge.png')]));

        self::assertSame(0, $this->imageCount('IMG-4'));
        self::assertStringContainsString('larger than', $this->imageLines($job)[0]);
    }

    public function testALocalFileIsLoadedFromTheImagesDirectoryOnly(): void
    {
        $this->imagesDirectory = $this->imagesDirectoryWith(['local.png' => $this->png('a')]);

        $this->importCsv($this->csv([self::HEADER, $this->row('IMG-5', 'local.png')]));

        self::assertSame(1, $this->imageCount('IMG-5'));
    }

    public function testASourceThatEscapesTheImagesDirectoryOrIsNotHttpIsRefused(): void
    {
        $this->imagesDirectory = $this->imagesDirectoryWith(['inside.png' => $this->png('b')]);
        $outside = sys_get_temp_dir().'/csv-importer-outside-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($outside, $this->png('c'));
        $this->temporaryFiles[] = $outside;
        symlink($outside, $this->imagesDirectory.'/link.png');

        foreach (['../'.basename($outside), '/etc/hosts', 'link.png', 'file:///etc/hosts', 'ftp://example.com/a.png', 'gopher://example.com/', 'data:image/png,AAAA', 'php://filter/resource=x', 'missing.png'] as $number => $source) {
            $reference = 'IMG-6-'.$number;
            $job = $this->importCsv($this->csv([self::HEADER, $this->row($reference, $source)]));

            self::assertSame(0, $this->imageCount($reference), $source);
            self::assertCount(1, $this->imageLines($job), $source);
        }

        @unlink($this->imagesDirectory.'/link.png');
    }

    public function testASimulationNeverCallsTheNetworkNorWritesAnImage(): void
    {
        $this->imagesDirectory = $this->imagesDirectoryWith([]);

        $job = $this->importCsv($this->csv([self::HEADER, $this->row('IMG-7', 'https://cdn.example.com/a.png'), $this->row('IMG-8', 'absent.png')]), dryRun: true);

        self::assertNull($this->product('IMG-7'));
        self::assertCount(1, $this->imageLines($job));
        self::assertStringContainsString('"%remote":"1"', (string) CsvImporterJobRowQuery::create()->filterByJobId($job->getId())->filterByOutcome('info')->findOne()->getMessage());
    }

    public function testImagesOnlyLoadsTheImagesOfExistingProductsAndLeavesTheRestAlone(): void
    {
        $this->importCsv($this->csv([self::HEADER, ['IMG-9', 'Produit', '5,00', 'Images CSV', '']]));
        $this->imageClient = new MockHttpClient(fn (): MockResponse => new MockResponse($this->png('d'), ['http_code' => 200]));

        $this->importCsv($this->csv([self::HEADER, ['IMG-9', 'Autre titre', '99,00', 'Images CSV', 'https://cdn.example.com/late.png']]), imagesOnly: true);

        self::assertSame(1, $this->imageCount('IMG-9'));
        self::assertSame('5.000000', $this->priceOf('IMG-9'));
    }

    public function testARowRefusedByTheFirstPassGetsNoImage(): void
    {
        $this->importCsv($this->csv([self::HEADER, ['IMG-10', 'Existing', '5,00', 'Images CSV', '']]));
        $requests = 0;
        $this->imageClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse($this->png(), ['http_code' => 200]);
        });

        $this->importCsv($this->csv([self::HEADER, ['IMG-10', 'Existing', 'abc', 'Images CSV', 'https://cdn.example.com/x.png']]));

        self::assertSame(0, $requests);
        self::assertSame(0, $this->imageCount('IMG-10'));
    }

    public function testTheProductionClientNeverReachesAnInternalAddress(): void
    {
        $client = ImageHttpClientFactory::create();

        foreach (['http://127.0.0.1:9/a.png', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.1/a.png', 'http://[::1]/a.png', 'http://100.64.0.1/a.png'] as $url) {
            try {
                $client->request('GET', $url)->getStatusCode();
                self::fail('The internal address was requested: '.$url);
            } catch (\Symfony\Component\HttpClient\Exception\TransportException $refused) {
                self::assertStringContainsString('blocked', $refused->getMessage(), $url);
            }
        }
    }
}
