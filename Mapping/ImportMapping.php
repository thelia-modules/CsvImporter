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

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ImportMapping
{
    public function __construct(public array $targets)
    {
    }

    public static function fromProposal(array $proposal): self
    {
        return new self(array_filter($proposal, static fn (?ColumnTarget $target): bool => null !== $target));
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('A mapping is a list of columns');
        }

        $targets = [];

        foreach ($decoded as $header => $definition) {
            $field = \is_array($definition) ? CatalogField::tryFrom((string) ($definition['field'] ?? '')) : null;

            if (null === $field) {
                throw new \InvalidArgumentException('The column "'.$header.'" maps an unknown field');
            }

            $targets[(string) $header] = new ColumnTarget($field, isset($definition['name']) ? (string) $definition['name'] : null);
        }

        return new self($targets);
    }

    public function toJson(): string
    {
        $definitions = [];

        foreach ($this->targets as $header => $target) {
            $definitions[$header] = null === $target->name
                ? ['field' => $target->field->value]
                : ['field' => $target->field->value, 'name' => $target->name];
        }

        return json_encode($definitions, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_FORCE_OBJECT);
    }

    public function targetOf(string $header): ?ColumnTarget
    {
        return $this->targets[$header] ?? null;
    }

    public function headers(): array
    {
        return array_map('strval', array_keys($this->targets));
    }

    public function headerFor(CatalogField $field): ?string
    {
        foreach ($this->targets as $header => $target) {
            if ($target->field === $field) {
                return (string) $header;
            }
        }

        return null;
    }

    public function featureHeaders(): array
    {
        $headers = [];

        foreach ($this->targets as $header => $target) {
            if (CatalogField::Feature === $target->field) {
                $headers[(string) $header] = (string) $target->name;
            }
        }

        return $headers;
    }
}
