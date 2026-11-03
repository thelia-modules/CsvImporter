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

use CsvImporter\Report\ReportMessage;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final class ImageRefusal extends \RuntimeException
{
    public function __construct(public readonly ReportMessage $report)
    {
        parent::__construct($report->key);
    }
}
