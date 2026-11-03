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

namespace CsvImporter\Tests\Integration;

use CsvImporter\Image\ImageFetcher;
use CsvImporter\Image\ImagePass;
use CsvImporter\Image\ImageSourceParser;
use CsvImporter\Image\ProductImageLoader;
use CsvImporter\Import\CatalogImportRunner;
use CsvImporter\Import\HtmlCleaner;
use CsvImporter\Import\ImportMode;
use CsvImporter\Import\RowProcessor;
use CsvImporter\Job\ImportJobRepository;
use CsvImporter\Lookup\CatalogLookupFactory;
use CsvImporter\Mapping\HeaderGuesser;
use CsvImporter\Mapping\ImportMapping;
use CsvImporter\Mapping\MappingValidator;
use CsvImporter\Model\CsvImporterJob;
use CsvImporter\Model\CsvImporterJobRowQuery;
use CsvImporter\Parsing\NumberParser;
use CsvImporter\Profile\ImportProfile;
use CsvImporter\Profile\ProfileRepository;
use CsvImporter\Reader\CsvFileReader;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Thelia\Domain\Catalog\Brand\BrandFacade;
use Thelia\Domain\Catalog\Category\CategoryFacade;
use Thelia\Domain\Catalog\Product\ProductFacade;
use Thelia\Domain\Catalog\Product\PSEFacade;
use Thelia\Domain\Media\MediaFacade;
use Thelia\Model\Lang;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;

trait ImportTestSupport
{
    private array $temporaryFiles = [];
    private array $results = [];
    private ?HttpClientInterface $imageClient = null;
    private ?string $imagesDirectory = null;

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    private function csv(array $rows, string $delimiter = ';'): string
    {
        return implode("\n", array_map(static fn (array $row): string => implode($delimiter, $row), $rows))."\n";
    }

    private function importCsv(string $content, ImportMode $mode = ImportMode::CreateOrUpdate, bool $dryRun = false, ?string $decimalSeparator = null, ?ImportMapping $mapping = null, ?\Closure $onRow = null, bool $imagesOnly = false): CsvImporterJob
    {
        $path = sys_get_temp_dir().'/csv-importer-it-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        $mapping ??= ImportMapping::fromProposal((new HeaderGuesser())->guess((new CsvFileReader($path))->headers()));
        $profile = (new ProfileRepository())->save(new ImportProfile(null, 'it-'.bin2hex(random_bytes(6)), null, null, $decimalSeparator, $mapping));
        $job = (new ImportJobRepository())->create($profile->id, $path, 'file.csv', $mode, $dryRun, null);

        $this->results = [];
        $this->runner()->run($job, function ($result) use ($onRow): void {
            $this->results[] = $result;
            null === $onRow || $onRow($result);
        }, $this->imagesDirectory, $imagesOnly);

        $job->reload();

        return $job;
    }

    private function runner(): CatalogImportRunner
    {
        $container = static::getContainer();
        $lookups = new CatalogLookupFactory(
            $container->get(CategoryFacade::class),
            $container->get(BrandFacade::class),
            $container->get('event_dispatcher'),
        );

        return new CatalogImportRunner(
            new ProfileRepository(),
            new ImportJobRepository(),
            new MappingValidator(),
            new RowProcessor($container->get(ProductFacade::class), $container->get(PSEFacade::class), new NumberParser(), new HtmlCleaner()),
            $lookups,
            $this->locks(),
            new ImagePass(new ImageSourceParser(), new ImageFetcher($this->imageClient ?? new MockHttpClient(static fn () => throw new \LogicException('No image should be requested'))), new ProductImageLoader($container->get(MediaFacade::class)), new ImportJobRepository()),
        );
    }

    private function locks(): LockFactory
    {
        $directory = sys_get_temp_dir().'/csv-importer-test-locks';

        if (!is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }

        return new LockFactory(new FlockStore($directory));
    }

    private function locale(): string
    {
        return Lang::getDefaultLanguage()->getLocale();
    }

    private function product(string $reference): ?\Thelia\Model\Product
    {
        $product = ProductQuery::create()->findOneByRef($reference);
        $product?->setLocale($this->locale());

        return $product;
    }

    private function priceOf(string $reference): ?string
    {
        $product = $this->product($reference);

        if (null === $product) {
            return null;
        }

        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->filterByIsDefault(true)->findOne();

        return ProductPriceQuery::create()->filterByProductSaleElementsId($pse->getId())->findOne()?->getPrice();
    }

    private function outcomes(): array
    {
        return array_map(static fn ($result): string => $result->outcome->value, $this->results);
    }

    private function reportLines(CsvImporterJob $job, string $outcome): array
    {
        return CsvImporterJobRowQuery::create()->filterByJobId($job->getId())->filterByOutcome($outcome)->orderByLineNumber()->find()->getData();
    }
}
