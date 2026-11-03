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

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ImageHttpClientFactory
{
    public const array NON_ROUTABLE = ['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', 'fec0::/10', '100::/64', '::ffff:0:0/96'];

    public const array OPTIONS = [
        'max_redirects' => 0,
        'timeout' => 10,
        'max_duration' => 30,
    ];

    public static function create(): HttpClientInterface
    {
        return (new NoPrivateNetworkHttpClient(HttpClient::create(), [...IpUtils::PRIVATE_SUBNETS, ...self::NON_ROUTABLE]))->withOptions(self::OPTIONS);
    }
}
