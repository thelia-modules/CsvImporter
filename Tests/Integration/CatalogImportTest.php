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

use CsvImporter\Import\CatalogImportRunner;
use CsvImporter\Import\ImportMode;
use CsvImporter\Job\ImportJobRepository;
use CsvImporter\Mapping\CatalogField;
use CsvImporter\Mapping\ColumnTarget;
use CsvImporter\Mapping\HeaderGuesser;
use CsvImporter\Mapping\ImportMapping;
use Propel\Runtime\Propel;
use Thelia\Domain\Catalog\Product\Identifier\Gtin;
use Thelia\Model\BrandQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\RewritingUrlQuery;
use Thelia\Model\TemplateQuery;
use Thelia\Test\IntegrationTestCase;

final class CatalogImportTest extends IntegrationTestCase
{
    use ImportTestSupport;

    private const HEADER = ['Référence', 'Désignation', 'Description courte', 'Description', 'Prix HT', 'Stock', 'Poids', 'Famille', 'Marque', 'Gabarit', 'EAN', 'C:Couleur'];

    private function fullRow(int $number, string $price = '10,50'): array
    {
        return ["CSVIT-$number", "Chaise numéro $number", "Courte $number", "Longue $number", $price, (string) (5 + $number), '1,25', 'Maison CSV > Cuisine CSV', 'Marque CSV', 'Gabarit CSV', $this->ean($number), 'Rouge'];
    }

    private function ean(int $number): string
    {
        $base = '40063813'.str_pad((string) $number, 4, '0', \STR_PAD_LEFT);

        return $base.Gtin::checkDigitOf($base);
    }

    private function tenProducts(string $price = '10,50'): string
    {
        $rows = [self::HEADER];

        for ($number = 1; $number <= 10; ++$number) {
            $rows[] = $this->fullRow($number, 3 >= $number ? '99,90' : $price);
        }

        return $this->csv($rows);
    }

    public function testItCreatesTheProductsOfAFile(): void
    {
        $job = $this->importCsv($this->tenProducts());

        self::assertSame('done', $job->getStatus());
        self::assertSame(10, $job->getCreatedRows());
        self::assertSame(0, $job->getFailedRows());

        $product = $this->product('CSVIT-4');
        self::assertNotNull($product);
        self::assertSame('Chaise numéro 4', $product->getTitle());
        self::assertSame('Courte 4', $product->getChapo());
        self::assertSame('Longue 4', $product->getDescription());
        self::assertSame('10.5', rtrim(rtrim((string) $this->priceOf('CSVIT-4'), '0'), '.'));

        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertEqualsWithDelta(9.0, $pse->getQuantity(), 0.0001);
        self::assertEqualsWithDelta(1.25, $pse->getWeight(), 0.0001);
        self::assertSame($this->ean(4), $pse->getEanCode());
    }

    public function testItCreatesTheCategoryPathTheBrandTheTemplateAndTheFeatureOnce(): void
    {
        $this->importCsv($this->tenProducts());

        $kitchen = CategoryQuery::create()->useCategoryI18nQuery()->filterByTitle('Cuisine CSV')->endUse()->find();
        self::assertCount(1, $kitchen);
        $parent = CategoryQuery::create()->findPk($kitchen[0]->getParent());
        $parent->setLocale($this->locale());
        self::assertSame('Maison CSV', $parent->getTitle());
        self::assertSame($kitchen[0]->getId(), $this->product('CSVIT-1')->getDefaultCategoryId());

        self::assertCount(1, BrandQuery::create()->useBrandI18nQuery()->filterByTitle('Marque CSV')->endUse()->find());
        self::assertCount(1, TemplateQuery::create()->useTemplateI18nQuery()->filterByName('Gabarit CSV')->endUse()->find());
        self::assertSame(1, FeatureProductQuery::create()->filterByProductId($this->product('CSVIT-1')->getId())->count());
    }

    public function testACreatedProductIsReachableByARewrittenUrl(): void
    {
        $this->importCsv($this->tenProducts());

        $count = RewritingUrlQuery::create()->filterByView('product')->filterByViewId($this->product('CSVIT-2')->getId())->count();

        self::assertGreaterThan(0, $count);
    }

