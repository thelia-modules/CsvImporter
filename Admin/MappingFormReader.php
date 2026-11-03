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

namespace CsvImporter\Admin;

use CsvImporter\Mapping\CatalogField;
use CsvImporter\Mapping\ColumnTarget;
use CsvImporter\Mapping\ImportMapping;
use CsvImporter\Report\ReportMessage;

/**
 * Turns the mapping table of the screen back into a mapping. The columns are the ones of
 * the file, read again on the server: the form only says what each of them holds.
 */
final readonly class MappingFormReader
{
    public const IGNORED = '';

    private const MAX_FEATURE_NAME = 255;

    /**
     * @return list<CatalogField> the fields a column can be given, in the order the screen offers them
     */
    public static function offeredFields(): array
    {
        return array_values(array_filter(CatalogField::cases(), static fn (CatalogField $field): bool => CatalogField::Attribute !== $field));
    }

    /**
     * @param list<string> $headers
     *
     * @return array{mapping: ImportMapping, errors: list<ReportMessage>}
     */
    public function read(array $headers, mixed $posted): array
    {
        $columns = \is_array($posted) ? $posted : [];
        $targets = [];
        $errors = [];

        foreach ($headers as $index => $header) {
            $column = $columns[$index] ?? null;
            $value = \is_array($column) && \is_string($column['field'] ?? null) ? $column['field'] : self::IGNORED;

            if (self::IGNORED === $value) {
                continue;
            }

            $field = CatalogField::tryFrom($value);

            if (null === $field || CatalogField::Attribute === $field) {
                $errors[] = new ReportMessage('The column "%column" is given a field that does not exist', ['%column' => $header]);

                continue;
            }

            if (CatalogField::Feature !== $field) {
                $targets[$header] = new ColumnTarget($field);

                continue;
            }

            $name = \is_string($column['name'] ?? null) ? trim($column['name']) : '';

            if ('' === $name || mb_strlen($name) > self::MAX_FEATURE_NAME) {
                $errors[] = new ReportMessage('The column "%column" needs the name of its feature, in 255 characters at most', ['%column' => $header]);

                continue;
            }

            $targets[$header] = new ColumnTarget($field, $name);
        }

        return ['mapping' => new ImportMapping($targets), 'errors' => $errors];
    }
}
