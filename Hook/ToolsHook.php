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

namespace CsvImporter\Hook;

use CsvImporter\Admin\ScreenAccess;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Translation\Translator;

final class ToolsHook extends BaseHook
{
    public function __construct(
        private readonly ScreenAccess $access,
        private readonly UrlGeneratorInterface $urls,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'main.top-menu-tools' => [
                ['type' => 'back', 'method' => 'onMainTopMenuTools'],
            ],
            'tools.col2-bottom' => [
                ['type' => 'back', 'method' => 'onToolsDataTransfer'],
            ],
        ];
    }

    public function onToolsDataTransfer(HookRenderEvent $event): void
    {
        if (!$this->access->can(AccessManager::VIEW)) {
            return;
        }

        $event->add($this->render('CsvImporter/tools_entry.html.twig'));
    }

    public function onMainTopMenuTools(HookRenderBlockEvent $event): void
    {
        if (!$this->access->can(AccessManager::VIEW)) {
            return;
        }

        $event->add([
            'id' => 'csv-importer-menu-entry',
            'class' => '',
            'url' => $this->urls->generate('csvimporter.index'),
            'title' => Translator::getInstance()->trans('Catalog import', [], 'csvimporter.bo.default-twig'),
        ]);
    }
}