    public function testASimulationWritesNothingAndAnnouncesWhatTheImportDoes(): void
    {
        $simulation = $this->importCsv($this->tenProducts(), dryRun: true);
        $simulated = $this->outcomes();

        self::assertNull($this->product('CSVIT-1'));
        self::assertSame(10, $simulation->getCreatedRows());
        self::assertSame(0, CategoryQuery::create()->useCategoryI18nQuery()->filterByTitle('Cuisine CSV')->endUse()->count());
        self::assertSame(0, BrandQuery::create()->useBrandI18nQuery()->filterByTitle('Marque CSV')->endUse()->count());

        $creations = $this->reportLines($simulation, 'creation');
        self::assertNotEmpty($creations);

        $import = $this->importCsv($this->tenProducts());

        self::assertSame($simulated, $this->outcomes());
        self::assertSame($simulation->getCreatedRows(), $import->getCreatedRows());
        self::assertSame(\count($creations), \count($this->reportLines($import, 'creation')));
    }

    public function testARerunWithThreeNewPricesUpdatesThreeProductsAndLeavesSevenUnchanged(): void
    {
        $this->importCsv($this->tenProducts('10,50'));

        $rerun = $this->importCsv($this->withPrices(['1' => '5', '2' => '6', '3' => '7']));

        self::assertSame(3, $rerun->getUpdatedRows());
        self::assertSame(7, $rerun->getUnchangedRows());
        self::assertSame(0, $rerun->getCreatedRows());
        self::assertSame(5.0, (float) $this->priceOf('CSVIT-1'));
        self::assertSame(10.5, (float) $this->priceOf('CSVIT-5'));
    }

    public function testAnUnchangedProductKeepsItsModificationDate(): void
    {
        $this->importCsv($this->tenProducts('10,50'));
        $this->getPropelConnection()->exec("UPDATE product SET updated_at = '2020-01-02 03:04:05' WHERE ref LIKE 'CSVIT-%'");
        \Thelia\Model\Map\ProductTableMap::clearInstancePool();

        $rerun = $this->importCsv($this->withPrices(['1' => '5']));

        self::assertSame(1, $rerun->getUpdatedRows());
        self::assertSame('2020-01-02 03:04:05', \Thelia\Model\ProductQuery::create()->findOneByRef('CSVIT-5')->getUpdatedAt('Y-m-d H:i:s'));
    }

    private function withPrices(array $prices): string
    {
        $rows = [self::HEADER];

        for ($number = 1; $number <= 10; ++$number) {
            $row = $this->fullRow($number, '10,50');
            $row[4] = $prices[(string) $number] ?? ($number <= 3 ? '99,90' : '10,50');
            $rows[] = $row;
        }

        return $this->csv($rows);
    }

    public function testAnImportThatCarriesOnlyThePriceLeavesTheOtherFieldsAlone(): void
    {
        $this->importCsv($this->tenProducts());

        $job = $this->importCsv($this->csv([['Référence', 'Prix HT'], ['CSVIT-4', '12,00']]), ImportMode::UpdateOnly);

        self::assertSame(1, $job->getUpdatedRows());
        self::assertSame(12.0, (float) $this->priceOf('CSVIT-4'));

        $product = $this->product('CSVIT-4');
        self::assertSame('Chaise numéro 4', $product->getTitle());
        self::assertSame('Courte 4', $product->getChapo());
        self::assertSame('Longue 4', $product->getDescription());
        self::assertNotNull($product->getBrandId());
        self::assertSame(CategoryQuery::create()->useCategoryI18nQuery()->filterByTitle('Cuisine CSV')->endUse()->findOne()->getId(), $product->getDefaultCategoryId());

        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertEqualsWithDelta(9.0, $pse->getQuantity(), 0.0001);
        self::assertEqualsWithDelta(1.25, $pse->getWeight(), 0.0001);
        self::assertSame($this->ean(4), $pse->getEanCode());
    }

