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

use CsvImporter\Reader\CsvDialectDetector;
use CsvImporter\Reader\UnreadableFileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvDialectDetectorTest extends TestCase
{
    use TemporaryFileTrait;

    #[DataProvider('delimiters')]
    public function testItFindsTheDelimiter(string $delimiter): void
    {
        $content = implode("\n", [
            implode($delimiter, ['ref', 'titre', 'prix']),
            implode($delimiter, ['A1', 'Chaise', '10']),
            implode($delimiter, ['A2', 'Table', '20']),
        ]);

        self::assertSame($delimiter, (new CsvDialectDetector())->detect($this->file($content))->delimiter);
    }

    public static function delimiters(): iterable
    {
        yield 'comma' => [','];
        yield 'semicolon' => [';'];
        yield 'tab' => ["\t"];
        yield 'pipe' => ['|'];
    }

    public function testAQuotedDelimiterDoesNotMislead(): void
    {
        $content = "ref;titre;prix\nA1;\"Chaise, bois\";10\nA2;\"Table, chêne\";20\n";

        self::assertSame(';', (new CsvDialectDetector())->detect($this->file($content))->delimiter);
    }

    public function testASingleColumnFileFallsBackToTheComma(): void
    {
        self::assertSame(',', (new CsvDialectDetector())->detect($this->file("ref\nA1\nA2\n"))->delimiter);
    }

    public function testItReadsAWindowsLineEnding(): void
    {
        self::assertSame(';', (new CsvDialectDetector())->detect($this->file("ref;titre\r\nA1;Chaise\r\n"))->delimiter);
    }

    public function testItFindsUtf8WithoutBom(): void
    {
        $dialect = (new CsvDialectDetector())->detect($this->file("ref;titre\nA1;Chaise été\n"));

        self::assertSame('UTF-8', $dialect->encoding);
        self::assertFalse($dialect->hasBom);
    }

    public function testItFindsUtf8WithBom(): void
    {
        $dialect = (new CsvDialectDetector())->detect($this->file("\xEF\xBB\xBFref;titre\nA1;Chaise\n"));

        self::assertSame('UTF-8', $dialect->encoding);
        self::assertTrue($dialect->hasBom);
    }

    public function testItFindsWindows1252(): void
    {
        $content = "ref;titre\nA1;".mb_convert_encoding('Chaise été', 'Windows-1252', 'UTF-8')."\n";

        self::assertSame('CP1252', (new CsvDialectDetector())->detect($this->file($content))->encoding);
    }

    public function testItFindsUtf16LittleEndian(): void
    {
        $content = "\xFF\xFE".mb_convert_encoding("ref;titre\nA1;Chaise\n", 'UTF-16LE', 'UTF-8');

        $dialect = (new CsvDialectDetector())->detect($this->file($content));

        self::assertSame('UTF-16LE', $dialect->encoding);
        self::assertSame(';', $dialect->delimiter);
    }

    public function testItFindsUtf16BigEndian(): void
    {
        $content = "\xFE\xFF".mb_convert_encoding("ref;titre\nA1;Chaise\n", 'UTF-16BE', 'UTF-8');

        self::assertSame('UTF-16BE', (new CsvDialectDetector())->detect($this->file($content))->encoding);
    }

    public function testAMultibyteCharacterCutAtTheEndOfTheSampleStaysUtf8(): void
    {
        $content = "ref;titre\nA1;".str_repeat('é', 40000)."\n";

        self::assertSame('UTF-8', (new CsvDialectDetector())->detect($this->file($content))->encoding);
    }

    public function testItRefusesAnEmptyFile(): void
    {
        $this->expectException(UnreadableFileException::class);

        (new CsvDialectDetector())->detect($this->file(''));
    }

    public function testItRefusesABinaryFile(): void
    {
        $this->expectException(UnreadableFileException::class);

        (new CsvDialectDetector())->detect($this->file("PK\x03\x04\x00\x00\x00\x00binary"));
    }

    public function testItRefusesAClassicMacLineEnding(): void
    {
        $this->expectException(UnreadableFileException::class);

        (new CsvDialectDetector())->detect($this->file("ref;titre\rA1;Chaise\rA2;Table\r"));
    }

    public function testItRefusesAMissingFile(): void
    {
        $this->expectException(UnreadableFileException::class);

        (new CsvDialectDetector())->detect($this->directory().'/missing.csv');
    }
}
