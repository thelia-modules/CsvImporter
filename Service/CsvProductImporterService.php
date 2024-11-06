<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CsvImporter\Service;

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Thelia\Core\Event\Attribute\AttributeAvCreateEvent;
use Thelia\Core\Event\Attribute\AttributeCreateEvent;
use Thelia\Core\Event\Brand\BrandCreateEvent;
use Thelia\Core\Event\Category\CategoryCreateEvent;
use Thelia\Core\Event\Feature\FeatureAvCreateEvent;
use Thelia\Core\Event\Feature\FeatureCreateEvent;
use Thelia\Core\Event\File\FileCreateOrUpdateEvent;
use Thelia\Core\Event\Product\ProductCreateEvent;
use Thelia\Core\Event\Product\ProductUpdateEvent;
use Thelia\Core\Event\ProductSaleElement\ProductSaleElementCreateEvent;
use Thelia\Core\Event\ProductSaleElement\ProductSaleElementUpdateEvent;
use Thelia\Core\Event\Tax\TaxEvent;
use Thelia\Core\Event\Tax\TaxRuleEvent;
use Thelia\Core\Event\Template\TemplateAddAttributeEvent;
use Thelia\Core\Event\Template\TemplateAddFeatureEvent;
use Thelia\Core\Event\Template\TemplateCreateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Files\FileManager;
use Thelia\Log\Tlog;
use Thelia\Model\Attribute;
use Thelia\Model\AttributeAv;
use Thelia\Model\AttributeAvQuery;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\AttributeQuery;
use Thelia\Model\AttributeTemplateQuery;
use Thelia\Model\Base\TemplateQuery;
use Thelia\Model\Brand;
use Thelia\Model\BrandQuery;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\Country;
use Thelia\Model\Feature;
use Thelia\Model\FeatureAv;
use Thelia\Model\FeatureAvQuery;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\FeatureQuery;
use Thelia\Model\FeatureTemplateQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsProductImageQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\TaxRule;
use Thelia\Model\TaxRuleCountryQuery;
use Thelia\Model\Template;
use Thelia\TaxEngine\TaxType\PricePercentTaxType;

class CsvProductImporterService
{
    public const LEVEL_1 = 'Niveau 1';
    public const LEVEL_4 = 'Niveau 4';
    public const ATTRIBUTE_DISCRIMINATOR = 'D:';
    public const FEATURE_DISCRIMINATOR = 'C:';

    public const REF_COLUMN = 'Référence Produit (Code)';

    private ?string $previousTaxRate = null;

    public function __construct(private EventDispatcherInterface $dispatcher, private FileManager $fileManager)
    {
    }

    /**
     * @throws PropelException
     * @throws \Exception
     */
    public function importProductsFromCsv($filePath, Country $country = null, string $locale = 'fr_FR'): void
    {
        if (null === $country) {
            $country = Country::getDefaultCountry();
        }
        $filesystem = new Filesystem();
        if (!$filesystem->exists($filePath)) {
            throw new \RuntimeException("File does not exists: $filePath");
        }

        if (($handle = fopen($filePath, 'r')) === false) {
            throw new \RuntimeException("Cannot open file: $filePath");
        }

        $headers = fgetcsv($handle, 1000, ',');
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            if (!$productData = array_combine($headers, $data)) {
                throw new \RuntimeException('Problem while combining headers and data.');
            }
            if (!$productData[self::REF_COLUMN]) {
                Tlog::getInstance()->addWarning('Missing Product reference');
                continue;
            }
            $product = $this->findOrCreateProduct(
                $productData,
                $country,
                $locale,
                $this->findOrCreateCategory($productData, $locale)
            );
            $this->createOrUpdateProductSaleElements($product, $productData, $locale);
            $this->addFeaturesToProduct($product, $productData, $locale);
        }

