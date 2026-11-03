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
use CsvImporter\Reader\SourceRow;
use CsvImporter\Reader\UnreadableFileException;
use PHPUnit\Framework\TestCase;

final class CsvFileReaderTest extends TestCase
{
    use TemporaryFileTrait;

    public function testItReadsTheRowsByHeaderWithTheirLineNumber(): void
    {
        $reader = new CsvFileReader($this->file("ref;titre;prix\nA1;Chaise;10\nA2;Table;20\n"));

        self::assertSame(['ref', 'titre', 'prix'], $reader->headers());
        self::assertEquals([
            new SourceRow(2, ['ref' => 'A1', 'titre' => 'Chaise', 'prix' => '10']),
            new SourceRow(3, ['ref' => 'A2', 'titre' => 'Table', 'prix' => '20']),
        ], iterator_to_array($reader->rows(), false));
    }

    public function testItConvertsWindows1252ToUtf8(): void
    {
        $content = "ref;titre\nA1;".mb_convert_encoding('Chaise été', 'Windows-1252', 'UTF-8')."\n";

        $rows = iterator_to_array((new CsvFileReader($this->file($content)))->rows(), false);

        self::assertSame('Chaise été', $rows[0]->cells['titre']);
    }

    public function testItConvertsUtf16ToUtf8(): void
    {
        $content = "\xFF\xFE".mb_convert_encoding("ref;titre\nA1;Chaise été\n", 'UTF-16LE', 'UTF-8');

        $reader = new CsvFileReader($this->file($content));

        self::assertSame(['ref', 'titre'], $reader->headers());
        self::assertSame('Chaise été', iterator_to_array($reader->rows(), false)[0]->cells['titre']);
    }

    public function testItDropsTheBomFromTheFirstHeader(): void
    {
        self::assertSame(['ref', 'titre'], (new CsvFileReader($this->file("\xEF\xBB\xBFref;titre\nA1;Chaise\n")))->headers());
    }

    public function testABomBeforeAQuotedFirstHeaderDoesNotBreakTheQuotes(): void
    {
        $reader = new CsvFileReader($this->file("\xEF\xBB\xBF\"ref\";\"titre\"\nA1;Chaise\n"));

        self::assertSame(['ref', 'titre'], $reader->headers());
    }

    public function testACharacterOutsideTheBasicMultilingualPlaneSurvives(): void
    {
        $rows = iterator_to_array((new CsvFileReader($this->file("ref;titre\nA1;Chaise 😀 \u{1F4A1}\n")))->rows(), false);

        self::assertSame("Chaise 😀 \u{1F4A1}", $rows[0]->cells['titre']);
    }

    public function testAQuotedCellKeepsItsDelimiterAndItsLineBreak(): void
    {
        $rows = iterator_to_array((new CsvFileReader($this->file("ref;titre\nA1;\"Chaise;\nbois\"\n")))->rows(), false);

        self::assertSame("Chaise;\nbois", $rows[0]->cells['titre']);
    }

    public function testItTrimsTheCells(): void
    {
        $rows = iterator_to_array((new CsvFileReader($this->file("ref;titre\n A1 ; Chaise \n")))->rows(), false);

        self::assertSame(['ref' => 'A1', 'titre' => 'Chaise'], $rows[0]->cells);
    }

    public function testABlankLineIsSkippedButStillCounted(): void
    {
        $rows = iterator_to_array((new CsvFileReader($this->file("ref;titre\nA1;Chaise\n\nA2;Table\n")))->rows(), false);

        self::assertSame([2, 4], array_map(static fn (SourceRow $row): int => $row->lineNumber, $rows));
    }

    public function testALineOfSeparatorsOnlyIsSkippedButStillCounted(): void
    {
        $rows = iterator_to_array((new CsvFileReader($this->file("ref;titre;prix\nA1;Chaise;10\n;;\n ; ;\nA2;Table;20\n;\n")))->rows(), false);

        self::assertSame([2, 5], array_map(static fn (SourceRow $row): int => $row->lineNumber, $rows));
        self::assertNull($rows[1]->problem);
    }

    public function testAColumnWithoutHeaderIsDropped(): void
    {
        $reader = new CsvFileReader($this->file("ref;titre;\nA1;Chaise;\n"));

        self::assertSame(['ref', 'titre'], $reader->headers());
        self::assertSame(['ref' => 'A1', 'titre' => 'Chaise'], iterator_to_array($reader->rows(), false)[0]->cells);
    }

    public function testARowWithTheWrongNumberOfCellsIsReportedAndTheNextOneIsRead(): void
    {
        $rows = iterator_to_array((new CsvFileReader($this->file("ref;titre;prix\nA1;Chaise\nA2;Table;20\n")))->rows(), false);

        self::assertCount(2, $rows);
        self::assertNotNull($rows[0]->problem);
        self::assertSame(2, $rows[0]->lineNumber);
        self::assertNull($rows[1]->problem);
        self::assertSame('20', $rows[1]->cells['prix']);
    }

