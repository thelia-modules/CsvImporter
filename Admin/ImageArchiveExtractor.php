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

namespace CsvImporter\Admin;

use CsvImporter\Image\ImageFetcher;

/**
 * Unpacks an archive of images into a directory nobody else writes to. The archive is
 * never trusted: every name is checked before the first byte is written, and the bytes
 * counted are the ones read, not the sizes the archive declares.
 */
final readonly class ImageArchiveExtractor
{
    public const MAX_ARCHIVE_BYTES = 200 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 500 * 1024 * 1024;
    public const MAX_ENTRIES = 5000;
    public const MAX_NAME_LENGTH = 200;

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const SYMLINK = 0o120000;
    private const TYPE_MASK = 0o170000;

    /**
     * @return int the number of images written
     *
     * @throws UploadRefused
     */
    public function extract(string $archivePath, string $directory): int
    {
        $archive = new \ZipArchive();

        if (true !== $archive->open($archivePath, \ZipArchive::RDONLY)) {
            throw new UploadRefused('The images archive is not a valid zip file');
        }

        try {
            $entries = $this->entriesOf($archive);

            if (!@mkdir($directory, 0o700) && !is_dir($directory)) {
                throw new UploadRefused('The images archive could not be stored');
            }

            try {
                $this->write($archive, $entries, $directory);
            } catch (\Throwable $failure) {
                $this->remove($directory);

                throw $failure;
            }

            return \count($entries);
        } finally {
            $archive->close();
        }
    }

    public function remove(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->remove($item->getPathname());

                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    /**
     * @return array<int, string> image entries, by index in the archive
     *
     * @throws UploadRefused
     */
    private function entriesOf(\ZipArchive $archive): array
    {
        if ($archive->numFiles > self::MAX_ENTRIES) {
            throw new UploadRefused('The images archive holds more than %max files', ['%max' => (string) self::MAX_ENTRIES]);
        }

        $entries = [];
        $seen = [];

        for ($index = 0; $index < $archive->numFiles; ++$index) {
            $name = $archive->getNameIndex($index);
            $stat = $archive->statIndex($index);

            if (false === $name || false === $stat) {
                throw new UploadRefused('The images archive is not a valid zip file');
            }

            $archive->getExternalAttributesIndex($index, $system, $attributes);

            if (\ZipArchive::OPSYS_UNIX === $system && self::SYMLINK === (($attributes >> 16) & self::TYPE_MASK)) {
                throw new UploadRefused('The images archive holds a symbolic link: "%name"', ['%name' => $this->shown($name)]);
            }

            if ($this->isMetadata($name)) {
                continue;
            }

            $this->checkName($name);

            if (str_ends_with($name, '/') || !\in_array(strtolower(pathinfo($name, \PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                continue;
            }

            if ($stat['size'] > ImageFetcher::MAX_BYTES) {
                throw new UploadRefused('The image "%name" of the archive is larger than %limit MB', ['%name' => $this->shown($name), '%limit' => (string) (ImageFetcher::MAX_BYTES / 1024 / 1024)]);
            }

            if (isset($seen[strtolower($name)])) {
                throw new UploadRefused('The images archive holds the name "%name" twice', ['%name' => $this->shown($name)]);
            }

            $seen[strtolower($name)] = true;
            $entries[$index] = $name;
        }

        if ([] === $entries) {
            throw new UploadRefused('The images archive holds no JPEG, PNG, GIF or WebP image');
        }

        return $entries;
    }

    /**
     * @param array<int, string> $entries
     *
     * @throws UploadRefused
     */
    private function write(\ZipArchive $archive, array $entries, string $directory): void
    {
        $total = 0;

        foreach ($entries as $index => $name) {
            $target = $directory.'/'.$name;
            $folder = \dirname($target);

            if (!is_dir($folder) && !@mkdir($folder, 0o700, true) && !is_dir($folder)) {
                throw new UploadRefused('The images archive could not be stored');
            }

            $source = $archive->getStream($archive->getNameIndex($index));

            if (false === $source) {
                throw new UploadRefused('The image "%name" of the archive cannot be read', ['%name' => $this->shown($name)]);
            }

            $handle = @fopen($target, 'x');

            if (false === $handle) {
                fclose($source);

                throw new UploadRefused('The images archive could not be stored');
            }

            chmod($target, 0o600);
            $size = 0;

            try {
                while (!feof($source)) {
                    $chunk = fread($source, 65536);

                    if (false === $chunk) {
                        throw new UploadRefused('The image "%name" of the archive cannot be read', ['%name' => $this->shown($name)]);
                    }

                    $size += \strlen($chunk);
                    $total += \strlen($chunk);

                    if ($size > ImageFetcher::MAX_BYTES) {
                        throw new UploadRefused('The image "%name" of the archive is larger than %limit MB', ['%name' => $this->shown($name), '%limit' => (string) (ImageFetcher::MAX_BYTES / 1024 / 1024)]);
                    }

                    if ($total > self::MAX_TOTAL_BYTES) {
                        throw new UploadRefused('The images archive is larger than %limit MB once unpacked', ['%limit' => (string) (self::MAX_TOTAL_BYTES / 1024 / 1024)]);
                    }

                    fwrite($handle, $chunk);
                }
            } finally {
                fclose($source);
                fclose($handle);
            }
        }
    }

    /**
     * @throws UploadRefused
     */
    private function checkName(string $name): void
    {
        $unsafe = '' === $name
            || \strlen($name) > self::MAX_NAME_LENGTH
            || str_starts_with($name, '/')
            || 1 === preg_match('/[\x00-\x1F\x7F\\\\:]/', $name);

        if (!$unsafe) {
            foreach (explode('/', rtrim($name, '/')) as $segment) {
                if ('' === $segment || '.' === $segment || '..' === $segment) {
                    $unsafe = true;

                    break;
                }
            }
        }

        if ($unsafe) {
            throw new UploadRefused('The images archive holds a path that leaves its folder: "%name"', ['%name' => $this->shown($name)]);
        }
    }

    private function isMetadata(string $name): bool
    {
        return str_starts_with($name, '__MACOSX/');
    }

    private function shown(string $name): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name), 0, 80);
    }
}
