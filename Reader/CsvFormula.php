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

namespace CsvImporter\Reader;

final class CsvFormula
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function protect(string $cell): string
    {
        if ('' === $cell) {
            return $cell;
        }

        $triggers = \in_array($cell[0], self::TRIGGERS, true) && !is_numeric(str_replace(',', '.', $cell));

        return $triggers || self::isEscaped($cell) ? "'".$cell : $cell;
    }

    public static function release(string $cell): string
    {
        return self::isEscaped($cell) ? substr($cell, 1) : $cell;
    }

    private static function isEscaped(string $cell): bool
    {
        return \strlen($cell) > 1 && "'" === $cell[0] && (\in_array($cell[1], self::TRIGGERS, true) || "'" === $cell[1]);
    }
}