    public function testItRefusesTwoColumnsWithTheSameHeader(): void
    {
        $this->expectException(UnreadableFileException::class);

        new CsvFileReader($this->file("ref;titre;titre\nA1;a;b\n"));
    }

    public function testAFileWithOnlyAHeaderHasNoRows(): void
    {
        $reader = new CsvFileReader($this->file("ref;titre\n"));

        self::assertSame([], iterator_to_array($reader->rows(), false));
    }

    public function testAWindows1252ByteWithNoMeaningDoesNotCutTheFile(): void
    {
        $lines = ['ref;titre'];
        for ($index = 1; $index <= 20000; ++$index) {
            $lines[] = "R$index;".(10000 === $index ? "mauvais \x81 octet" : "ligne $index");
        }

        $rows = iterator_to_array((new CsvFileReader($this->file(implode("\n", $lines)."\n"), new CsvDialect(';', 'CP1252', false)))->rows(), false);

        self::assertCount(20000, $rows);
        self::assertSame('R20000', $rows[19999]->cells['ref']);
        self::assertNull($rows[9999]->problem);
    }

    public function testAWindows1252ByteWithNoMeaningInTheFirstLinesDoesNotMakeTheFileEmpty(): void
    {
        $reader = new CsvFileReader($this->file("ref;titre\nA1;mauvais \x81 octet\nA2;suite\n"), new CsvDialect(';', 'CP1252', false));

        self::assertCount(2, iterator_to_array($reader->rows(), false));
    }

    public function testALoneSurrogateInAUtf16FileDoesNotCutTheFile(): void
    {
        $body = "ref;titre\n";
        for ($index = 1; $index <= 20000; ++$index) {
            $body .= "R$index;ligne $index\n";
        }
        $utf16 = "\xFF\xFE".mb_convert_encoding($body, 'UTF-16LE', 'UTF-8');
        $cut = \strlen("\xFF\xFE".mb_convert_encoding("ref;titre\n", 'UTF-16LE', 'UTF-8')) + 2 * 15000;
        $utf16 = substr($utf16, 0, $cut)."\x00\xD8".substr($utf16, $cut);

        $rows = iterator_to_array((new CsvFileReader($this->file($utf16)))->rows(), false);

        self::assertCount(20000, $rows);
    }

    public function testAnEmojiCutBetweenTwoReadBlocksOfAUtf16FileSurvives(): void
    {
        $cell = str_repeat('a', 32754).'😀';
        $body = "\xFF\xFE".mb_convert_encoding("ref;titre\nA1;$cell\nA2;suite\n", 'UTF-16LE', 'UTF-8');

        $rows = iterator_to_array((new CsvFileReader($this->file($body)))->rows(), false);

        self::assertCount(2, $rows);
        self::assertSame($cell, $rows[0]->cells['titre']);
    }

    public function testAnEmojiCutBetweenTwoReadBlocksOfAUtf16BigEndianFileSurvives(): void
    {
        $cell = str_repeat('a', 32754).'😀';
        $body = "\xFE\xFF".mb_convert_encoding("ref;titre\nA1;$cell\nA2;suite\n", 'UTF-16BE', 'UTF-8');

        $rows = iterator_to_array((new CsvFileReader($this->file($body)))->rows(), false);

        self::assertSame($cell, $rows[0]->cells['titre']);
    }

    public function testAnUnclosedQuoteIsRefusedInsteadOfSwallowingTheRestOfTheFile(): void
    {
        $lines = ['ref;titre', 'A1;"ouvert'];
        for ($index = 1; $index <= 500; ++$index) {
            $lines[] = "R$index;ligne $index";
        }

        $this->expectException(UnreadableFileException::class);

        iterator_to_array((new CsvFileReader($this->file(implode("\n", $lines)."\n")))->rows(), false);
    }

    public function testAnOrdinaryMultilineCellIsStillRead(): void
    {
        $rows = iterator_to_array((new CsvFileReader($this->file("ref;titre\nA1;\"un\ndeux\ntrois\"\nA2;ok\n")))->rows(), false);

        self::assertCount(2, $rows);
    }

    public function testAFileTenTimesLongerCostsNoMoreMemory(): void
    {
        $small = $this->peakMemoryOfReading(3000);
        $large = $this->peakMemoryOfReading(30000);

        self::assertLessThan(256 * 1024, $large - $small);
    }

    private function peakMemoryOfReading(int $rows): int
    {
        $path = $this->file('');
        $handle = fopen($path, 'w');
        fwrite($handle, "ref;titre;prix\n");

        for ($index = 1; $index <= $rows; ++$index) {
            fwrite($handle, "R$index;Produit numéro $index avec un titre assez long pour peser;".($index % 100)."\n");
        }

        fclose($handle);

        $reader = new CsvFileReader($path);
        $count = 0;
        memory_reset_peak_usage();
        $before = memory_get_usage();

        foreach ($reader->rows() as $row) {
            ++$count;
            unset($row);
        }

        $peak = memory_get_peak_usage() - $before;

        self::assertSame($rows, $count);

        return $peak;
    }
}
