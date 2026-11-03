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

namespace CsvImporter\Report;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ReportMessage
{
    public function __construct(
        public string $key,
        public array $parameters = [],
    ) {
    }

    public function toJson(): string
    {
        return json_encode(['key' => $this->key, 'parameters' => $this->parameters], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);

        return new self((string) ($decoded['key'] ?? ''), (array) ($decoded['parameters'] ?? []));
    }
}
