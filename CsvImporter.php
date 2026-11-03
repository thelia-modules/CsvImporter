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

namespace CsvImporter;

use CsvImporter\Hook\StaleHookPurger;
use CsvImporter\Image\ImageHttpClientFactory;
use CsvImporter\Job\RunCatalogImportJob;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Thelia\Module\BaseModule;

class CsvImporter extends BaseModule
{
    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Model/*',
                __DIR__.'/Tests/*',
            ])
            ->autowire(true)
            ->autoconfigure(true);

        $servicesConfigurator->set('csv_importer.image_http_client', HttpClientInterface::class)
            ->factory([ImageHttpClientFactory::class, 'create']);
    }

    public static function loadConfiguration(ContainerBuilder $containerBuilder): void
    {
        $containerBuilder->loadFromExtension('framework', [
            'messenger' => ['routing' => [RunCatalogImportJob::class => 'async_heavy']],
        ]);
    }

    public function update($currentVersion, $newVersion, ?ConnectionInterface $con = null): void
    {
        (new StaleHookPurger())->purge(self::getModuleId());
    }

    public function postActivation(?ConnectionInterface $con = null): void
    {
        (new StaleHookPurger())->purge(self::getModuleId());
    }
}
