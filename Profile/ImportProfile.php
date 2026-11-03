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

namespace CsvImporter\Profile;

use CsvImporter\Mapping\ImportMapping;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ImportProfile
{
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $delimiter,
        public ?string $encoding,
        public ?string $decimalSeparator,
        public ImportMapping $mapping,
    ) {
    }
}
