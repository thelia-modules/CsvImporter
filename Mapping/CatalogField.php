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

namespace CsvImporter\Mapping;

enum CatalogField: string
{
    case Reference = 'reference';
    case Title = 'title';
    case Chapo = 'chapo';
    case Description = 'description';
    case Price = 'price';
    case PromoPrice = 'promo_price';
    case Stock = 'stock';
    case Weight = 'weight';
    case Category = 'category';
    case Brand = 'brand';
    case Template = 'template';
    case Image = 'image';
    case Ean = 'ean';
    case Feature = 'feature';
    case Attribute = 'attribute';
}