    public function testAChangedFeatureValueReplacesTheOldOne(): void
    {
        $this->importCsv($this->csv([['Référence', 'Désignation', 'Prix HT', 'Famille', 'Gabarit', 'C:Couleur'], ['CSVIT-1', 'Chaise', '10', 'Maison CSV', 'Gabarit CSV', 'Rouge']]));

        $job = $this->importCsv($this->csv([['Référence', 'C:Couleur'], ['CSVIT-1', 'Bleu']]), ImportMode::UpdateOnly);

        self::assertSame(1, $job->getUpdatedRows());
        $values = FeatureProductQuery::create()->filterByProductId($this->product('CSVIT-1')->getId())->find();
        self::assertCount(1, $values);
        $values[0]->getFeatureAv()->setLocale($this->locale());
        self::assertSame('Bleu', $values[0]->getFeatureAv()->getTitle());

        $again = $this->importCsv($this->csv([['Référence', 'C:Couleur'], ['CSVIT-1', 'Bleu']]), ImportMode::UpdateOnly);
        self::assertSame(1, $again->getUnchangedRows());
    }

    public function testAnEmptyCellDoesNotEraseTheField(): void
    {
        $this->importCsv($this->tenProducts());

        $job = $this->importCsv($this->csv([['Référence', 'Description', 'Prix HT'], ['CSVIT-4', '', '10,50']]), ImportMode::UpdateOnly);

        self::assertSame(1, $job->getUnchangedRows());
        self::assertSame('Longue 4', $this->product('CSVIT-4')->getDescription());
    }

    public function testTwoWrongRowsAreNamedWithTheirLineAndTheOthersAreImported(): void
    {
        $rows = [self::HEADER];

        for ($number = 1; $number <= 10; ++$number) {
            $row = $this->fullRow($number);
            if (4 === $number) {
                $row[4] = 'douze euros';
            }
            if (7 === $number) {
                $row[0] = '';
            }
            $rows[] = $row;
        }

        $simulation = $this->importCsv($this->csv($rows), dryRun: true);
        self::assertSame(2, $simulation->getFailedRows());
        self::assertSame([5, 8], array_map(static fn ($line) => $line->getLineNumber(), $this->reportLines($simulation, 'failed')));

        $job = $this->importCsv($this->csv($rows));

        self::assertSame(8, $job->getCreatedRows());
        self::assertSame(2, $job->getFailedRows());
        self::assertNull($this->product('CSVIT-4'));
        self::assertNotNull($this->product('CSVIT-5'));
        $failed = $this->reportLines($job, 'failed');
        self::assertSame([5, 8], array_map(static fn ($line) => $line->getLineNumber(), $failed));
        self::assertSame('CSVIT-4', $failed[0]->getReference());
        self::assertStringContainsString('The reference is missing', $failed[1]->getMessage());
        self::assertStringContainsString('douze euros', $failed[0]->getMessage());
    }

    public function testUpdateOnlySkipsAMissingProductAndCreateOnlySkipsAnExistingOne(): void
    {
        $this->importCsv($this->csv([self::HEADER, $this->fullRow(1)]));

        $updateOnly = $this->importCsv($this->csv([self::HEADER, $this->fullRow(1), $this->fullRow(2)]), ImportMode::UpdateOnly);
        self::assertSame(1, $updateOnly->getSkippedRows());
        self::assertSame(1, $updateOnly->getUnchangedRows());
        self::assertNull($this->product('CSVIT-2'));

        $createOnly = $this->importCsv($this->csv([self::HEADER, $this->fullRow(1, '77'), $this->fullRow(2)]), ImportMode::CreateOnly);
        self::assertSame(1, $createOnly->getSkippedRows());
        self::assertSame(1, $createOnly->getCreatedRows());
        self::assertSame(10.5, (float) $this->priceOf('CSVIT-1'));
    }

    public function testAReferenceRepeatedInTheFileIsSkippedTheSecondTime(): void
    {
        $job = $this->importCsv($this->csv([self::HEADER, $this->fullRow(1), $this->fullRow(1, '77')]));

        self::assertSame(1, $job->getCreatedRows());
        self::assertSame(1, $job->getSkippedRows());
        self::assertSame(10.5, (float) $this->priceOf('CSVIT-1'));
    }

