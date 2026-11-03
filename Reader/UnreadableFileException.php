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

namespace CsvImporter\Reader;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class UnreadableFileException extends \RuntimeException
{
    public function __construct(string $messageKey, public readonly array $parameters = [])
    {
        parent::__construct($messageKey);
    }
}
