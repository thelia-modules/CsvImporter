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

use Thelia\Domain\Media\DTO\ImageUploadDTO;
use Thelia\Domain\Media\MediaFacade;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductImageQuery;

final readonly class ProductImageLoader
{
    public function __construct(private MediaFacade $media)
    {
    }

    /**
     * @return bool false when the product already holds this very image
     */
    public function load(int $productId, FetchedImage $image, string $locale): bool
    {
        $hash = hash_file('sha256', $image->path);

        if ($this->alreadyHolds($productId, $hash)) {
            $image->discard();

            return false;
        }

        try {
            $this->media->uploadImage(new ImageUploadDTO($productId, 'product', $image->file, $locale));
        } finally {
            $image->discard();
        }

        return true;
    }

    private function alreadyHolds(int $productId, string|false $hash): bool
    {
        $directory = (new ProductImage())->getUploadDir();

        foreach (ProductImageQuery::create()->filterByProductId($productId)->find() as $existing) {
            $path = $directory.\DIRECTORY_SEPARATOR.$existing->getFile();

            if (is_file($path) && hash_file('sha256', $path) === $hash) {
                return true;
            }
        }

        return false;
    }
}