    public function testAnEmojiInATitleIsWrittenAsIs(): void
    {
        $row = $this->fullRow(1);
        $row[1] = 'Chaise 😀 spéciale';

        $this->importCsv($this->csv([self::HEADER, $row]));

        self::assertSame('Chaise 😀 spéciale', $this->product('CSVIT-1')->getTitle());
    }

    public function testEuropeanAndUsNumbersAreRead(): void
    {
        $first = $this->fullRow(1, '1 234,56');
        $second = $this->fullRow(2, '1.234,56');
        $third = $this->fullRow(3, '1,234.56');

        $this->importCsv($this->csv([self::HEADER, $first, $second, $third]));

        foreach (['CSVIT-1', 'CSVIT-2', 'CSVIT-3'] as $reference) {
            self::assertSame(1234.56, (float) $this->priceOf($reference));
        }
    }

    public function testAnAmbiguousNumberIsRefusedUnlessTheProfileSaysWhichMarkIsDecimal(): void
    {
        $row = $this->fullRow(1, '1.234');
        $row[6] = '1.25';
        $content = $this->csv([self::HEADER, $row]);

        $refused = $this->importCsv($content);
        self::assertSame(1, $refused->getFailedRows());

        $accepted = $this->importCsv($content, decimalSeparator: '.');
        self::assertSame(1, $accepted->getCreatedRows());
        self::assertSame(1.234, (float) $this->priceOf('CSVIT-1'));
    }

    public function testAProductToCreateNeedsATitleACategoryAndAPrice(): void
    {
        $job = $this->importCsv($this->csv([['Référence', 'Désignation', 'Prix HT', 'Famille'], ['CSVIT-1', '', '10', 'Maison CSV'], ['CSVIT-2', 'Chaise', '10', ''], ['CSVIT-3', 'Chaise', '', 'Maison CSV']]));

        self::assertSame(3, $job->getFailedRows());
        $messages = array_map(static fn ($line) => $line->getMessage(), $this->reportLines($job, 'failed'));
        self::assertStringContainsString('title', $messages[0]);
        self::assertStringContainsString('category', $messages[1]);
        self::assertStringContainsString('price', $messages[2]);
    }

    public function testAnInvalidEanRefusesTheLineWithItsReason(): void
    {
        $row = $this->fullRow(1);
        $row[10] = '12345';

        $job = $this->importCsv($this->csv([self::HEADER, $row]));

        self::assertSame(1, $job->getFailedRows());
        self::assertNull($this->product('CSVIT-1'));
        self::assertStringContainsString('12345', $this->reportLines($job, 'failed')[0]->getMessage());
    }

    public function testAProductWithCombinationsIsNotUpdatedByThisImport(): void
    {
        $this->importCsv($this->csv([self::HEADER, $this->fullRow(1)]));
        $product = $this->product('CSVIT-1');
        $this->createFixtureFactory()->productSaleElement($product, ['ref' => 'CSVIT-1-B']);

        $job = $this->importCsv($this->csv([['Référence', 'Prix HT'], ['CSVIT-1', '50']]), ImportMode::UpdateOnly);

        self::assertSame(1, $job->getFailedRows());
        self::assertSame(10.5, (float) $this->priceOf('CSVIT-1'));
    }

    public function testAFileWithAMissingReferenceColumnIsRefusedBeforeAnyRow(): void
    {
        $job = $this->importCsv($this->csv([['Désignation', 'Prix HT'], ['Chaise', '10']]));

        self::assertSame('failed', $job->getStatus());
        self::assertSame(0, $job->getProcessedRows());
        self::assertNull($this->product('CSVIT-1'));
    }

    public function testTheJobKeepsItsCountsAndTheReportOfWhatDidNotGoThroughCleanly(): void
    {
        $job = $this->importCsv($this->csv([self::HEADER, $this->fullRow(1), ['CSVIT-NO-TITLE', '', '', '', '', '', '', '', '', '', '', '']]));

        self::assertSame(2, $job->getTotalRows());
        self::assertSame(2, $job->getProcessedRows());
        self::assertSame(1, $job->getCreatedRows());
        self::assertSame(1, $job->getFailedRows());
        self::assertNotNull($job->getStartedAt());
        self::assertNotNull($job->getFinishedAt());
    }

