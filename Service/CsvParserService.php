<?php

namespace CsvImporter\Service;

use Symfony\Component\Yaml\Yaml;
use Thelia\Log\Tlog;

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
        $mappedData = [];
        foreach ($this->getFeatureColumns($productData) as $featureColumn => $featureTitle) {
            $mappedData['features'][substr($featureColumn, 2)] = $productData[$featureColumn];
        }
        foreach ($this->getAttributeColumns($productData) as $attributeColumn => $attributeTitle) {
            $mappedData['attributes'][substr($attributeColumn, 2)] = $productData[$attributeColumn];
        }
        foreach ($this->mapping as $fieldName => $header) {
            $mappedData[$fieldName] = $productData[$header] ?? null;
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

    /**
     * Issue a warning for each missing column in a file header
     *
     * @param array $headers
     * @return void
     */
    public function checkHeaders(array $headers): void
    {
        foreach ($this->mapping as $fieldName => $headerLabel) {
            if (! in_array($headerLabel, $headers, true)) {
                Tlog::getInstance()->warning("Column \"$headerLabel\" not found, some data could be missing after import");
            }
        }

        foreach ($headers as $headerLabel) {
            if (!in_array($headerLabel, $this->mapping, true)
                &&
                !str_starts_with($headerLabel, self::ATTRIBUTE_DISCRIMINATOR)
                &&
                !str_starts_with($headerLabel, self::FEATURE_DISCRIMINATOR)
            ) {
                Tlog::getInstance()->warning("Found additional \"$headerLabel\" column. This column will be ignored.");
            }
        }
    }
}
