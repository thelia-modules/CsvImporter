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

use Thelia\Model\BrandQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ProductQuery;
use Thelia\Test\IntegrationTestCase;

final class CatalogImportRollbackTest extends IntegrationTestCase
{
    use ImportTestSupport;

    protected bool $useTransaction = false;

    private ?\Closure $failure = null;

    protected function tearDown(): void
    {
        if (null !== $this->failure) {
            static::getContainer()->get('event_dispatcher')->removeListener(\Thelia\Core\Event\TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT, $this->failure);
        }

        foreach (ProductQuery::create()->filterByRef('CSVRB-%')->find() as $product) {
            static::getContainer()->get(\Thelia\Domain\Catalog\Product\ProductFacade::class)->delete($product->getId());
        }

        foreach (CategoryQuery::create()->useCategoryI18nQuery()->filterByTitle('Rollback %', \Propel\Runtime\ActiveQuery\Criteria::LIKE)->endUse()->orderById('desc')->find() as $category) {
            $category->delete();
        }

        foreach (BrandQuery::create()->useBrandI18nQuery()->filterByTitle('Rollback %', \Propel\Runtime\ActiveQuery\Criteria::LIKE)->endUse()->find() as $brand) {
            $brand->delete();
        }

        \CsvImporter\Model\CsvImporterJobQuery::create()->filterBySourceName('file.csv')->delete();
        \CsvImporter\Model\CsvImporterProfileQuery::create()->filterByName('it-%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)->delete();

        parent::tearDown();
    }

    private function rows(string $featureValue): string
    {
        return $this->csv([
            ['Référence', 'Désignation', 'Prix HT', 'Famille', 'Marque', 'C:Matière'],
            ['CSVRB-1', 'Premier', '10', 'Rollback A > Rollback B', 'Rollback Marque', $featureValue],
            ['CSVRB-2', 'Second', '20', 'Rollback A > Rollback B', 'Rollback Marque', 'Bois'],
        ]);
    }

    public function testARowThatFailsMidWayLeavesNoHalfProduct(): void
    {
        $job = $this->importCsv($this->rows(str_repeat('x', 400)));

        self::assertSame(1, $job->getFailedRows());
        self::assertSame(1, $job->getCreatedRows());
        self::assertNull($this->product('CSVRB-1'));
        self::assertNotNull($this->product('CSVRB-2'));
    }

    public function testWhatAFailedRowCreatedIsNotRememberedForTheNextOne(): void
    {
        $job = $this->importCsv($this->rows(str_repeat('x', 400)));

        self::assertSame(1, $job->getCreatedRows());
        $second = $this->product('CSVRB-2');
        self::assertNotNull($second);
        self::assertNotNull($second->getBrandId());
        self::assertSame(1, BrandQuery::create()->useBrandI18nQuery()->filterByTitle('Rollback Marque')->endUse()->count());
        self::assertSame(1, CategoryQuery::create()->useCategoryI18nQuery()->filterByTitle('Rollback B')->endUse()->count());
        self::assertSame($second->getDefaultCategoryId(), CategoryQuery::create()->useCategoryI18nQuery()->filterByTitle('Rollback B')->endUse()->findOne()->getId());
    }

    public function testAFailureAfterTheProductWasInsertedLeavesNothingOfIt(): void
    {
        $this->failure = static function (\Thelia\Core\Event\ProductSaleElement\ProductSaleElementUpdateEvent $event): void {
            if ('CSVRB-1' === $event->getProduct()->getRef()) {
                throw new \RuntimeException('Simulated failure after the product was inserted');
            }
        };
        static::getContainer()->get('event_dispatcher')->addListener(\Thelia\Core\Event\TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT, $this->failure, 100);

        $job = $this->importCsv($this->rows('Bois'));

        self::assertSame(1, $job->getFailedRows());
        self::assertSame(1, $job->getCreatedRows());
        self::assertNull($this->product('CSVRB-1'));
        self::assertStringContainsString('Simulated failure after the product was inserted', $this->reportLines($job, 'failed')[0]->getMessage());
        self::assertSame(0, ProductQuery::create()->filterByRef('CSVRB-1')->count());
        $second = $this->product('CSVRB-2');
        self::assertNotNull($second);
        self::assertSame(1, CategoryQuery::create()->useCategoryI18nQuery()->filterByTitle('Rollback B')->endUse()->count());
        self::assertSame(1, BrandQuery::create()->useBrandI18nQuery()->filterByTitle('Rollback Marque')->endUse()->count());
        self::assertSame($second->getDefaultCategoryId(), CategoryQuery::create()->useCategoryI18nQuery()->filterByTitle('Rollback B')->endUse()->findOne()->getId());
    }
}