    public function testTheCorrectedLineOfAFailedReferenceIsStillProcessed(): void
    {
        $wrong = $this->fullRow(1);
        $wrong[4] = 'douze euros';

        $job = $this->importCsv($this->csv([self::HEADER, $wrong, $this->fullRow(1, '12')]));

        self::assertSame(1, $job->getFailedRows());
        self::assertSame(1, $job->getCreatedRows());
        self::assertSame(0, $job->getSkippedRows());
        self::assertSame(12.0, (float) $this->priceOf('CSVIT-1'));
    }

    public function testAScriptInADescriptionNeverReachesTheDatabase(): void
    {
        $hostile = '<p>Texte <b>gras</b></p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">lien</a>';
        $rows = [['Référence', 'Désignation', 'Description courte', 'Description', 'Prix HT', 'Famille'], ['CSVIT-1', 'Chaise', $hostile, $hostile, '10', 'Maison CSV']];

        $this->importCsv($this->csv($rows));

        $product = $this->product('CSVIT-1');
        foreach ([$product->getDescription(), $product->getChapo()] as $html) {
            self::assertStringContainsString('<b>gras</b>', $html);
            self::assertStringNotContainsStringIgnoringCase('script', $html);
            self::assertStringNotContainsStringIgnoringCase('onerror', $html);
            self::assertStringNotContainsStringIgnoringCase('javascript:', $html);
        }

        $again = $this->importCsv($this->csv($rows), ImportMode::UpdateOnly);
        self::assertSame(1, $again->getUnchangedRows());
    }

    public function testAProfileWhoseColumnIsMissingFromTheFileIsRefusedBeforeAnyRow(): void
    {
        $mapping = new ImportMapping([
            'Référence' => new ColumnTarget(CatalogField::Reference),
            'Prix HT' => new ColumnTarget(CatalogField::Price),
        ]);
        $this->importCsv($this->csv([['Référence', 'Désignation', 'Prix HT', 'Famille'], ['CSVIT-1', 'Chaise', '10', 'Maison CSV']]));

        $job = $this->importCsv($this->csv([['Référence', 'Tarif HT'], ['CSVIT-1', '99']]), ImportMode::UpdateOnly, mapping: $mapping);

        self::assertSame('failed', $job->getStatus());
        self::assertSame(0, $job->getProcessedRows());
        self::assertSame(10.0, (float) $this->priceOf('CSVIT-1'));
        self::assertStringContainsString('Prix HT', $this->reportLines($job, 'failed')[0]->getMessage());
    }

    public function testAValueLongerThanTheDatabaseKeepsTheLineOutAndTheJobGoesOn(): void
    {
        $long = str_repeat('x', 300);
        $rows = [self::HEADER, $this->fullRow(1), $this->fullRow(2), $this->fullRow(3), $this->fullRow(4), $this->fullRow(5)];
        $rows[1][0] = $long;
        $rows[2][1] = $long;
        $rows[3][7] = 'Maison CSV > '.$long;
        $rows[4][11] = $long;

        $simulation = $this->importCsv($this->csv($rows), dryRun: true);
        $job = $this->importCsv($this->csv($rows));

        foreach ([$simulation, $job] as $result) {
            self::assertSame('done', $result->getStatus());
            self::assertSame(4, $result->getFailedRows());
            self::assertSame(1, $result->getCreatedRows());
        }

        self::assertNotNull($this->product('CSVIT-5'));
        self::assertCount(4, $this->reportLines($job, 'failed'));
        self::assertStringContainsString('255', $this->reportLines($job, 'failed')[0]->getMessage());
    }

    public function testACategoryNameCanHoldTheSeparatorWhenItIsEscaped(): void
    {
        $row = $this->fullRow(1);
        $row[7] = 'Maison CSV > Cuisine \\> Salon CSV';

        $this->importCsv($this->csv([self::HEADER, $row]));

        $category = CategoryQuery::create()->findPk($this->product('CSVIT-1')->getDefaultCategoryId());
        $category->setLocale($this->locale());
        self::assertSame('Cuisine > Salon CSV', $category->getTitle());
    }

