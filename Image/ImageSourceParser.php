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

namespace CsvImporter\Image;

use CsvImporter\Report\ReportMessage;

final readonly class ImageSourceParser
{
    public const SEPARATOR = '|';

    /**
     * @return list<ImageSource>
     *
     * @throws ImageRefusal
     */
    public function parse(string $cell): array
    {
        $sources = [];

        foreach (explode(self::SEPARATOR, $cell) as $part) {
            $part = trim($part);

            if ('' !== $part) {
                $sources[] = $this->classify($part);
            }
        }

        return $sources;
    }

    private function classify(string $part): ImageSource
    {
        if (1 === preg_match('#^[a-z][a-z0-9+.-]*:#i', $part)) {
            if (1 !== preg_match('#^https?://[^/\s]+#i', $part)) {
                throw new ImageRefusal(new ReportMessage('The image "%source" is neither an http(s) address nor a file name', ['%source' => $this->shorten($part)]));
            }

            return new ImageSource(true, $part);
        }

        if (str_starts_with($part, '/') || str_starts_with($part, '\\') || str_contains($part, "\0") || 1 === preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $part)) {
            throw new ImageRefusal(new ReportMessage('The image file "%source" must be a name inside the images directory', ['%source' => $this->shorten($part)]));
        }

        return new ImageSource(false, $part);
    }

    private function shorten(string $text): string
    {
        return ImageSource::redact($text);
    }
}
