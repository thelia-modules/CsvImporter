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

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[Exclude]
final readonly class FetchedImage
{
    public function __construct(
        public string $path,
        public string $name,
        public UploadedFile $file,
    ) {
    }

    public function discard(): void
    {
        @unlink($this->path);
    }
}
