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

trait TemporaryFileTrait
{
    private ?string $temporaryDirectory = null;

    protected function tearDown(): void
    {
        if (null !== $this->temporaryDirectory) {
            foreach (glob($this->temporaryDirectory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->temporaryDirectory);
            $this->temporaryDirectory = null;
        }
    }

    private function directory(): string
    {
        if (null === $this->temporaryDirectory) {
            $this->temporaryDirectory = sys_get_temp_dir().'/csv-importer-'.bin2hex(random_bytes(6));
            mkdir($this->temporaryDirectory, 0o700);
        }

        return $this->temporaryDirectory;
    }

    private function file(string $content): string
    {
        $path = $this->directory().'/'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, $content);

        return $path;
    }
}
