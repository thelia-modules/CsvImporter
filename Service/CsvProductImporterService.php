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
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
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
    public const REF_COLUMN = 'product_reference';
    public const TITLE_COLUMN = 'product_title';
    public const FAMILY_COLUMN = 'family';
    public const SUB_FAMILY_COLUMN = 'sub_family';
    public const BRAND_COLUMN = 'brand';
    public const LEVEL1_COLUMN = 'level_1';
    public const LEVEL2_COLUMN = 'level_2';
    public const LEVEL3_COLUMN = 'level_3';
    public const LEVEL4_COLUMN = 'level_4';
    public const TAX_RULE_COLUMN = 'tax_rule';
    public const PRICE_EXCL_TAX_COLUMN = 'price_excl_tax';
    public const PRICE_INCL_TAX_COLUMN = 'price_incl_tax';
    public const WEIGHT_COLUMN = 'weight';
    public const EAN_COLUMN = 'ean';
    public const SHORT_DESCRIPTION_COLUMN = 'short_description';
    public const LONG_DESCRIPTION_COLUMN = 'long_description';
    public const IMAGE_COLUMN = 'image';

    public const FEATURES = 'features';
    public const ATTRIBUTES = 'attributes';

    private ?string $previousTaxRate = null;

    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private FileManager              $fileManager,
        private CsvParserService         $csvParser
    ) {
    }

    public function importFromDirectory(string $path, ?OutputInterface $output = null): bool
    {
        $finder = Finder::create()
            ->files()
            ->in($path)
            ->ignoreDotFiles(true)
            ->depth(0)
            ->name('*.csv')
        ;

        $output?->writeln("<info>Fetching directory $path</info>");

        $count = $errors = 0;

        $success = true;

        foreach ($finder->getIterator() as $file) {
            Tlog::getInstance()->info("Importation du fichier $file");

            $output?->writeln("<info>Starting to import  : ".$file->getBasename()."</info>");

            $count++;

            try {
                $this->importProductsFromCsv($file->getPathname(), $path);

                $output?->writeln('<info>Import is a success !</info>');

                Tlog::getInstance()->info("Fichier $file importé.");
            } catch (\Exception $e) {
                Tlog::getInstance()->addError("Erreur lors de l'importation de $file : ".$e->getMessage());
                $output?->writeln('<error>Error : '.$e->getMessage().'</error>');

                if ($e->getPrevious()) {
                    $output?->writeln('<error>Caused by : '.$e->getPrevious()->getMessage().'</error>');
                }

                $success = false;

                $errors++;
            }
        }

        Tlog::getInstance()->info("$count fichiers(s) traités, $errors erreur(s).");

        $output?->writeln("<info>$count file(s) processed, $errors error(s).</info>");

        return $success;
    }

    /**
     * @throws PropelException
     * @throws \Exception
     */
    public function importProductsFromCsv(string $filePath, string $basedir, Country $country = null, string $locale = 'fr_FR'): void
    {
        if (null === $country) {
            $country = Country::getDefaultCountry();
        }
        $filesystem = new Filesystem();
        if (!$filesystem->exists($filePath)) {
            throw new \RuntimeException("File does not exists: $filePath");
        }

        if (($handle = fopen($filePath, 'rb')) === false) {
            throw new \RuntimeException("Cannot open file: $filePath");
        }

        $line = 0;

        $headers = fgetcsv($handle);
        while (($data = fgetcsv($handle)) !== false) {
            $line++;

            if (!$productData = array_combine($headers, $data)) {
                throw new \RuntimeException('Problem while combining headers and data.');
            }
            $productData = $this->csvParser->mapToArray($productData);

            if (!$productData[self::REF_COLUMN]) {
                Tlog::getInstance()->addWarning("Line $line: Missing Product reference");
                continue;
            }
            if (!$productData[self::TITLE_COLUMN]) {
                Tlog::getInstance()->addWarning("Line $line: Missing Product title");
                continue;
            }
            if (!$productData[self::LEVEL1_COLUMN]) {
                Tlog::getInstance()->addWarning("Line $line: Missing Product category");
                continue;
            }
            if (!$productData[self::TAX_RULE_COLUMN]) {
                Tlog::getInstance()->addWarning("Line $line: Missing Product tax rule");
                continue;
            }
            if (!$productData[self::PRICE_EXCL_TAX_COLUMN]) {
                Tlog::getInstance()->addWarning("Line $line: Missing Product price for:" . $productData[self::REF_COLUMN]);
                continue;
            }
            $product = $this->findOrCreateProduct(
                $productData,
                $country,
                $locale,
                $this->findOrCreateCategory($productData, $locale)
            );
            $this->createOrUpdateProductSaleElements($product, $productData, $locale, $basedir);
            $this->addFeaturesToProduct($product, $productData, $locale);
        }

        fclose($handle);
    }

    /**
     * @throws \JsonException
     */
    private function findOrCreateTax(array $productData, Country $country, string $locale): TaxRule
    {
        $taxLabel = $productData[self::TAX_RULE_COLUMN] ?: $this->previousTaxRate;
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
        Tlog::getInstance()->info('Created tax ID=' . $taxEvent->getTax()->getId() . " for tax $taxTitle");

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

        Tlog::getInstance()->info('Created tax rule ID=' . $taxRuleEvent->getTaxRule()?->getId() . " for $taxTitle");

        $this->dispatcher->dispatch($taxRuleEvent, TheliaEvents::TAX_RULE_UPDATE);

        return $taxRuleEvent;
    }

    /**
     * @throws PropelException
     */
    private function findOrCreateCategory(array $productData, string $locale, Category $parent = null, string $level = self::LEVEL1_COLUMN): Category
    {
        if ($level === self::LEVEL4_COLUMN || !$productData[$level]) {
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

            Tlog::getInstance()->info('Created category ' . $productData[$level]);
        }
        return $this->findOrCreateCategory($productData, $locale, $category, $this->incrementLevel($level));
    }

    public function incrementLevel(string $level): string
    {
        if (preg_match('/(\d+)$/', $level, $matches)) {
            $newNumber = (int)$matches[1] + 1;

            return preg_replace('/\d+$/', $newNumber, $level);
        }
        throw new \RuntimeException('Missing level');
    }

    /**
     * @throws PropelException
     * @throws \JsonException
     */
    private function findOrCreateProduct(array $productData, Country $country, string $locale, Category $category): Product
    {
        $product = ProductQuery::create()
            ->useProductI18nQuery()
            ->filterByTitle($productData[self::TITLE_COLUMN])
            ->filterByLocale($locale)
            ->endUse()
            ->findOne();
        if ($product) {
            return $product;
        }

        $newProduct = $this->dispatchProductEvent(new ProductCreateEvent(), $productData, $locale, $category, $country, true);
        Tlog::getInstance()->addInfo('Created product ' . $productData[self::TITLE_COLUMN]);

        return $this->dispatchProductEvent(new ProductUpdateEvent($newProduct->getId()), $productData, $locale, $category, $country);
    }

    /**
     * @throws \JsonException|PropelException
     */
    private function dispatchProductEvent(
        ProductCreateEvent|ProductUpdateEvent $event,
        array $productData,
        string $locale,
        Category $category,
        Country $country,
        bool $isNew = false
    ): Product {
        $event
            ->setRef($productData[self::REF_COLUMN])
            ->setLocale($locale)
            ->setTitle($productData[self::TITLE_COLUMN])
            ->setDefaultCategory($category->getId())
            ->setBasePrice($productData[self::PRICE_EXCL_TAX_COLUMN])
            ->setBaseWeight($productData[self::WEIGHT_COLUMN] ?? 0)
            ->setTaxRuleId($this->findOrCreateTax($productData, $country, $locale)->getId())
            ->setVisible(1)
            ->setCurrencyId(1);

        if ($event instanceof ProductUpdateEvent) {
            $event
                ->setChapo($productData[self::SHORT_DESCRIPTION_COLUMN])
                ->setDescription($productData[self::LONG_DESCRIPTION_COLUMN])
                ;

            if (null !== $brand = $this->findOrCreateBrand($productData, $locale)) {
                $event->setBrandId($brand->getId());
            }
        }

        $this->dispatcher->dispatch(
            $event,
            $isNew ? TheliaEvents::PRODUCT_CREATE : TheliaEvents::PRODUCT_UPDATE
        );

        /** @var Product $product */
        $product = $event->getProduct();
        $product->setTemplateId(
            $this->findOrCreateTemplate($productData[self::LEVEL1_COLUMN], $locale)
                ->getId()
        );

        $product->save();

        return $product;
    }

    /**
     * @throws PropelException
     * @throws \Exception
     */
    private function createOrUpdateProductSaleElements(Product $product, array $productData, string $locale, string $baseDir): ProductSaleElements
    {
        $productSaleElement = ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->filterByRef($productData[self::REF_COLUMN])
            ->findOne();
        $attributeAvList = [];
        $attributeAvListIds = [];
        foreach ($productData[self::ATTRIBUTES] as $attributeColumn => $attributeTitle) {
            $attribute = $this->findOrCreateAttribute($attributeColumn, $locale);
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
            }
        }
        $event = new ProductSaleElementUpdateEvent($product, $productSaleElement->getId());
        $event->setWeight($productData[self::WEIGHT_COLUMN])
            ->setProductSaleElement($productSaleElement)
            ->setPrice($productData[self::PRICE_EXCL_TAX_COLUMN])
            ->setEanCode($productData[self::EAN_COLUMN])
            ->setReference($productData[self::REF_COLUMN])
            ->setCurrencyId(1)
            ->setTaxRuleId($product->getTaxRuleId())
            ->setProduct($product);
        $this->dispatcher->dispatch($event, TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT);

        if ($productData[self::IMAGE_COLUMN]) {
            $this->addImages($product, $event->getProductSaleElement(), $productData, $baseDir);
        }

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

    private function findOrCreateBrand(array $productData, string $locale): ?Brand
    {
        $brandTitle = $productData[self::BRAND_COLUMN];

        if (empty($brandTitle)) {
            Tlog::getInstance()->warning("Brand without title for product reference " . $productData[self::REF_COLUMN]);
            return null;
        }

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
        foreach ($productData[self::FEATURES] as $featureColumn => $featureTitle) {
            if (!$featureTitle) {
                continue;
            }
            $feature = $this->findOrCreateFeature($featureColumn, $locale);
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
    private function addImages(Product $product, ProductSaleElements $productSaleElements, array $productData, $baseDir): void
    {
        if (0 === stripos($productData[self::IMAGE_COLUMN], 'http')) {
            Tlog::getInstance()->warning(
                "Product ref. ".$product->getRef().": remote images are not supported, please use a local copy (".$productData[self::IMAGE_COLUMN].')'
            );

            return;
        }

        $filePath = $baseDir . DS . 'Images'. DS . $productData[self::IMAGE_COLUMN];

        if (!$filePath) {
            return;
        }

        if (!file_exists($filePath)) {
            Tlog::getInstance()->addWarning('Image not found : ' . $filePath);

            return;
        }

        try {
            $fileName = basename($filePath);

            $productImage = ProductImageQuery::create()
                ->filterByProductId($product->getId())
                ->filterByFile($fileName)
                ->findOneOrCreate();
            if (!$productImage->isNew() && file_exists($filePath) && is_file($filePath)) {
                $this->fileManager->deleteFile($filePath);
            }

            $uploadedFile = new UploadedFile($this->copyFile($filePath), $fileName);
            $event = new FileCreateOrUpdateEvent($product->getId());
            $event->setModel($productImage)
                ->setUploadedFile($uploadedFile);
            $this->dispatcher->dispatch($event, TheliaEvents::IMAGE_SAVE);
            $this->addProductSaleElementImage($productSaleElements, $product, $event->getUploadedFile()?->getFilename());
        } catch (\Exception $ex) {
            Tlog::getInstance()->addError("Echec d'ajout de l'image $filePath : ".$ex->getMessage());
        }
    }

    private function copyFile(string $filePath): string
    {
        $fileInfo = pathinfo($filePath);
        $directory = $fileInfo['dirname'];
        $filename = $fileInfo['filename'];
        $extension = $fileInfo['extension'];

        $newFilename = $filename . '_copy.' . $extension;
        $newFilePath = sys_get_temp_dir() . DS . $fileInfo['basename'];
        copy($filePath, $newFilePath);

        return $newFilePath;
    }
}
