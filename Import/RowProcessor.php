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

namespace CsvImporter\Import;

use CsvImporter\Lookup\CatalogLookup;
use CsvImporter\Lookup\Resolved;
use CsvImporter\Mapping\CatalogField;
use CsvImporter\Mapping\CategoryPath;
use CsvImporter\Mapping\ImportMapping;
use CsvImporter\Parsing\InvalidNumberException;
use CsvImporter\Parsing\NumberParser;
use CsvImporter\Reader\SourceRow;
use CsvImporter\Report\Refusal;
use CsvImporter\Report\ReportMessage;
use Propel\Runtime\Propel;
use Thelia\Domain\Catalog\Product\DTO\ProductFeatureDTO;
use Thelia\Domain\Catalog\Product\DTO\ProductUpdateDTO;
use Thelia\Domain\Catalog\Product\DTO\ProductWithPSECreateDTO;
use Thelia\Domain\Catalog\Product\DTO\PSEUpdateDTO;
use Thelia\Domain\Catalog\Product\Identifier\Gtin;
use Thelia\Domain\Catalog\Product\ProductFacade;
use Thelia\Domain\Catalog\Product\PSEFacade;
use Thelia\Log\Tlog;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\Product;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;

final readonly class RowProcessor
{
    private const PRECISION = 6;
    private const MAX_NAME_LENGTH = 255;
    private const MAX_REASON_LENGTH = 300;

    public function __construct(
        private ProductFacade $products,
        private PSEFacade $saleElements,
        private NumberParser $numbers,
        private HtmlCleaner $htmlCleaner,
    ) {
    }

    public function process(SourceRow $row, ImportMapping $mapping, ImportOptions $options, CatalogLookup $lookup): RowResult
    {
        $reference = $this->text($row, $mapping, CatalogField::Reference);

        try {
            if (null !== $row->problem) {
                throw new Refusal(new ReportMessage($row->problem, $row->problemParameters));
            }

            if (null === $reference) {
                throw new Refusal(new ReportMessage('The reference is missing'));
            }

            $numbers = $this->readNumbers($row, $mapping, $options);
            $this->assertEan($row, $mapping);
            $this->assertLengths($row, $mapping);
            $product = ProductQuery::create()->findOneByRef($reference);

            if (null === $product && !$options->mode->allowsCreation()) {
                return new RowResult($row->lineNumber, RowOutcome::Skipped, $reference, new ReportMessage('The product does not exist and this import only updates'));
            }

            if (null !== $product && !$options->mode->allowsUpdate()) {
                return new RowResult($row->lineNumber, RowOutcome::Skipped, $reference, new ReportMessage('The product already exists and this import only creates'));
            }

            null === $product ? $this->assertCreatable($row, $mapping, $numbers) : $this->assertUpdatable($product, $numbers, $row, $mapping);
        } catch (Refusal $refusal) {
            return new RowResult($row->lineNumber, RowOutcome::Failed, $reference, $refusal->report);
        }

        $connection = $options->dryRun ? null : Propel::getWriteConnection(ProductTableMap::DATABASE_NAME);
        $connection?->beginTransaction();

        try {
            $result = $this->apply($row, $mapping, $options, $lookup, $reference, $numbers, $product);
            $connection?->commit();

            return $result->withCreations($lookup->commit());
        } catch (Refusal $refusal) {
            $this->abandon($connection, $lookup);

            return new RowResult($row->lineNumber, RowOutcome::Failed, $reference, $refusal->report);
        } catch (\Throwable $failure) {
            $this->abandon($connection, $lookup);
            Tlog::getInstance()->error(\sprintf('Catalog import, line %d: %s: %s', $row->lineNumber, $failure::class, $failure->getMessage()));

            return new RowResult($row->lineNumber, RowOutcome::Failed, $reference, new ReportMessage('The line could not be saved: %reason', ['%reason' => $this->reasonOf($failure)]));
        }
    }

    private function abandon(?object $connection, CatalogLookup $lookup): void
    {
        if (null !== $connection && $connection->inTransaction()) {
            $connection->rollBack();
        }

        $lookup->rollback();
    }

    private function apply(SourceRow $row, ImportMapping $mapping, ImportOptions $options, CatalogLookup $lookup, string $reference, array $numbers, ?Product $product): RowResult
    {
        $category = $this->resolveText($row, $mapping, CatalogField::Category, $lookup->category(...));
        $brand = $this->resolveText($row, $mapping, CatalogField::Brand, $lookup->brand(...));
        $template = $this->resolveText($row, $mapping, CatalogField::Template, $lookup->template(...));
        $features = $this->resolveFeatures($row, $mapping, $lookup);
        $warnings = [];

        if (null === $product) {
            if ($options->dryRun) {
                return new RowResult($row->lineNumber, RowOutcome::Created, $reference);
            }

            $product = ProductQuery::create()->findPk($this->products->createWithPSE(new ProductWithPSECreateDTO(
                ref: $reference,
                title: (string) $this->text($row, $mapping, CatalogField::Title),
                locale: $options->locale,
                defaultCategoryId: (int) $category->id,
                price: (float) $numbers['price'],
                currencyId: $options->currencyId,
                templateId: $template?->id,
            ))->getId());

            $this->update($product, $row, $mapping, $options, $lookup, $numbers, $category, $brand, $template, $features, $warnings);

            return new RowResult($row->lineNumber, RowOutcome::Created, $reference, null, $warnings);
        }

        $changed = $this->update($product, $row, $mapping, $options, $lookup, $numbers, $category, $brand, $template, $features, $warnings);

        return new RowResult($row->lineNumber, $changed ? RowOutcome::Updated : RowOutcome::Unchanged, $reference, null, $warnings);
    }

    private function update(Product $product, SourceRow $row, ImportMapping $mapping, ImportOptions $options, CatalogLookup $lookup, array $numbers, ?Resolved $category, ?Resolved $brand, ?Resolved $template, array $features, array &$warnings): bool
    {
        $write = !$options->dryRun;
        $product->setLocale($options->locale);
        $changed = false;

        $title = $this->text($row, $mapping, CatalogField::Title) ?? (string) $product->getTitle();
        $chapo = $this->htmlOrStored($this->html($row, $mapping, CatalogField::Chapo), $product->getChapo());
        $description = $this->htmlOrStored($this->html($row, $mapping, CatalogField::Description), $product->getDescription());
        $brandId = null === $brand ? $product->getBrandId() : $brand->id;
        $categoryId = null === $category ? $product->getDefaultCategoryId() : $category->id;

        $productChanged = $title !== (string) $product->getTitle()
            || $chapo !== $product->getChapo()
            || $description !== $product->getDescription()
            || (null !== $brand && ($brand->planned || $brand->id !== $product->getBrandId()))
            || (null !== $category && ($category->planned || $category->id !== $product->getDefaultCategoryId()));

        if ($productChanged) {
            $changed = true;

            if ($write) {
                $this->products->update($product->getId(), new ProductUpdateDTO(
                    ref: $product->getRef(),
                    title: $title,
                    locale: $options->locale,
                    defaultCategoryId: (int) $categoryId,
                    visible: (bool) $product->getVisible(),
                    virtual: (bool) $product->getVirtual(),
                    chapo: $chapo,
                    description: $description,
                    postscriptum: $product->getPostscriptum(),
                    brandId: $brandId,
                ));
            }
        }

        $templateId = $product->getTemplateId();

        if (null !== $template) {
            if (null === $templateId) {
                $changed = true;

                if ($write) {
                    $this->products->setTemplate($product->getId(), $template->id, $options->currencyId);
                    $templateId = $template->id;
                }
            } elseif ($template->planned || $template->id !== $templateId) {
                $warnings[] = new ReportMessage('The template of the product was kept: it already has another one');
            }
        }

        if ($this->updateSaleElement($product, $options, $row, $mapping, $numbers, $write)) {
            $changed = true;
        }

        if ($this->updateFeatures($product, $options, $lookup, $features, $templateId, $write)) {
            $changed = true;
        }

        return $changed;
    }

    private function updateSaleElement(Product $product, ImportOptions $options, SourceRow $row, ImportMapping $mapping, array $numbers, bool $write): bool
    {
        $ean = $this->text($row, $mapping, CatalogField::Ean);

        if (null === $numbers['price'] && null === $numbers['promoPrice'] && null === $numbers['weight'] && null === $numbers['stock'] && null === $ean) {
            return false;
        }

        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->filterByIsDefault(true)->findOne();

        if (null === $saleElement) {
            throw new Refusal(new ReportMessage('The product has no default sale element'));
        }

        $currentPrice = ProductPriceQuery::create()->filterByProductSaleElementsId($saleElement->getId())->filterByCurrencyId($options->currencyId)->findOne();
        $price = $numbers['price'] ?? (float) $currentPrice?->getPrice();
        $promoPrice = $numbers['promoPrice'] ?? (float) $currentPrice?->getPromoPrice();
        $onSale = null === $numbers['promoPrice'] ? (bool) $saleElement->getPromo() : $numbers['promoPrice'] > 0;
        $weight = $numbers['weight'] ?? (float) $saleElement->getWeight();
        $stock = $numbers['stock'] ?? (float) $saleElement->getQuantity();
        $ean = null === $ean ? $saleElement->getEanCode() : Gtin::normalize($ean);

        $changed = null === $currentPrice
            || !$this->same($price, (float) $currentPrice->getPrice())
            || !$this->same($promoPrice, (float) $currentPrice->getPromoPrice())
            || $onSale !== (bool) $saleElement->getPromo()
            || !$this->same($weight, (float) $saleElement->getWeight())
            || !$this->same($stock, (float) $saleElement->getQuantity())
            || $ean !== $saleElement->getEanCode();

        if ($changed && $write) {
            $this->saleElements->update($product->getId(), $saleElement->getId(), new PSEUpdateDTO(
                reference: $saleElement->getRef(),
                price: $price,
                currencyId: $options->currencyId,
                weight: $weight,
                quantity: $stock,
                salePrice: $promoPrice,
                onSale: $onSale,
                isNew: (bool) $saleElement->getNewness(),
                isDefault: true,
                eanCode: $ean,
                taxRuleId: (int) ProductQuery::create()->findPk($product->getId())->getTaxRuleId(),
            ));
        }

        return $changed;
    }

    private function updateFeatures(Product $product, ImportOptions $options, CatalogLookup $lookup, array $features, ?int $templateId, bool $write): bool
    {
        $changed = false;

        foreach ($features as [$feature, $values]) {
            $planned = $feature->planned || [] !== array_filter($values, static fn (Resolved $value): bool => $value->planned);
            $held = $planned
                ? []
                : FeatureProductQuery::create()->filterByProductId($product->getId())->filterByFeatureId($feature->id)->find()->toKeyValue('Id', 'FeatureAvId');
            $heldIds = array_map('intval', array_values($held));
            $wantedIds = array_values(array_unique(array_map(static fn (Resolved $value): int => (int) $value->id, $values)));
            sort($heldIds);
            sort($wantedIds);

            if (!$planned && $heldIds === $wantedIds) {
                continue;
            }

            $changed = true;

            if (!$write) {
                continue;
            }

            if (null !== $templateId) {
                $lookup->linkFeatureToTemplate($templateId, $feature->id);
            }

            if ([] !== $held) {
                $this->products->deleteFeatureValue($product->getId(), $feature->id);
            }

            foreach ($values as $value) {
                $this->products->setFeatureValue($product->getId(), new ProductFeatureDTO($feature->id, $value->id, false, $options->locale));
            }
        }

        return $changed;
    }

    private function assertCreatable(SourceRow $row, ImportMapping $mapping, array $numbers): void
    {
        $missing = [];

        foreach ([CatalogField::Title, CatalogField::Category] as $field) {
            if (null === $this->text($row, $mapping, $field)) {
                $missing[] = $field->value;
            }
        }

        if (null === $numbers['price']) {
            $missing[] = CatalogField::Price->value;
        }

        if ([] !== $missing) {
            throw new Refusal(new ReportMessage('A product to create needs a value in: %fields', ['%fields' => implode(', ', $missing)]));
        }
    }

    private function assertUpdatable(Product $product, array $numbers, SourceRow $row, ImportMapping $mapping): void
    {
        $touchesTheSaleElement = null !== $numbers['price'] || null !== $numbers['promoPrice'] || null !== $numbers['weight'] || null !== $numbers['stock'] || null !== $this->text($row, $mapping, CatalogField::Ean);

        if ($touchesTheSaleElement && ProductSaleElementsQuery::create()->filterByProductId($product->getId())->count() > 1) {
            throw new Refusal(new ReportMessage('The product has combinations, which this version does not update yet'));
        }
    }

    private function reasonOf(\Throwable $failure): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', $failure->getMessage()), 0, self::MAX_REASON_LENGTH);
    }

    private function assertLengths(SourceRow $row, ImportMapping $mapping): void
    {
        $checked = [];

        foreach ([CatalogField::Reference, CatalogField::Title, CatalogField::Brand, CatalogField::Template, CatalogField::Ean] as $field) {
            $checked[(string) $mapping->headerFor($field)] = $this->text($row, $mapping, $field);
        }

        foreach (CategoryPath::split((string) $this->text($row, $mapping, CatalogField::Category)) as $level) {
            $checked[(string) $mapping->headerFor(CatalogField::Category)] = $level;

            $this->assertShortEnough($checked);
            $checked = [];
        }

        $this->assertShortEnough($checked);

        foreach (array_keys($mapping->featureHeaders()) as $header) {
            foreach ($this->featureTitles($row, (string) $header) as $title) {
                $this->assertShortEnough([$header => $title]);
            }
        }
    }

    private function assertShortEnough(array $valuesByColumn): void
    {
        foreach ($valuesByColumn as $column => $value) {
            if (null !== $value && mb_strlen($value) > self::MAX_NAME_LENGTH) {
                throw new Refusal(new ReportMessage('The column "%column" holds more than %max characters', ['%column' => (string) $column, '%max' => self::MAX_NAME_LENGTH]));
            }
        }
    }

    private function assertEan(SourceRow $row, ImportMapping $mapping): void
    {
        $ean = $this->text($row, $mapping, CatalogField::Ean);

        if (null !== $ean && ('' === Gtin::normalize($ean) || null !== Gtin::violationOfTypedCode($ean))) {
            throw new Refusal(new ReportMessage('The code "%ean" of the column "%column" is not a valid EAN or GTIN', ['%ean' => $ean, '%column' => (string) $mapping->headerFor(CatalogField::Ean)]));
        }
    }

    private function readNumbers(SourceRow $row, ImportMapping $mapping, ImportOptions $options): array
    {
        $numbers = [];

        foreach (['price' => CatalogField::Price, 'promoPrice' => CatalogField::PromoPrice, 'weight' => CatalogField::Weight, 'stock' => CatalogField::Stock] as $name => $field) {
            $raw = $this->text($row, $mapping, $field);

            if (null === $raw) {
                $numbers[$name] = null;

                continue;
            }

            try {
                $numbers[$name] = (float) $this->numbers->parse($raw, $options->decimalSeparator);
            } catch (InvalidNumberException $invalid) {
                throw new Refusal(new ReportMessage($invalid->getMessage(), $invalid->parameters + ['%column' => (string) $mapping->headerFor($field)]));
            }

            if ($numbers[$name] < 0 && \in_array($field, [CatalogField::Price, CatalogField::PromoPrice, CatalogField::Weight], true)) {
                throw new Refusal(new ReportMessage('The column "%column" cannot hold a negative number', ['%column' => (string) $mapping->headerFor($field)]));
            }
        }

        return $numbers;
    }

    private function resolveText(SourceRow $row, ImportMapping $mapping, CatalogField $field, \Closure $resolve): ?Resolved
    {
        $text = $this->text($row, $mapping, $field);

        return null === $text ? null : $resolve($text);
    }

    private function resolveFeatures(SourceRow $row, ImportMapping $mapping, CatalogLookup $lookup): array
    {
        $features = [];

        foreach ($mapping->featureHeaders() as $header => $name) {
            $titles = $this->featureTitles($row, (string) $header);

            if ([] === $titles) {
                continue;
            }

            $feature = $lookup->feature($name);
            $features[] = [$feature, array_map(static fn (string $title): Resolved => $lookup->featureValue($name, $feature, $title), $titles)];
        }

        return $features;
    }

    /**
     * @return list<string>
     */
    private function featureTitles(SourceRow $row, string $header): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode('|', $row->cells[$header] ?? '')), static fn (string $title): bool => '' !== $title)));
    }

    private function htmlOrStored(?string $cleaned, ?string $stored): ?string
    {
        return null === $cleaned || (null !== $stored && trim($this->htmlCleaner->clean($stored)) === $cleaned) ? $stored : $cleaned;
    }

    private function html(SourceRow $row, ImportMapping $mapping, CatalogField $field): ?string
    {
        $text = $this->text($row, $mapping, $field);

        if (null === $text) {
            return null;
        }

        $clean = trim($this->htmlCleaner->clean($text));

        return '' === $clean ? null : $clean;
    }

    private function text(SourceRow $row, ImportMapping $mapping, CatalogField $field): ?string
    {
        $header = $mapping->headerFor($field);
        $value = null === $header ? '' : trim($row->cells[$header] ?? '');

        return '' === $value ? null : $value;
    }

    private function same(float $first, float $second): bool
    {
        return round($first, self::PRECISION) === round($second, self::PRECISION);
    }
}
