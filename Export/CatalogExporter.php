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

namespace CsvImporter\Export;

use CsvImporter\Mapping\CategoryPath;
use CsvImporter\Reader\CsvFormula;
use Propel\Runtime\Propel;
use Thelia\Model\BrandQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\FeatureAvQuery;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\FeatureQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\TemplateQuery;

final class CatalogExporter
{
    private const CHUNK = 200;

    private array $categories = [];
    private array $brands = [];
    private array $templates = [];
    private array $featureValues = [];

    /**
     * @return array{exported: int, withCombinations: int}
     */
    public function export(string $path, string $locale, int $currencyId): array
    {
        $handle = fopen($path, 'w');

        if (false === $handle) {
            throw new \RuntimeException('The export file cannot be written');
        }

        $poolingWasEnabled = Propel::isInstancePoolingEnabled();
        Propel::disableInstancePooling();
        $this->categories = $this->brands = $this->templates = $this->featureValues = [];

        try {
            $features = $this->features($locale);
            fwrite($handle, "\xEF\xBB\xBF");
            $this->write($handle, [...self::FIXED_HEADERS, ...array_map(static fn (string $title): string => 'C:'.$title, array_values($features))]);

            $exported = $withCombinations = 0;
            $lastId = 0;

            while (true) {
                $products = ProductQuery::create()->filterById($lastId, \Propel\Runtime\ActiveQuery\Criteria::GREATER_THAN)->orderById()->limit(self::CHUNK)->find();

                if (0 === $products->count()) {
                    break;
                }

                foreach ($products as $product) {
                    $lastId = $product->getId();

                    if (ProductSaleElementsQuery::create()->filterByProductId($product->getId())->count() > 1) {
                        ++$withCombinations;

                        continue;
                    }

                    $this->write($handle, $this->line($product, $locale, $currencyId, array_keys($features)));
                    ++$exported;
                }
            }
        } finally {
            fclose($handle);
            $poolingWasEnabled && Propel::enableInstancePooling();
        }

        return ['exported' => $exported, 'withCombinations' => $withCombinations];
    }

    private const FIXED_HEADERS = ['Référence', 'Titre', 'Description courte', 'Description', 'Prix HT', 'Prix promo', 'Stock', 'Poids', 'Catégorie', 'Marque', 'Gabarit', 'EAN'];

    private function features(string $locale): array
    {
        $ids = FeatureProductQuery::create()->select('FeatureId')->distinct()->find()->getData();
        $titles = [];

        foreach (FeatureQuery::create()->filterById($ids)->orderById()->find() as $feature) {
            $titles[$feature->getId()] = trim((string) $feature->setLocale($locale)->getTitle());
        }

        return array_filter($titles, static fn (string $title): bool => '' !== $title);
    }

    private function line(Product $product, string $locale, int $currencyId, array $featureIds): array
    {
        $product->setLocale($locale);
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->filterByIsDefault(true)->findOne();
        $price = null === $saleElement ? null : ProductPriceQuery::create()->filterByProductSaleElementsId($saleElement->getId())->filterByCurrencyId($currencyId)->findOne();

        $line = [
            $product->getRef(),
            (string) $product->getTitle(),
            (string) $product->getChapo(),
            (string) $product->getDescription(),
            null === $price ? '' : $this->number($price->getPrice()),
            null !== $price && $saleElement->getPromo() ? $this->number($price->getPromoPrice()) : '',
            null === $saleElement ? '' : $this->number((string) $saleElement->getQuantity()),
            null === $saleElement ? '' : $this->number((string) $saleElement->getWeight()),
            $this->categoryPath($product->getDefaultCategoryId(), $locale),
            $this->brand($product->getBrandId(), $locale),
            $this->template($product->getTemplateId(), $locale),
            (string) $saleElement?->getEanCode(),
        ];

        $held = FeatureProductQuery::create()->filterByProductId($product->getId())->orderById()->find();
        $values = [];

        foreach ($held as $featureProduct) {
            $values[$featureProduct->getFeatureId()][] = $featureProduct->getFeatureAvId();
        }

        foreach ($featureIds as $featureId) {
            $line[] = isset($values[$featureId]) ? implode('|', array_filter(array_map(fn (int $valueId): string => $this->featureValue($valueId, $locale), $values[$featureId]), static fn (string $title): bool => '' !== $title)) : '';
        }

        return array_map(static fn ($cell): string => (string) $cell, $line);
    }

    private function categoryPath(?int $id, string $locale): string
    {
        return $this->categories[$id] ??= null === $id ? '' : CategoryPath::join($this->categoryLevels($id, $locale));
    }

    private function categoryLevels(int $id, string $locale): array
    {
        $levels = [];

        for ($guard = 0; null !== $id && 0 !== $id && $guard < 50; ++$guard) {
            $category = CategoryQuery::create()->findPk($id);

            if (null === $category) {
                break;
            }

            array_unshift($levels, trim((string) $category->setLocale($locale)->getTitle()));
            $id = $category->getParent();
        }

        return array_values(array_filter($levels, static fn (string $level): bool => '' !== $level));
    }

    private function brand(?int $id, string $locale): string
    {
        return $this->brands[$id] ??= null === $id ? '' : (string) BrandQuery::create()->findPk($id)?->setLocale($locale)->getTitle();
    }

    private function template(?int $id, string $locale): string
    {
        return $this->templates[$id] ??= null === $id ? '' : (string) TemplateQuery::create()->findPk($id)?->setLocale($locale)->getName();
    }

    private function featureValue(int $id, string $locale): string
    {
        return $this->featureValues[$id] ??= (string) FeatureAvQuery::create()->findPk($id)?->setLocale($locale)->getTitle();
    }

    private function number(string $value): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }

        [$whole, $fraction] = array_pad(explode('.', rtrim(rtrim($value, '0'), '.'), 2), 2, '');

        return '' === $fraction ? $whole : $whole.','.(3 === \strlen($fraction) ? $fraction.'0' : $fraction);
    }

    private function write($handle, array $cells): void
    {
        fputcsv($handle, array_map(CsvFormula::protect(...), $cells), ';', '"', '', "\n");
    }
}
