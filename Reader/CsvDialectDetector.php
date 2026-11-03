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

final readonly class CsvDialectDetector
{
    private const SAMPLE_SIZE = 65536;
    private const SAMPLED_LINES = 10;
    private const DELIMITERS = [';', ',', "\t", '|'];

    public function detect(string $path): CsvDialect
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new UnreadableFileException('The file cannot be read');
        }

        $sample = file_get_contents($path, false, null, 0, self::SAMPLE_SIZE);

        if (false === $sample || '' === $sample) {
            throw new UnreadableFileException('The file is empty');
        }

        $truncated = \strlen($sample) === self::SAMPLE_SIZE;
        [$encoding, $hasBom, $text] = $this->decode($sample);

        if (1 === preg_match('/\r(?!\n)/', $truncated ? rtrim($text, "\r") : $text)) {
            throw new UnreadableFileException('The lines of the file end with a lone carriage return: save it again with Windows or Unix line endings');
        }

        return new CsvDialect($this->findDelimiter($text, $truncated), $encoding, $hasBom);
    }

    /**
     * The dialect a profile asks for, completed by what the file shows for the rest.
     */
    public function resolve(string $path, ?string $delimiter, ?string $encoding): CsvDialect
    {
        $detected = $this->detect($path);

        return new CsvDialect($delimiter ?? $detected->delimiter, $encoding ?? $detected->encoding, $detected->hasBom);
    }

    private function decode(string $sample): array
    {
        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            return ['UTF-8', true, substr($sample, 3)];
        }

        foreach (['UTF-16LE' => "\xFF\xFE", 'UTF-16BE' => "\xFE\xFF"] as $encoding => $bom) {
            if (str_starts_with($sample, $bom)) {
                $body = substr($sample, 2);

                return [$encoding, true, mb_convert_encoding(0 === \strlen($body) % 2 ? $body : substr($body, 0, -1), 'UTF-8', $encoding)];
            }
        }

        if (str_contains($sample, "\0")) {
            throw new UnreadableFileException('The file is not a text file');
        }

        if ($this->isUtf8($sample)) {
            return ['UTF-8', false, $sample];
        }

        return ['CP1252', false, mb_convert_encoding($sample, 'UTF-8', 'Windows-1252')];
    }

    private function isUtf8(string $sample): bool
    {
        for ($cut = 0; $cut <= 3 && $cut < \strlen($sample); ++$cut) {
            if (mb_check_encoding(0 === $cut ? $sample : substr($sample, 0, -$cut), 'UTF-8')) {
                return true;
            }
        }

        return false;
    }

    private function findDelimiter(string $text, bool $truncated): string
    {
        $lines = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $text)), static fn (string $line): bool => '' !== trim($line)));

        if ($truncated && \count($lines) > 1) {
            array_pop($lines);
        }

        $lines = \array_slice($lines, 0, self::SAMPLED_LINES);
        $best = null;
        $bestColumns = 1;

        foreach (self::DELIMITERS as $delimiter) {
            $counts = array_map(static fn (string $line): int => \count(str_getcsv($line, $delimiter, '"', '')), $lines);

            if (1 !== \count(array_unique($counts)) || $counts[0] <= $bestColumns) {
                continue;
            }

            $best = $delimiter;
            $bestColumns = $counts[0];
        }

        return $best ?? $this->delimiterOfTheHeader($lines);
    }

    private function delimiterOfTheHeader(array $lines): string
    {
        $best = ',';
        $bestColumns = 1;

        foreach (self::DELIMITERS as $delimiter) {
            $columns = \count(str_getcsv($lines[0] ?? '', $delimiter, '"', ''));

            if ($columns > $bestColumns) {
                $best = $delimiter;
                $bestColumns = $columns;
            }
        }

        return $best;
    }
}
