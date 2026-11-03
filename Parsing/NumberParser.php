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

namespace CsvImporter\Parsing;

final readonly class NumberParser
{
    private const LARGEST_STORABLE_NUMBER = 1e10;

    public function parse(string $raw, ?string $decimalSeparator = null): string
    {
        if (null !== $decimalSeparator && !\in_array($decimalSeparator, ['.', ','], true)) {
            throw new \InvalidArgumentException('The decimal separator is a dot or a comma');
        }

        $compact = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}€$£]/u', '', $raw);

        if (null === $compact || 1 !== preg_match('/^([+-]?)([\d.,]*)$/', $compact, $parts) || 1 !== preg_match('/\d/', $parts[2])) {
            throw new InvalidNumberException('The value "%value" of the column "%column" is not a number', $raw);
        }

        $normalised = null === $decimalSeparator
            ? $this->normaliseGuessingSeparator($parts[2], $raw)
            : $this->normaliseWithSeparator($parts[2], $decimalSeparator, $raw);

        if (abs((float) $normalised) >= self::LARGEST_STORABLE_NUMBER) {
            throw new InvalidNumberException('The value "%value" of the column "%column" is too large', $raw);
        }

        return ('-' === $parts[1] ? '-' : '').$normalised;
    }

    private function normaliseWithSeparator(string $digits, string $decimalSeparator, string $raw): string
    {
        $thousandsSeparator = '.' === $decimalSeparator ? ',' : '.';

        if (substr_count($digits, $decimalSeparator) > 1) {
            throw new InvalidNumberException('The value "%value" of the column "%column" is not a number', $raw);
        }

        [$integerPart, $fractionPart] = array_pad(explode($decimalSeparator, $digits), 2, '');

        if (str_contains($fractionPart, $thousandsSeparator)) {
            throw new InvalidNumberException('The value "%value" of the column "%column" is not a number', $raw);
        }

        if (str_contains($integerPart, $thousandsSeparator) && 1 !== preg_match('/^\d{1,3}(?:'.preg_quote($thousandsSeparator, '/').'\d{3})+$/', $integerPart)) {
            throw new InvalidNumberException('The value "%value" of the column "%column" is not a number', $raw);
        }

        return $this->join(str_replace($thousandsSeparator, '', $integerPart), $fractionPart, $raw);
    }

    private function normaliseGuessingSeparator(string $digits, string $raw): string
    {
        $dots = substr_count($digits, '.');
        $commas = substr_count($digits, ',');

        if ($dots > 0 && $commas > 0) {
            $decimalSeparator = strrpos($digits, '.') > strrpos($digits, ',') ? '.' : ',';
            $thousandsSeparator = '.' === $decimalSeparator ? ',' : '.';

            if (1 !== substr_count($digits, $decimalSeparator)) {
                throw new InvalidNumberException('The value "%value" of the column "%column" is not a number', $raw);
            }

            [$integerPart, $fractionPart] = explode($decimalSeparator, $digits);

            if (1 !== preg_match('/^\d{1,3}(?:'.preg_quote($thousandsSeparator, '/').'\d{3})*$/', $integerPart)) {
                throw new InvalidNumberException('The value "%value" of the column "%column" is not a number', $raw);
            }

            return $this->join(str_replace($thousandsSeparator, '', $integerPart), $fractionPart, $raw);
        }

        $separator = $dots > 0 ? '.' : ($commas > 0 ? ',' : null);

        if (null === $separator) {
            return $this->join($digits, '', $raw);
        }

        if (substr_count($digits, $separator) > 1) {
            if (1 !== preg_match('/^\d{1,3}(?:'.preg_quote($separator, '/').'\d{3})+$/', $digits)) {
                throw new InvalidNumberException('The value "%value" of the column "%column" is not a number', $raw);
            }

            return str_replace($separator, '', $digits);
        }

        [$integerPart, $fractionPart] = explode($separator, $digits);

        if (3 === \strlen($fractionPart) && $integerPart !== '' && \strlen($integerPart) <= 3 && 0 !== (int) $integerPart) {
            throw new InvalidNumberException('The value "%value" of the column "%column" is ambiguous: say in the profile which mark is the decimal one', $raw);
        }

        return $this->join($integerPart, $fractionPart, $raw);
    }

    private function join(string $integerPart, string $fractionPart, string $raw): string
    {
        if (1 !== preg_match('/^\d*$/', $integerPart) || 1 !== preg_match('/^\d*$/', $fractionPart)) {
            throw new InvalidNumberException('The value "%value" of the column "%column" is not a number', $raw);
        }

        $integerPart = '' === $integerPart ? '0' : $integerPart;

        return '' === $fractionPart ? $integerPart : $integerPart.'.'.$fractionPart;
    }
}
