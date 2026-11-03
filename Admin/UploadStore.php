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

use CsvImporter\Reader\CsvFileReader;
use CsvImporter\Reader\UnreadableFileException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Keeps an uploaded file out of the document root, under a name nobody chooses, until
 * the administrator has seen what it would do.
 */
final readonly class UploadStore
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    private const EXTENSIONS = ['csv', 'txt', 'tsv'];
    public const MAX_COLUMNS = 200;
    private const MAX_NAME_LENGTH = 120;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDirectory,
        private ImageArchiveExtractor $archives,
    ) {
    }

    /**
     * @return array{path: string, name: string, headers: list<string>}
     *
     * @throws UploadRefused
     */
    public function store(?UploadedFile $file): array
    {
        if (null === $file) {
            throw new UploadRefused('Choose a CSV file');
        }

        if (!$file->isValid()) {
            throw new UploadRefused(\UPLOAD_ERR_INI_SIZE === $file->getError() || \UPLOAD_ERR_FORM_SIZE === $file->getError() ? 'The file is larger than the server accepts' : 'The file could not be uploaded');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new UploadRefused('The file is larger than %limit MB', ['%limit' => (string) (self::MAX_BYTES / 1024 / 1024)]);
        }

        if (!\in_array(strtolower($file->getClientOriginalExtension()), self::EXTENSIONS, true)) {
            throw new UploadRefused('The file must be a .csv, .tsv or .txt file');
        }

        try {
            $headers = (new CsvFileReader($file->getPathname()))->headers();
        } catch (UnreadableFileException $unreadable) {
            throw new UploadRefused($unreadable->getMessage(), $unreadable->parameters);
        }

        if (\count($headers) > self::MAX_COLUMNS) {
            throw new UploadRefused('The file has more than %max columns', ['%max' => (string) self::MAX_COLUMNS]);
        }

        $directory = $this->directory();
        $path = $directory.'/'.bin2hex(random_bytes(16)).'.csv';

        if (!@copy($file->getPathname(), $path)) {
            throw new UploadRefused('The file could not be stored');
        }

        chmod($path, 0o600);

        return ['path' => $path, 'name' => $this->displayName($file->getClientOriginalName()), 'headers' => $headers];
    }

    /**
     * Unpacks the images archive beside the staged file, where the job finds it again by the name of the file.
     *
     * @throws UploadRefused
     */
    public function storeImages(?UploadedFile $archive, string $stagedPath): void
    {
        if (null === $archive) {
            return;
        }

        if (!$archive->isValid()) {
            throw new UploadRefused(\UPLOAD_ERR_INI_SIZE === $archive->getError() || \UPLOAD_ERR_FORM_SIZE === $archive->getError() ? 'The images archive is larger than the server accepts' : 'The images archive could not be uploaded');
        }

        if ($archive->getSize() > ImageArchiveExtractor::MAX_ARCHIVE_BYTES) {
            throw new UploadRefused('The images archive is larger than %limit MB', ['%limit' => (string) (ImageArchiveExtractor::MAX_ARCHIVE_BYTES / 1024 / 1024)]);
        }

        if ('zip' !== strtolower($archive->getClientOriginalExtension())) {
            throw new UploadRefused('The images archive must be a .zip file');
        }

        $this->archives->extract($archive->getPathname(), $this->imagesPathOf($stagedPath));
    }

    public function imagesDirectoryOf(string $stagedPath): ?string
    {
        if (!$this->owns($stagedPath)) {
            return null;
        }

        $directory = $this->imagesPathOf($stagedPath);

        return is_dir($directory) && !is_link($directory) ? $directory : null;
    }

    public function discard(string $stagedPath): void
    {
        @unlink($stagedPath);
        $this->archives->remove($this->imagesPathOf($stagedPath));
    }

    public function owns(string $path): bool
    {
        $directory = realpath($this->directory());
        $real = realpath($path);

        return false !== $directory && false !== $real && str_starts_with($real, $directory.\DIRECTORY_SEPARATOR) && is_file($real);
    }

    private function imagesPathOf(string $stagedPath): string
    {
        return substr($stagedPath, 0, -\strlen('.csv')).'.images';
    }

    private function directory(): string
    {
        $directory = $this->projectDirectory.'/var/csv-importer/uploads';

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new UploadRefused('The file could not be stored');
        }

        return $directory;
    }

    private function displayName(string $clientName): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', basename(str_replace('\\', '/', $clientName))));

        return '' === $name ? 'import.csv' : mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }
}
