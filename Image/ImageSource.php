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

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ImageSource
{
    public function __construct(
        public bool $remote,
        public string $location,
    ) {
    }

    public static function redact(string $location): string
    {
        $parts = parse_url($location);

        if (false === $parts || !isset($parts['host'])) {
            return mb_substr($location, 0, 120);
        }

        return mb_substr($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? ''), 0, 200);
    }
}
