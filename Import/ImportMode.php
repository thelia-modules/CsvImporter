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

namespace CsvImporter\Import;

enum ImportMode: string
{
    case CreateOrUpdate = 'create_or_update';
    case UpdateOnly = 'update_only';
    case CreateOnly = 'create_only';

    public function allowsCreation(): bool
    {
        return self::UpdateOnly !== $this;
    }

    public function allowsUpdate(): bool
    {
        return self::CreateOnly !== $this;
    }
}
