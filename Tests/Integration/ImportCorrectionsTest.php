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
use CsvImporter\Import\ImportMode;
use CsvImporter\Job\ImportJobRepository;
use CsvImporter\Mapping\CatalogField;
use CsvImporter\Mapping\ColumnTarget;
use CsvImporter\Mapping\ImportMapping;
use CsvImporter\Model\CsvImporterProfile;
use CsvImporter\Profile\ImportProfile;
use CsvImporter\Profile\ProfileRepository;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\IntegrationTestCase;

final class ImportCorrectionsTest extends IntegrationTestCase
{
    use ImportTestSupport;

    private const HEADER = ['Référence', 'Titre', 'Prix HT', 'Catégorie', 'EAN', 'C:Couleur', 'Description'];

    private function eanOf(string $reference): ?string
    {
        return ProductSaleElementsQuery::create()->filterByProductId($this->product($reference)->getId())->filterByIsDefault(true)->findOne()->getEanCode();
    }

    public function testADashInTheEanColumnDoesNotEraseTheStoredCode(): void
    {
        $this->importCsv($this->csv([self::HEADER, ['COR-1', 'Produit', '5,00', 'Corrections CSV', '4006381333931', '', '']]));

        foreach (['-', '--', ' - - '] as $dash) {
            $job = $this->importCsv($this->csv([self::HEADER, ['COR-1', 'Produit', '5,00', 'Corrections CSV', $dash, '', '']]));

            self::assertSame(1, $job->getFailedRows(), $dash);
            self::assertSame('4006381333931', $this->eanOf('COR-1'), $dash);
        }
    }

    public function testAnEanTypedWithDashesIsStoredNormalizedAndStaysUnchanged(): void
    {
        $this->importCsv($this->csv([self::HEADER, ['COR-2', 'Produit', '5,00', 'Corrections CSV', '4006381333-931', '', '']]));

        self::assertSame('4006381333931', $this->eanOf('COR-2'));
        self::assertSame(1, $this->importCsv($this->csv([self::HEADER, ['COR-2', 'Produit', '5,00', 'Corrections CSV', '4006381333-931', '', '']]))->getUnchangedRows());
    }

    public function testAFeatureCellHoldsSeveralValuesAndNoneIsLost(): void
    {
        $this->importCsv($this->csv([self::HEADER, ['COR-3', 'Produit', '5,00', 'Corrections CSV', '', 'Rouge|Bleu', '']]));
        $productId = $this->product('COR-3')->getId();

        self::assertCount(2, FeatureProductQuery::create()->filterByProductId($productId)->find());

        $job = $this->importCsv($this->csv([self::HEADER, ['COR-3', 'Produit', '5,00', 'Corrections CSV', '', 'Bleu|Rouge', '']]));

        self::assertSame(1, $job->getUnchangedRows());
        self::assertCount(2, FeatureProductQuery::create()->filterByProductId($productId)->find());

        $this->importCsv($this->csv([self::HEADER, ['COR-3', 'Produit', '5,00', 'Corrections CSV', '', 'Vert', '']]));

        self::assertCount(1, FeatureProductQuery::create()->filterByProductId($productId)->find());
    }

    public function testADescriptionThatTheCleanerWouldRewriteIsNotTouchedWhenTheFileHoldsTheSameText(): void
    {
        $html = '<p class="a" style="color:red">Texte<br>suite</p>';
        $this->importCsv($this->csv([self::HEADER, ['COR-4', 'Produit', '5,00', 'Corrections CSV', '', '', $html]]));
        $product = $this->product('COR-4');
        $product->setDescription($html)->save();

        $job = $this->importCsv($this->csv([self::HEADER, ['COR-4', 'Produit', '5,00', 'Corrections CSV', '', '', $html]]));

        self::assertSame(1, $job->getUnchangedRows());
        self::assertSame($html, $this->product('COR-4')->getDescription());
    }

    public function testAFailureOutsideTheRowsLeavesTheJobFailedAndNotRunning(): void
    {
        $path = sys_get_temp_dir().'/csv-importer-stuck-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, $this->csv([self::HEADER, ['COR-5', 'Produit', '5,00', 'Corrections CSV', '', '', '']]));
        $this->temporaryFiles[] = $path;
        $profile = (new CsvImporterProfile())->setName('broken-'.bin2hex(random_bytes(4)))->setMapping('{"Référence":{"field":"nothing"}}');
        $profile->save();
        $job = (new ImportJobRepository())->create($profile->getId(), $path, 'file.csv', ImportMode::CreateOrUpdate, false, null);

        try {
            $this->runner()->run($job);
            self::fail('The unreadable profile should have thrown');
        } catch (\InvalidArgumentException) {
        }

        $job->reload();

        self::assertSame('failed', $job->getStatus());
        self::assertNull($this->product('COR-5'));
    }

