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

use CsvImporter\CsvImporter;
use CsvImporter\Hook\StaleHookPurger;
use CsvImporter\Hook\ToolsHook;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\HookQuery;
use Thelia\Model\ModuleHook;
use Thelia\Model\ModuleHookQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Test\IntegrationTestCase;

final class StaleHookPurgerTest extends IntegrationTestCase
{
    public function testAHookWhoseClassIsGoneIsRemovedAndTheOthersStay(): void
    {
        $moduleId = CsvImporter::getModuleId();
        $hook = HookQuery::create()->findOneByCode('module.configuration');
        self::assertNotNull($hook);

        $this->link($moduleId, $hook->getId(), 'CsvImporter\\Hook\\HookManager', 'onModuleConfiguration');
        $this->link($moduleId, $hook->getId(), ToolsHook::class, 'onToolsDataTransfer');

        self::assertSame(1, (new StaleHookPurger())->purge($moduleId));

        $left = ModuleHookQuery::create()->filterByModuleId($moduleId)->filterByHookId($hook->getId())->find();
        self::assertCount(1, $left);
        self::assertSame(ToolsHook::class, $left[0]->getClassname());
    }

    public function testAnotherModulesHookIsNeverTouched(): void
    {
        $hook = HookQuery::create()->findOneByCode('module.configuration');
        $other = ModuleQuery::create()->filterById(CsvImporter::getModuleId(), Criteria::NOT_EQUAL)->findOne();
        self::assertNotNull($other);
        $foreign = $this->link($other->getId(), $hook->getId(), 'Gone\\Hook\\Manager', 'onModuleConfiguration');

        self::assertSame(0, (new StaleHookPurger())->purge(CsvImporter::getModuleId()));
        self::assertNotNull(ModuleHookQuery::create()->findPk($foreign->getId()));
    }

    private function link(int $moduleId, int $hookId, string $class, string $method): ModuleHook
    {
        $moduleHook = (new ModuleHook())
            ->setModuleId($moduleId)
            ->setHookId($hookId)
            ->setClassname($class)
            ->setMethod($method)
            ->setActive(true)
            ->setModuleActive(true)
            ->setHookActive(true)
            ->setPosition(1000);
        $moduleHook->save();

        return $moduleHook;
    }
}
