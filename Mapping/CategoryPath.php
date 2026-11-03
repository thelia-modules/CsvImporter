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

final class CategoryPath
{
    private const SEPARATOR = '>';
    private const ESCAPE = '\\';

    public static function join(array $levels): string
    {
        return implode(' '.self::SEPARATOR.' ', array_map(static fn (string $level): string => str_replace([self::ESCAPE, self::SEPARATOR], [self::ESCAPE.self::ESCAPE, self::ESCAPE.self::SEPARATOR], $level), $levels));
    }

    public static function split(string $path): array
    {
        $levels = [];
        $current = '';
        $length = \strlen($path);

        for ($position = 0; $position < $length; ++$position) {
            $character = $path[$position];

            if (self::ESCAPE === $character && $position + 1 < $length && \in_array($path[$position + 1], [self::SEPARATOR, self::ESCAPE], true)) {
                $current .= $path[++$position];

                continue;
            }

            if (self::SEPARATOR === $character) {
                $levels[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $levels[] = $current;

        return array_values(array_filter(array_map('trim', $levels), static fn (string $level): bool => '' !== $level));
    }
}
