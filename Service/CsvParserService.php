<?php

namespace CsvImporter\Service;

use Symfony\Component\Yaml\Yaml;

class CsvParserService
{
    private array $mapping;

    public const ATTRIBUTE_DISCRIMINATOR = 'D:';
    public const FEATURE_DISCRIMINATOR = 'C:';

    public function __construct()
    {
        $this->mapping = Yaml::parseFile(__DIR__.'/../Config/csv_mapping.yaml')['mappings'];
    }

    public function mapToArray(array $productData): array
    {
        static $lastMappedData = [];

        $mappedData = [];
        foreach ($this->getFeatureColumns($productData) as $featureColumn => $featureTitle) {
            $mappedData['features'][substr($featureColumn, 2)] = $productData[$featureColumn];
        }
        foreach ($this->getAttributeColumns($productData) as $attributeColumn => $attributeTitle) {
            $mappedData['attributes'][substr($attributeColumn, 2)] = $productData[$attributeColumn];
        }
        foreach ($this->mapping as $fieldName => $header) {
            // On empty fields, re-use last valid value if we have one
            $mappedData[$fieldName] =
                empty($productData[$header]) ?
                    ($lastMappedData[$fieldName] ?? null) :
                    $productData[$header]
                ;

            if (! empty($mappedData[$fieldName])) {
                $lastMappedData[$fieldName] = $mappedData[$fieldName];
            }
        }

        return $mappedData;
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

}
