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

namespace CsvImporter\Lookup;

use CsvImporter\Import\ImportOptions;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Catalog\Brand\BrandFacade;
use Thelia\Domain\Catalog\Category\CategoryFacade;

final readonly class CatalogLookupFactory
{
    public function __construct(
        private CategoryFacade $categories,
        private BrandFacade $brands,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function create(ImportOptions $options): CatalogLookup
    {
        return new CatalogLookup($this->categories, $this->brands, $this->dispatcher, $options->locale, $options->dryRun, $options->createMissingReferences);
    }
}