        fclose($handle);
        Tlog::getInstance()->addInfo('End of products import.');
    }

    /**
     * @throws \JsonException
     */
    private function findOrCreateTax(array $productData, Country $country, string $locale): TaxRule
    {
        $taxLabel = $productData['Règle de taxe'] ?: $this->previousTaxRate;
        $this->previousTaxRate = $taxLabel;
        $taxPercent = $this->extractTaxPercentage($taxLabel);
        $taxTitle = "TVA $taxLabel";

        $existingTaxRule = $this->findExistingTaxRule($taxTitle, $country);
        if ($existingTaxRule) {
            return $existingTaxRule;
        }

        $taxEvent = $this->createTax($locale, $taxTitle, $taxPercent);
        $taxRuleEvent = $this->createTaxRule($locale, $taxTitle, $country, $taxEvent->getTax()->getId());

        return $taxRuleEvent->getTaxRule();
    }

    private function extractTaxPercentage(string $taxLabel): string
    {
        preg_match('/\d+/', $taxLabel, $matches);

        return $matches[0];
    }

    private function findExistingTaxRule(string $taxTitle, Country $country): ?TaxRule
    {
        return TaxRuleCountryQuery::create()
            ->useTaxQuery()
            ->useTaxI18nQuery()
            ->filterByTitle($taxTitle)
            ->endUse()
            ->endUse()
            ->filterByCountry($country)
            ->findOne()?->getTaxRule();
    }

    private function createTax(string $locale, string $taxTitle, string $taxPercent): TaxEvent
    {
        $taxEvent = new TaxEvent();
        $ppe = (new PricePercentTaxType())->setPercentage($taxPercent);

        $taxEvent
            ->setLocale($locale)
            ->setTitle($taxTitle)
            ->setType($ppe::class)
            ->setRequirements($ppe->getRequirements());

        $this->dispatcher->dispatch($taxEvent, TheliaEvents::TAX_CREATE);

        if (!$taxEvent->getTax()) {
            throw new \RuntimeException('Tax must be present in the tax event');
        }

        $taxEvent->setId($taxEvent->getTax()->getId());
        Tlog::getInstance()->info('Created tax ID='.$taxEvent->getTax()->getId()." for tax $taxTitle");

        $this->dispatcher->dispatch($taxEvent, TheliaEvents::TAX_UPDATE);

        return $taxEvent;
    }

    private function createTaxRule(string $locale, string $taxTitle, Country $country, ?int $taxId): TaxRuleEvent
    {
        $taxRuleEvent = new TaxRuleEvent();

        $taxRuleEvent
            ->setLocale($locale)
            ->setTitle($taxTitle)
            ->setCountryList([$country->getId()])
            ->setTaxList(json_encode([$taxId], \JSON_THROW_ON_ERROR));

        $this->dispatcher->dispatch($taxRuleEvent, TheliaEvents::TAX_RULE_CREATE);
        $taxRuleEvent->setId($taxRuleEvent->getTaxRule()?->getId());

        $this->dispatcher->dispatch($taxRuleEvent, TheliaEvents::TAX_RULE_TAXES_UPDATE);

        Tlog::getInstance()->info('Created tax rule ID='.$taxRuleEvent->getTaxRule()?->getId()." for $taxTitle");

        $this->dispatcher->dispatch($taxRuleEvent, TheliaEvents::TAX_RULE_UPDATE);

        return $taxRuleEvent;
    }

    /**
     * @throws PropelException
     */
    private function findOrCreateCategory(array $productData, string $locale, Category $parent = null, string $level = self::LEVEL_1): Category
    {
        if ($level === self::LEVEL_4 || !$productData[$level]) {
            if (null === $parent) {
                throw new \RuntimeException('A product must have at least one category');
            }

            return $parent;
        }
        $category = CategoryQuery::create()
            ->filterByParent($parent ? $parent->getId() : 0)
            ->useI18nQuery($locale)
            ->filterByTitle($productData[$level])
            ->filterByLocale($locale)
            ->endUse()
            ->findOne();
        if (null === $category) {
            $createEvent = new CategoryCreateEvent();
            $createEvent
                ->setLocale($locale)
                ->setTitle($productData[$level])
                ->setParent($parent ? $parent->getId() : 0)
                ->setVisible(1);
            $this->dispatcher->dispatch($createEvent, TheliaEvents::CATEGORY_CREATE);
            $category = $createEvent->getCategory();
        }
        $this->findOrCreateCategory($productData, $locale, $category, $this->incrementLevel($level));

        return $category;
    }

    public function incrementLevel(string $level): string
    {
        if (preg_match('/(\d+)$/', $level, $matches)) {
            $newNumber = (int) $matches[1] + 1;

            return preg_replace('/\d+$/', $newNumber, $level);
        }
        throw new \RuntimeException('Missing level');
    }

    /**
     * @throws PropelException
     */
    private function findOrCreateProduct(array $productData, Country $country, string $locale, Category $category): Product
    {
        $product = ProductQuery::create()
            ->useProductI18nQuery()
            ->filterByTitle($productData['Titre du produit (Description)'])
            ->filterByLocale($locale)
            ->endUse()
            ->findOne();
        if ($product) {
            return $product;
        }

        $newProduct = $this->dispatchProductEvent(new ProductCreateEvent(), $productData, $locale, $category, $country, true);
        Tlog::getInstance()->addInfo('Produit créé : '.$productData['Titre du produit (Description)']);

        return $this->dispatchProductEvent(new ProductUpdateEvent($newProduct->getId()), $productData, $locale, $category, $country);
    }

    /**
     * @throws \JsonException|PropelException
     */
    private function dispatchProductEvent(ProductCreateEvent|ProductUpdateEvent $event, array $productData, string $locale, Category $category, Country $country, bool $isNew = false): Product
    {
        $event
            ->setRef($productData[self::REF_COLUMN])
            ->setLocale($locale)
            ->setTitle($productData['Titre du produit (Description)'])
            ->setDefaultCategory($category->getId())
            ->setBasePrice($productData['Prix du Produit HT'])
            ->setBaseWeight($productData['Poids'] ?? 0)
            ->setTaxRuleId($this->findOrCreateTax($productData, $country, $locale)->getId());

        if ($isNew) {
            $event->setVisible(1)
                ->setCurrencyId(1);
        }

        if ($event instanceof ProductUpdateEvent) {
            $event
                ->setChapo($productData['Description courte'])
                ->setDescription($productData['Description Longue'])
                ->setBrandId($this->findOrCreateBrand($productData, $locale)->getId());
        }
        $event = $this->dispatcher->dispatch($event, $isNew ? TheliaEvents::PRODUCT_CREATE : TheliaEvents::PRODUCT_UPDATE);
        /** @var Product $product */
        $product = $event->getProduct();
        $product->setTemplateId(
            $this->findOrCreateTemplate($productData[self::LEVEL_1], $locale)
                ->getId()
        );
        $product->save();

        return $product;
    }

    /**
     * @throws PropelException
     * @throws \Exception
     */
    private function createOrUpdateProductSaleElements(Product $product, array $productData, string $locale): ProductSaleElements
    {
        $productSaleElement = ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->filterByRef($productData[self::REF_COLUMN])
            ->findOne();
        $attributeAvList = [];
        $attributeAvListIds = [];
        foreach ($this->getAttributeColumns($productData) as $attributeColumn => $attributeTitle) {
            $attribute = $this->findOrCreateAttribute(substr($attributeColumn, 2), $locale);
            $attributeAv = $this->findOrCreateAttributeAv($attributeTitle, $attribute, $locale);
            $attributeAvList[] = $attributeAv;
            $attributeAvListIds[] = $attributeAv->getId();
            $this->addAttributeToTemplate($product->getTemplate(), $attribute);
        }
        if (!$productSaleElement) {
            $event = new ProductSaleElementCreateEvent($product, $attributeAvListIds, 1);
            $this->dispatcher->dispatch($event, TheliaEvents::PRODUCT_ADD_PRODUCT_SALE_ELEMENT);
            $productSaleElement = $event->getProductSaleElement();
        }
        if (\count($attributeAvList) > 0) {
            foreach ($attributeAvList as $attributeAv) {
                $attributeCombination = AttributeCombinationQuery::create()
                    ->filterByProductSaleElementsId($productSaleElement->getId())
                    ->filterByAttributeId($attributeAv->getAttribute()->getId())
                    ->filterByAttributeAvId($attributeAv->getId())
                    ->findOneOrCreate();
                $attributeCombination->save();
                $productSaleElement->addAttributeCombination($attributeCombination);
            }
        }
        $event = new ProductSaleElementUpdateEvent($product, $productSaleElement->getId());
        $event->setWeight($productData['Poids'])
            ->setProductSaleElement($productSaleElement)
            ->setPrice($productData['Prix du Produit HT'])
            ->setEanCode($productData['EAN'])
            ->setReference($productData[self::REF_COLUMN])
            ->setCurrencyId(1)
            ->setTaxRuleId($product->getTaxRuleId())
            ->setProduct($product);
        $this->dispatcher->dispatch($event, TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT);
        $this->addImages($product, $event->getProductSaleElement(), $productData);

        return $event->getProductSaleElement();
    }

    /**
     * @throws PropelException
     */
    private function addProductSaleElementImage(ProductSaleElements $productSaleElement, Product $product, string $fileName): void
    {
        $productImage = ProductImageQuery::create()
            ->filterByFile($fileName)
            ->filterByProductId($product->getId())
            ->findOne();
        if (!$productImage) {
            throw new \RuntimeException('Missing product image ID');
        }
        ProductSaleElementsProductImageQuery::create()
            ->filterByProductSaleElementsId($productSaleElement->getId())
            ->filterByProductImageId($productImage->getId())
            ->findOneOrCreate()
            ->save();
    }

    private function findOrCreateAttribute(string $attributeTitle, string $locale): Attribute
    {
        $attribute = AttributeQuery::create()
            ->useAttributeI18nQuery()
            ->filterByTitle($attributeTitle)
            ->filterByLocale($locale)
            ->endUse()
            ->findOne();
        if ($attribute) {
            return $attribute;
        }

        $event = new AttributeCreateEvent();
        $event->setTitle($attributeTitle)
            ->setLocale($locale);
        $this->dispatcher->dispatch($event, TheliaEvents::ATTRIBUTE_CREATE);

        return $event->getAttribute();
    }

    private function findOrCreateAttributeAv(string $attributeAvTitle, Attribute $attribute, string $locale): AttributeAv
    {
        $attributeAv = AttributeAvQuery::create()
            ->useAttributeAvI18nQuery()
            ->filterByTitle($attributeAvTitle)
            ->filterByLocale($locale)
            ->endUse()
            ->findOne();

        if ($attributeAv) {
            return $attributeAv;
        }
        $event = new AttributeAvCreateEvent();
        $event->setTitle($attributeAvTitle)
            ->setLocale($locale)
            ->setAttributeId($attribute->getId());

        $this->dispatcher->dispatch($event, TheliaEvents::ATTRIBUTE_AV_CREATE);

        return $event->getAttributeAv();
    }

    private function findOrCreateBrand(array $productData, string $locale): Brand
    {
        $brandTitle = $productData['Marque'];
        $brand = BrandQuery::create()
            ->useBrandI18nQuery()
            ->filterByTitle($brandTitle)
            ->endUse()
            ->findOne();
        if ($brand) {
            return $brand;
        }
        $event = new BrandCreateEvent();
        $event
            ->setLocale($locale)
            ->setTitle($brandTitle)
            ->setVisible(1);
        $this->dispatcher->dispatch($event, TheliaEvents::BRAND_CREATE);

        return $event->getBrand();
    }

    private function findOrCreateTemplate(string $attributeName, string $locale): Template
    {
        $template = TemplateQuery::create()
            ->useTemplateI18nQuery()
            ->filterByName($attributeName)
            ->filterByLocale($locale)
            ->endUse()
            ->findOne();
        if ($template) {
            return $template;
        }
        $event = new TemplateCreateEvent();
        $event->setLocale($locale)
            ->setTemplateName($attributeName);
        $this->dispatcher->dispatch($event, TheliaEvents::TEMPLATE_CREATE);

        return $event->getTemplate();
    }

    /**
     * @throws PropelException
     */
    private function addAttributeToTemplate(Template $template, Attribute $attribute): void
    {
        $attributeTemplate = AttributeTemplateQuery::create()
            ->filterByAttributeId($attribute->getId())
            ->filterByTemplateId($template->getId())
            ->findOne();
        if ($attributeTemplate) {
            return;
        }
        $event = new TemplateAddAttributeEvent($template, $attribute->getId());
        $this->dispatcher->dispatch($event, TheliaEvents::TEMPLATE_ADD_ATTRIBUTE);
    }

    /**
     * @throws PropelException
     */
    private function addFeaturesToProduct(Product $product, array $productData, string $locale): void
    {
        foreach ($this->getFeatureColumns($productData) as $featureColumn => $featureTitle) {
            $feature = $this->findOrCreateFeature(substr($featureColumn, 2), $locale);
            $featureAv = $this->findOrCreateFeatureAv($featureTitle, $feature, $locale);
            $this->addFeatureToTemplate($product->getTemplate(), $feature);
            $featureProduct = FeatureProductQuery::create()
                ->filterByProductId($product->getId())
                ->filterByFeatureId($feature->getId())
                ->filterByFeatureAvId($featureAv->getId())
                ->findOneOrCreate();
            $featureProduct->save();
        }
    }

    private function findOrCreateFeature(string $featureTitle, string $locale): Feature
    {
        $feature = FeatureQuery::create()
            ->useFeatureI18nQuery()
            ->filterByTitle($featureTitle)
            ->filterByLocale($locale)
            ->endUse()
            ->findOne();
        if ($feature) {
            return $feature;
        }

        $event = new FeatureCreateEvent();
        $event->setTitle($featureTitle)
            ->setLocale($locale);
        $this->dispatcher->dispatch($event, TheliaEvents::FEATURE_CREATE);

        return $event->getFeature();
    }

    private function getColumns(array $productData, string $discriminator): array
    {
        $filteredData = array_filter(array_keys($productData), static fn ($key) => str_starts_with($key, $discriminator));

        return array_intersect_key($productData, array_flip($filteredData));
    }

    private function getFeatureColumns(array $productData): array
    {
        return $this->getColumns($productData, self::FEATURE_DISCRIMINATOR);
    }

    private function getAttributeColumns(array $productData): array
    {
        return $this->getColumns($productData, self::ATTRIBUTE_DISCRIMINATOR);
    }

    private function addFeatureToTemplate(Template $template, Feature $feature): void
    {
        $featureTemplate = FeatureTemplateQuery::create()
            ->filterByFeatureId($feature->getId())
            ->filterByTemplateId($template->getId())
            ->findOne();
        if ($featureTemplate) {
            return;
        }
        $event = new TemplateAddFeatureEvent($template, $feature->getId());
        $this->dispatcher->dispatch($event, TheliaEvents::TEMPLATE_ADD_FEATURE);
    }

    private function findOrCreateFeatureAv(string $featureAvTitle, Feature $feature, string $locale): FeatureAv
    {
        $featureAv = FeatureAvQuery::create()
            ->useFeatureAvI18nQuery()
            ->filterByTitle($featureAvTitle)
            ->filterByLocale($locale)
            ->endUse()
            ->findOne();

        if ($featureAv) {
            return $featureAv;
        }
        $event = new FeatureAvCreateEvent();
        $event->setTitle($featureAvTitle)
            ->setLocale($locale)
            ->setFeatureId($feature->getId());

        $this->dispatcher->dispatch($event, TheliaEvents::FEATURE_AV_CREATE);

        return $event->getFeatureAv();
    }

    /**
     * @throws \Exception
     */
    private function addImages(Product $product, ProductSaleElements $productSaleElements, array $productData): void
    {
        $filePath = $productData['IMG'];
        if (!$filePath) {
            return;
        }
        $fileName = basename($filePath);

        $productImage = ProductImageQuery::create()
            ->filterByProductId($product->getId())
            ->filterByFile($fileName)
            ->findOneOrCreate();
        if (!$productImage->isNew() && file_exists($filePath) && is_file($filePath)) {
            $this->fileManager->deleteFile($filePath);
        }
        $uploadedFile = new UploadedFile($filePath, $fileName);
        $event = new FileCreateOrUpdateEvent($product->getId());
        $event->setModel($productImage)
            ->setUploadedFile($uploadedFile);
        $this->dispatcher->dispatch($event, TheliaEvents::IMAGE_SAVE);
        $this->addProductSaleElementImage($productSaleElements, $product, $event->getUploadedFile()->getFilename());
    }
}
