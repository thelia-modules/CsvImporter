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
use Thelia\Core\Event\Category\CategoryCreateEvent;
use Thelia\Core\Event\Product\ProductCreateEvent;
use Thelia\Core\Event\Product\ProductUpdateEvent;
use Thelia\Core\Event\Tax\TaxEvent;
use Thelia\Core\Event\Tax\TaxRuleEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\Country;
use Thelia\Model\Product;
use Thelia\Model\ProductQuery;
use Thelia\Model\TaxRule;
use Thelia\Model\TaxRuleCountryQuery;
use Thelia\TaxEngine\TaxType\PricePercentTaxType;

class CsvProductImporterService
{
    public const LEVEL_1 = 'Niveau 1';
    public const LEVEL_4 = 'Niveau 4';
    public const ATTRIBUTE_DISCRIMINATOR = 'D:';

    private ?string $previousTaxRate = null;

    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    /**
     * @throws PropelException
     */
    public function importProductsFromCsv($filePath, Country $country = null, string $locale = 'fr_FR'): void
    {
        if (null === $country) {
            $country = Country::getDefaultCountry();
        }
        $filesystem = new Filesystem();
        if (!$filesystem->exists($filePath)) {
            throw new \RuntimeException("Le fichier spécifié n'existe pas : $filePath");
        }

        if (($handle = fopen($filePath, 'r')) === false) {
            throw new \RuntimeException("Impossible d'ouvrir le fichier : $filePath");
        }

        $headers = fgetcsv($handle, 1000, ',');
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            if (!$productData = array_combine($headers, $data)) {
                throw new \RuntimeException('Problem while combining headers and data.');
            }
            $product = $this->findOrCreateProduct(
                $productData,
                $country,
                $locale,
                $this->findOrCreateCategory($productData, $locale)
            );
            $this->createOrUpdateProductSaleElement($product, $productData);
            //            $this->addImages($product, $productData);
        }

        fclose($handle);
        Tlog::getInstance()->addInfo('Importation et mise à jour terminées.');
    }

    private function findOrCreateTax(array $productData, Country $country, string $locale): ?TaxRule
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
        $taxRuleEvent = $this->createTaxRule($locale, $taxTitle, $country, $taxEvent->getTax()?->getId());

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
            ->findOne()
            ?->getTaxRule();
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

        Tlog::getInstance()->addInfo('Produit créé : '.$productData['Titre du produit (Description)']);

        $newProduct = $this->dispatchProductEvent(new ProductCreateEvent(), $productData, $locale, $category, $country, true);
        return $this->dispatchProductEvent(new ProductUpdateEvent($newProduct->getId()), $productData, $locale, $category, $country);
    }

    private function dispatchProductEvent($event, array $productData, string $locale, Category $category, Country $country, bool $isNew = false): Product
    {
        $event
            ->setRef($productData['Référence Produit (Code)'])
            ->setLocale($locale)
            ->setTitle($productData['Titre du produit (Description)'])
            ->setDefaultCategory($category->getId())
            ->setBasePrice($productData['Prix du Produit HT'] ?? 0)
            ->setBaseWeight($productData['Poids'] ?? 0)
            ->setTaxRuleId($this->findOrCreateTax($productData, $country, $locale)?->getId());

        if ($isNew) {
            $event->setVisible(1)
                ->setCurrencyId(1);
        }

        if ($event instanceof ProductUpdateEvent) {
            $event->setChapo($productData['Description courte']);
            $event->setDescription($productData['Description Longue']);
        }

        $this->dispatcher->dispatch($event, $isNew ? TheliaEvents::PRODUCT_CREATE : TheliaEvents::PRODUCT_UPDATE);

        return $event->getProduct();
    }

    private function createOrUpdateProductSaleElement($product, array $productData): void
    {
        $this->findOrCreateAttributes($productData);
    }

    private function findOrCreateAttributes(array $productData): void
    {
        $filteredData = array_filter(array_keys($productData), static fn ($key) => str_starts_with($key, self::ATTRIBUTE_DISCRIMINATOR));
        $attributes = array_intersect_key($productData, array_flip($filteredData));
        dd($attributes);
    }
}
