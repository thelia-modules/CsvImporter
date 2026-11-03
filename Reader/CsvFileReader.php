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

final class CsvFileReader
{
    private const MAX_CELL_BYTES = 1048576;
    private const MAX_CELL_LINES = 200;
    private const MAX_LINE_BYTES = 4194304;
    private const CHUNK_BYTES = 65536;
    private const SOURCE_CHARSETS = [
        'UTF-16LE' => 'UTF-16LE',
        'UTF-16BE' => 'UTF-16BE',
        'CP1252' => 'Windows-1252',
    ];

    private readonly CsvDialect $dialect;
    private readonly array $columns;
    private readonly int $width;

    public function __construct(private readonly string $path, ?CsvDialect $dialect = null)
    {
        $this->dialect = $dialect ?? (new CsvDialectDetector())->detect($path);

        if (1 !== \strlen($this->dialect->delimiter)) {
            throw new UnreadableFileException('The delimiter must be a single character');
        }

        $handle = $this->open();

        try {
            $this->assertBounded($handle);
            [$header] = $this->readHeader($handle);
        } finally {
            fclose($handle);
        }

        $columns = [];

        foreach ($header as $index => $name) {
            $name = CsvFormula::release(trim(ltrim($name, "\u{FEFF}")));

            if ('' === $name) {
                continue;
            }

            if (\in_array($name, $columns, true)) {
                throw new UnreadableFileException('The column "%column" appears twice in the first line', ['%column' => $name]);
            }

            $columns[$index] = $name;
        }

        if ([] === $columns) {
            throw new UnreadableFileException('The first line of the file has no column name');
        }

        $this->columns = $columns;
        $this->width = \count($header);
    }

    public function headers(): array
    {
        return array_values($this->columns);
    }

