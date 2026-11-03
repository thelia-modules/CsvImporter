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

use Thelia\Model\ModuleHookQuery;

final readonly class StaleHookPurger
{
    public function purge(int $moduleId): int
    {
        $removed = 0;

        foreach (ModuleHookQuery::create()->filterByModuleId($moduleId)->find() as $moduleHook) {
            if (class_exists($moduleHook->getClassname())) {
                continue;
            }

            $moduleHook->delete();
            ++$removed;
        }

        return $removed;
    }
}
