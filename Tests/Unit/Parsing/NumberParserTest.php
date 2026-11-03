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

namespace CsvImporter\Tests\Unit\Parsing;

use CsvImporter\Parsing\InvalidNumberException;
use CsvImporter\Parsing\NumberParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumberParserTest extends TestCase
{
    #[DataProvider('readableNumbers')]
    public function testItNormalisesANumberToADecimalString(string $raw, ?string $decimalSeparator, string $expected): void
    {
        self::assertSame($expected, (new NumberParser())->parse($raw, $decimalSeparator));
    }

    public static function readableNumbers(): iterable
    {
        yield 'integer' => ['12', null, '12'];
        yield 'dot decimal' => ['12.5', null, '12.5'];
        yield 'comma decimal' => ['12,5', null, '12.5'];
        yield 'trailing zero is kept' => ['12.50', null, '12.50'];
        yield 'space thousands' => ['1 234,56', null, '1234.56'];
        yield 'no-break space thousands' => ["1\u{00A0}234,56", null, '1234.56'];
        yield 'narrow no-break space thousands' => ["1\u{202F}234,56", null, '1234.56'];
        yield 'dot thousands, comma decimal' => ['1.234,56', null, '1234.56'];
        yield 'comma thousands, dot decimal' => ['1,234.56', null, '1234.56'];
        yield 'repeated dot thousands' => ['1.234.567', null, '1234567'];
        yield 'repeated comma thousands' => ['1,234,567', null, '1234567'];
        yield 'plain decimal' => ['1234.56', null, '1234.56'];
        yield 'euro suffix' => ['12,5 €', null, '12.5'];
        yield 'euro prefix' => ['€ 12.50', null, '12.50'];
        yield 'negative' => ['-3,5', null, '-3.5'];
        yield 'explicit plus' => ['+3', null, '3'];
        yield 'leading zero is a decimal' => ['0.125', null, '0.125'];
        yield 'leading zero comma is a decimal' => ['0,125', null, '0.125'];
        yield 'long integer part is a decimal' => ['1234.567', null, '1234.567'];
        yield 'no integer part' => ['.5', null, '0.5'];
        yield 'largest storable' => ['9999999999.99', null, '9999999999.99'];
        yield 'ambiguous with dot as decimal' => ['1.234', '.', '1.234'];
        yield 'ambiguous with comma as thousands' => ['1.234', ',', '1234'];
        yield 'ambiguous comma with comma as decimal' => ['1,234', ',', '1.234'];
        yield 'ambiguous comma with dot as decimal' => ['1,234', '.', '1234'];
        yield 'comma decimal forced' => ['1.234,56', ',', '1234.56'];
        yield 'dot decimal forced' => ['1,234.56', '.', '1234.56'];
        yield 'repeated dots with comma decimal' => ['1.234.567', ',', '1234567'];
    }

    #[DataProvider('unreadableNumbers')]
    public function testItRefusesWhatIsNotAnUnambiguousNumber(string $raw): void
    {
        $this->expectException(InvalidNumberException::class);

        (new NumberParser())->parse($raw);
    }

    public static function unreadableNumbers(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'letters' => ['abc'];
        yield 'letters inside' => ['12abc3'];
        yield 'scientific notation' => ['1e5'];
        yield 'two decimals marks' => ['1..2'];
        yield 'badly grouped' => ['12.5.5'];
        yield 'mixed marks twice' => ['1.23,4.5'];
        yield 'only a sign' => ['-'];
        yield 'ambiguous dot' => ['1.234'];
        yield 'ambiguous comma' => ['1,234'];
        yield 'too large' => ['10000000000'];
        yield 'too large negative' => ['-10000000000'];
    }

    #[DataProvider('strayMarks')]
    public function testAnExplicitSeparatorRefusesTheOtherMarkWhenItIsNotAThousandsGroup(string $raw, string $decimalSeparator): void
    {
        $this->expectException(InvalidNumberException::class);

        (new NumberParser())->parse($raw, $decimalSeparator);
    }

    public static function strayMarks(): iterable
    {
        yield 'comma decimal read as dot' => ['12,50', '.'];
        yield 'short comma group' => ['1,5', '.'];
        yield 'dot decimal read as comma' => ['1.5', ','];
        yield 'badly grouped thousands' => ['1,23,456.7', '.'];
        yield 'group of four' => ['1,2345.5', '.'];
    }
}