    public function rows(): \Generator
    {
        $handle = $this->open();

        try {
            [, $line] = $this->readHeader($handle);

            while (false !== $record = $this->readRecord($handle)) {
                ++$line;

                if ($this->isEmpty($record)) {
                    continue;
                }

                yield $this->toRow($line, $record);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * A blank line, or a line of separators only, which is how a spreadsheet saves an empty row.
     */
    private function isEmpty(array $record): bool
    {
        foreach ($record as $cell) {
            if ('' !== trim((string) $cell)) {
                return false;
            }
        }

        return true;
    }

    private function assertReasonableSize(int $line, array $record): void
    {
        foreach ($record as $cell) {
            if (\is_string($cell) && (\strlen($cell) > self::MAX_CELL_BYTES || substr_count($cell, "\n") > self::MAX_CELL_LINES)) {
                throw new UnreadableFileException('The line %line holds a cell that is far too long: check for a quote that was never closed', ['%line' => $line]);
            }
        }
    }

    private function toRow(int $line, array $record): SourceRow
    {
        $this->assertReasonableSize($line, $record);

        if (\count($record) !== $this->width) {
            return new SourceRow($line, [], 'Expected %expected cells, found %found', ['%expected' => $this->width, '%found' => \count($record)]);
        }

        $cells = [];

        foreach ($this->columns as $index => $name) {
            $cell = CsvFormula::release(trim((string) $record[$index]));

            if (!mb_check_encoding($cell, 'UTF-8')) {
                return new SourceRow($line, [], 'The line holds characters that are not valid in %encoding', ['%encoding' => $this->dialect->encoding]);
            }

            $cells[$name] = $cell;
        }

        return new SourceRow($line, $cells);
    }

    private function assertBounded($handle): void
    {
        $start = ftell($handle);
        $stops = $this->dialect->delimiter."\r\n";
        $state = 'start';
        $cell = 0;
        $line = 0;
        $carry = '';

        while (!feof($handle) || '' !== $carry) {
            $buffer = $carry.(string) fread($handle, self::CHUNK_BYTES);
            $last = feof($handle);
            $carry = '';
            $length = \strlen($buffer);
            $position = 0;

            while ($position < $length) {
                if ('quoted' === $state) {
                    $quote = strpos($buffer, '"', $position);

                    if (false === $quote) {
                        $cell += $length - $position;
                        $position = $length;
                    } elseif ($quote + 1 >= $length && !$last) {
                        $cell += $quote - $position;
                        $carry = '"';
                        $position = $length;
                    } elseif ($quote + 1 < $length && '"' === $buffer[$quote + 1]) {
                        $cell += $quote - $position + 2;
                        $position = $quote + 2;
                    } else {
                        $cell += $quote - $position;
                        $position = $quote + 1;
                        $state = 'plain';
                    }

                    if ($cell > self::MAX_CELL_BYTES) {
                        throw new UnreadableFileException('A quoted cell is longer than 1 MB or its quote is never closed');
                    }

                    continue;
                }

                $char = $buffer[$position];

                if ('start' === $state && '"' === $char) {
                    $state = 'quoted';
                    $cell = 0;
                    ++$position;

                    continue;
                }

                if ("\n" === $char || "\r" === $char || $this->dialect->delimiter === $char) {
                    $state = 'start';
                    $line = "\n" === $char ? 0 : $line + 1;
                    ++$position;

                    continue;
                }

                $jump = strcspn($buffer, $stops, $position);
                $state = 'plain';
                $line += $jump;
                $position += $jump;

                if ($line > self::MAX_LINE_BYTES) {
                    throw new UnreadableFileException('A line is longer than 4 MB');
                }
            }
        }

        if ('quoted' === $state) {
            throw new UnreadableFileException('A quoted cell is never closed');
        }

        fseek($handle, $start);
    }

    private function readHeader($handle): array
    {
        $line = 0;

        while (false !== $record = $this->readRecord($handle)) {
            ++$line;

            if ([null] !== $record) {
                $this->assertReasonableSize($line, $record);

                return [$record, $line];
            }
        }

        throw new UnreadableFileException('The file is empty');
    }

    private function readRecord($handle): array|false
    {
        return fgetcsv($handle, null, $this->dialect->delimiter, '"', '');
    }

    private function open()
    {
        $source = fopen($this->path, 'r');

        if (false === $source) {
            throw new UnreadableFileException('The file cannot be read');
        }

        if (!isset(self::SOURCE_CHARSETS[$this->dialect->encoding])) {
            if ($this->dialect->hasBom) {
                fseek($source, 3);
            }

            return $source;
        }

        $target = tmpfile();

        if (false === $target) {
            fclose($source);

            throw new UnreadableFileException('The file cannot be read');
        }

        try {
            if ($this->dialect->hasBom && str_starts_with($this->dialect->encoding, 'UTF-16')) {
                fseek($source, 2);
            }

            $this->transcode($source, $target, self::SOURCE_CHARSETS[$this->dialect->encoding]);
        } finally {
            fclose($source);
        }

        rewind($target);

        return $target;
    }

    private function transcode($source, $target, string $charset): void
    {
        $wide = str_starts_with($charset, 'UTF-16');
        $carry = '';

        while (!feof($source)) {
            $chunk = $carry.(string) fread($source, self::CHUNK_BYTES);
            $carry = '';

            if ($wide && !feof($source)) {
                $keep = $this->incompleteTail($chunk, $charset);
                $carry = substr($chunk, \strlen($chunk) - $keep);
                $chunk = substr($chunk, 0, \strlen($chunk) - $keep);
            }

            fwrite($target, mb_convert_encoding($chunk, 'UTF-8', $charset));
        }

        if ('' !== $carry) {
            fwrite($target, mb_convert_encoding($carry, 'UTF-8', $charset));
        }
    }

    private function incompleteTail(string $chunk, string $charset): int
    {
        $length = \strlen($chunk);
        $keep = $length % 2;

        if ($length - $keep >= 2) {
            $high = 'UTF-16LE' === $charset ? \ord($chunk[$length - $keep - 1]) : \ord($chunk[$length - $keep - 2]);

            if ($high >= 0xD8 && $high <= 0xDB) {
                $keep += 2;
            }
        }

        return $keep;
    }
}
