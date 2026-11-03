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

namespace CsvImporter\Mapping;

use CsvImporter\Import\ImportMode;
use CsvImporter\Report\ReportMessage;

final readonly class MappingValidator
{
    public function validate(ImportMapping $mapping, ImportMode $mode): array
    {
        $issues = [];
        $seen = [];

        foreach ($mapping->targets as $header => $target) {
            if (CatalogField::Attribute === $target->field) {
                $issues[] = new ReportMessage('The column "%column" holds combinations, which this version does not import yet', ['%column' => (string) $header]);

                continue;
            }

            if (CatalogField::Feature === $target->field) {
                continue;
            }

            if (isset($seen[$target->field->value])) {
                $issues[$target->field->value.'-duplicate'] = new ReportMessage('The field "%field" is mapped to several columns', ['%field' => $target->field->value]);
            }

            $seen[$target->field->value] = true;
        }

        if (null === $mapping->headerFor(CatalogField::Reference)) {
            array_unshift($issues, new ReportMessage('The column holding the reference is required'));
        }

        return array_values($issues);
    }
}