    public function testAnUnclosedQuoteFailsTheJobBeforeAnyRowIsWritten(): void
    {
        $lines = [implode(';', ['Référence', 'Désignation', 'Prix HT', 'Famille']), 'CSVIT-1;Chaise;10;Maison CSV', 'CSVIT-2;"ouvert;10;Maison CSV'];
        for ($index = 3; $index <= 400; ++$index) {
            $lines[] = "CSVIT-$index;Chaise $index;10;Maison CSV";
        }

        $job = $this->importCsv(implode("\n", $lines)."\n", mapping: ImportMapping::fromProposal((new HeaderGuesser())->guess(['Référence', 'Désignation', 'Prix HT', 'Famille'])));

        self::assertSame('failed', $job->getStatus());
        self::assertNull($this->product('CSVIT-1'));
        self::assertStringContainsString('quote', $job->getError());
    }

    public function testAnImportRunningElsewhereRefusesARealRunButNotASimulation(): void
    {
        $lock = $this->locks()->createLock(CatalogImportRunner::LOCK_KEY);
        self::assertTrue($lock->acquire());

        try {
            $refused = $this->importCsv($this->csv([self::HEADER, $this->fullRow(1)]));
            $simulated = $this->importCsv($this->csv([self::HEADER, $this->fullRow(1)]), dryRun: true);
        } finally {
            $lock->release();
        }

        self::assertSame('failed', $refused->getStatus());
        self::assertSame(0, $refused->getProcessedRows());
        self::assertNull($this->product('CSVIT-1'));
        self::assertSame('done', $simulated->getStatus());

        $accepted = $this->importCsv($this->csv([self::HEADER, $this->fullRow(1)]));
        self::assertSame('done', $accepted->getStatus());
    }

    public function testAJobRunsOnceAndAnUnknownModeFailsInsteadOfStayingQueued(): void
    {
        $job = $this->importCsv($this->csv([self::HEADER, $this->fullRow(1)]));
        self::assertSame(1, $job->getCreatedRows());

        $this->runner()->run($job);
        $job->reload();
        self::assertSame('done', $job->getStatus());
        self::assertSame(1, $job->getCreatedRows());

        $bogus = (new ImportJobRepository())->create(null, '/nowhere.csv', 'x.csv', ImportMode::CreateOnly, false, null);
        $bogus->setMode('bogus')->save();
        $this->runner()->run($bogus);
        $bogus->reload();
        self::assertSame('failed', $bogus->getStatus());
    }

    public function testInstancePoolingIsOffDuringTheRunAndBackAfter(): void
    {
        Propel::enableInstancePooling();
        $during = [];

        $this->importCsv($this->csv([self::HEADER, $this->fullRow(1), $this->fullRow(2)]), onRow: static function () use (&$during): void {
            $during[] = Propel::isInstancePoolingEnabled();
        });

        self::assertSame([false, false], $during);
        self::assertTrue(Propel::isInstancePoolingEnabled());
    }

    public function testTheReportHoldsOneLinePerRowWhateverItsOutcome(): void
    {
        $created = $this->importCsv("Référence;Titre;Prix HT;Catégorie\nCSVIT-REPORT-1;Un;5,00;Rapport CSV\nCSVIT-REPORT-2;Deux;6,00;Rapport CSV\n");

        self::assertSame(['created', 'created'], array_map(static fn ($line): string => $line->getOutcome(), $this->reportLines($created, 'created')));

        $job = $this->importCsv("Référence;Titre;Prix HT;Catégorie\nCSVIT-REPORT-1;Un;5,00;Rapport CSV\nCSVIT-REPORT-2;Deux;7,00;Rapport CSV\nCSVIT-REPORT-3;Trois;pas un prix;Rapport CSV\n");

        $outcomes = [];

        foreach (['unchanged', 'updated', 'failed'] as $outcome) {
            foreach ($this->reportLines($job, $outcome) as $line) {
                $outcomes[$line->getLineNumber()] = $line->getOutcome();
            }
        }

        ksort($outcomes);
        self::assertSame([2 => 'unchanged', 3 => 'updated', 4 => 'failed'], $outcomes);
    }
}
