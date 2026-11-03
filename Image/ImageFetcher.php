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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ImageFetcher
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_PIXELS = 40000000;

    private const TYPES = [\IMAGETYPE_JPEG => 'jpg', \IMAGETYPE_PNG => 'png', \IMAGETYPE_GIF => 'gif', \IMAGETYPE_WEBP => 'webp'];

    public function __construct(
        #[Autowire(service: 'csv_importer.image_http_client')]
        private HttpClientInterface $client,
    ) {
    }

    /**
     * Checks the source without any network call and returns the readable local file, if the source is one.
     *
     * @throws ImageRefusal
     */
    public function check(ImageSource $source, ?string $imagesDirectory): ?string
    {
        if ($source->remote) {
            return null;
        }

        return $this->localFile($source->location, $imagesDirectory);
    }

    /**
     * @throws ImageRefusal
     */
    public function fetch(ImageSource $source, ?string $imagesDirectory): FetchedImage
    {
        $path = $source->remote ? $this->download($source->location) : $this->copy($this->localFile($source->location, $imagesDirectory), $source->location);

        try {
            $extension = $this->extensionOf($path);
        } catch (ImageRefusal $refusal) {
            @unlink($path);

            throw $refusal;
        }

        $name = $this->nameOf($source, $extension);

        return new FetchedImage($path, $name, new UploadedFile($path, $name, null, null, true));
    }

    private function localFile(string $name, ?string $imagesDirectory): string
    {
        $directory = null === $imagesDirectory ? false : realpath($imagesDirectory);

        if (false === $directory) {
            throw new ImageRefusal(new ReportMessage('The image file "%source" cannot be read: no images directory was given', ['%source' => $name]));
        }

        $candidate = $directory.\DIRECTORY_SEPARATOR.$name;
        $real = realpath($candidate);

        if (false === $real || is_link($candidate) || !str_starts_with($real, $directory.\DIRECTORY_SEPARATOR) || !is_file($real) || !is_readable($real)) {
            throw new ImageRefusal(new ReportMessage('The image file "%source" is not in the images directory', ['%source' => $name]));
        }

        return $real;
    }

    private function copy(string $from, string $name): string
    {
        if (filesize($from) > self::MAX_BYTES) {
            throw new ImageRefusal(new ReportMessage('The image "%source" is larger than %limit MB', ['%source' => $name, '%limit' => (string) (self::MAX_BYTES / 1024 / 1024)]));
        }

        $target = $this->temporaryFile();

        if (!copy($from, $target)) {
            @unlink($target);

            throw new ImageRefusal(new ReportMessage('The image file "%source" cannot be read', ['%source' => $name]));
        }

        return $target;
    }

    private function download(string $url): string
    {
        $target = $this->temporaryFile();
        $handle = fopen($target, 'w');
        $received = 0;

        try {
            $response = $this->client->request('GET', $url, ['headers' => ['Accept' => 'image/*']]);

            if (200 !== $response->getStatusCode()) {
                throw new ImageRefusal(new ReportMessage('The image "%source" answered with the status %status', ['%source' => ImageSource::redact($url), '%status' => (string) $response->getStatusCode()]));
            }

            foreach ($this->client->stream($response) as $chunk) {
                $received += \strlen($chunk->getContent());

                if ($received > self::MAX_BYTES) {
                    $response->cancel();

                    throw new ImageRefusal(new ReportMessage('The image "%source" is larger than %limit MB', ['%source' => ImageSource::redact($url), '%limit' => (string) (self::MAX_BYTES / 1024 / 1024)]));
                }

                fwrite($handle, $chunk->getContent());
            }
        } catch (ImageRefusal $refusal) {
            $this->discard($handle, $target);

            throw $refusal;
        } catch (\Throwable) {
            $this->discard($handle, $target);

            throw new ImageRefusal(new ReportMessage('The image "%source" could not be downloaded', ['%source' => ImageSource::redact($url)]));
        }

        fclose($handle);

        return $target;
    }

    private function discard($handle, string $path): void
    {
        \is_resource($handle) && fclose($handle);
        @unlink($path);
    }

    private function extensionOf(string $path): string
    {
        $size = @getimagesize($path);
        $type = $size[2] ?? null;

        if (null === $type || !isset(self::TYPES[$type])) {
            throw new ImageRefusal(new ReportMessage('The file is not a JPEG, PNG, GIF or WebP image'));
        }

        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new ImageRefusal(new ReportMessage('The image is larger than %limit million pixels', ['%limit' => (string) (self::MAX_PIXELS / 1000000)]));
        }

        return self::TYPES[$type];
    }

    private function nameOf(ImageSource $source, string $extension): string
    {
        $base = pathinfo(parse_url($source->location, \PHP_URL_PATH) ?: $source->location, \PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base), '-.');

        return ('' === $base ? 'image' : mb_substr($base, 0, 80)).'.'.$extension;
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-image-');

        if (false === $path) {
            throw new ImageRefusal(new ReportMessage('The image cannot be stored for processing'));
        }

        chmod($path, 0o600);

        return $path;
    }
}