    public function testADelimiterOfSeveralCharactersFailsTheJobCleanly(): void
    {
        $profile = (new ProfileRepository())->save(new ImportProfile(null, 'delimiter-'.bin2hex(random_bytes(4)), 'ab', null, null, new ImportMapping(['Référence' => new ColumnTarget(CatalogField::Reference)])));
        $path = sys_get_temp_dir().'/csv-importer-delimiter-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, "Référence\nCOR-8\n");
        $this->temporaryFiles[] = $path;
        $job = (new ImportJobRepository())->create($profile->id, $path, 'file.csv', ImportMode::CreateOrUpdate, false, null);

        $this->runner()->run($job);
        $job->reload();

        self::assertSame('failed', $job->getStatus());
    }

    public function testAnImageDeclaringHugeDimensionsIsRefusedBeforeAnyProcessing(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        $huge = substr($png, 0, 16).pack('N', 30000).pack('N', 30000).substr($png, 24);
        $this->imageClient = new MockHttpClient(static fn (): MockResponse => new MockResponse($huge, ['http_code' => 200]));

        $job = $this->importCsv($this->csv([['Référence', 'Titre', 'Prix HT', 'Catégorie', 'Image'], ['COR-6', 'Produit', '5,00', 'Corrections CSV', 'https://user:secret@cdn.example.com/big.png?token=abc']]));

        self::assertSame(0, ProductImageQuery::create()->filterByProductId($this->product('COR-6')->getId())->count());
        $lines = array_map(static fn ($line): string => (string) $line->getMessage(), $this->reportLines($job, 'image'));
        self::assertCount(1, $lines);
        self::assertStringContainsString('million pixels', $lines[0]);
        self::assertSame(0, (int) (ImageFetcher::MAX_PIXELS < 30000 * 30000 ? 0 : 1));
    }

    public function testAnUrlWithCredentialsOrAQueryNeverReachesTheReport(): void
    {
        $this->imageClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 403]));

        $job = $this->importCsv($this->csv([['Référence', 'Titre', 'Prix HT', 'Catégorie', 'Image'], ['COR-7', 'Produit', '5,00', 'Corrections CSV', 'https://user:secret@cdn.example.com/a.png?token=abc']]));
        $message = (string) $this->reportLines($job, 'image')[0]->getMessage();

        self::assertStringNotContainsString('secret', $message);
        self::assertStringNotContainsString('token', $message);
        self::assertStringContainsString('cdn.example.com/a.png', $message);
    }

    public function testAFeatureCellWithSeveralShortValuesLongerThan255CharactersIsImported(): void
    {
        $values = [];

        foreach (range(1, 12) as $index) {
            $values[] = 'Compatible modele numero '.$index.' serie longue';
        }

        $cell = implode('|', $values);
        self::assertGreaterThan(255, mb_strlen($cell));

        $job = $this->importCsv($this->csv([
            ['Référence', 'Titre', 'Prix HT', 'Catégorie', 'C:Compatibilite'],
            ['CORR-FEAT-LEN', 'Produit', '5,00', 'Corrections', $cell],
        ]));

        self::assertSame(0, $job->getFailedRows(), 'each value fits in 255 characters, the line must not be refused: '.implode(' / ', array_map(static fn ($line): string => (string) $line->getMessage(), \CsvImporter\Model\CsvImporterJobRowQuery::create()->filterByJobId($job->getId())->find()->getData())));
        self::assertSame(1, $job->getCreatedRows());
        self::assertCount(12, FeatureProductQuery::create()->filterByProductId($this->product('CORR-FEAT-LEN')->getId())->find());
    }

    public function testALineOfSeparatorsOnlyIsSkippedLikeABlankLine(): void
    {
        $job = $this->importCsv(
            "Référence;Titre;Prix HT;Catégorie\n"
            ."CORR-EMPTY-1;Produit;5,00;Corrections\n"
            .";;;\n"
            ."\n"
            ."CORR-EMPTY-2;Produit;6,00;Corrections\n"
            .";;;\n",
        );

        self::assertSame(2, $job->getCreatedRows());
        self::assertSame(0, $job->getFailedRows(), 'an empty spreadsheet row is not a refused line');
        self::assertSame(2, $job->getTotalRows(), 'an empty spreadsheet row is not a line to import');
    }
}
