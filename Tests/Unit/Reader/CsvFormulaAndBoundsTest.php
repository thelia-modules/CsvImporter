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

namespace CsvImporter\Tests\Unit\Reader;

use CsvImporter\Reader\CsvDialect;
use CsvImporter\Reader\CsvFileReader;
use CsvImporter\Reader\CsvFormula;
use CsvImporter\Reader\UnreadableFileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvFormulaAndBoundsTest extends TestCase
{
    use TemporaryFileTrait;

    #[DataProvider('cells')]
    public function testACellSurvivesTheRoundTripAndNeverStartsWithATrigger(string $cell, string $written): void
    {
        self::assertSame($written, CsvFormula::protect($cell));
        self::assertSame($cell, CsvFormula::release($written));
    }

    public static function cells(): iterable
    {
        yield 'formula' => ['=SUM(A1)', "'=SUM(A1)"];
        yield 'plus' => ['+33 1 23', "'+33 1 23"];
        yield 'at' => ['@cmd', "'@cmd"];
        yield 'text' => ['Chaise', 'Chaise'];
        yield 'negative number' => ['-5', '-5'];
        yield 'decimal number' => ['-1,5', '-1,5'];
        yield 'already apostrophe-formula' => ["'=x", "''=x"];
        yield 'double apostrophe' => ["''x", "'''x"];
        yield 'lone apostrophe' => ["'", "'"];
        yield 'empty' => ['', ''];
    }

    public function testAnUnclosedQuoteIsRefusedWithoutReadingTheRestIntoMemory(): void
    {
        $path = $this->file("Réf;Titre\n\"A1;x\n".str_repeat("ligne;suite\n", 150000));

        $this->expectException(UnreadableFileException::class);

        new CsvFileReader($path);
    }

    public function testAQuotedCellOverOneMegabyteIsRefused(): void
    {
        $path = $this->file("Réf;Titre\nA1;\"".str_repeat('x', 1100000)."\"\n");

        $this->expectException(UnreadableFileException::class);

        new CsvFileReader($path, new CsvDialect(';', 'UTF-8', false));
    }

    public function testAnUnquotedLineOverFourMegabytesIsRefused(): void
    {
        $path = $this->file("Réf;Titre\nA1;".str_repeat('x', 4300000)."\n");

        $this->expectException(UnreadableFileException::class);

        new CsvFileReader($path);
    }

    public function testQuotesDoubledAndMultilineCellsStillRead(): void
    {
        $path = $this->file("Réf;Titre\nA1;\"dit \"\"oui\"\"\nsur deux lignes\"\n");

        $rows = iterator_to_array((new CsvFileReader($path))->rows(), false);

        self::assertSame("dit \"oui\"\nsur deux lignes", $rows[0]->cells['Titre']);
    }
}
