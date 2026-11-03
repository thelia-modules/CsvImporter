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

namespace CsvImporter\Tests\Unit\Mapping;

use CsvImporter\Mapping\CategoryPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryPathTest extends TestCase
{
    #[DataProvider('paths')]
    public function testItSplitsAPathIntoItsLevels(string $path, array $levels): void
    {
        self::assertSame($levels, CategoryPath::split($path));
    }

    public static function paths(): iterable
    {
        yield 'one level' => ['Maison', ['Maison']];
        yield 'two levels' => ['Maison > Cuisine', ['Maison', 'Cuisine']];
        yield 'no spaces around' => ['A>B>C', ['A', 'B', 'C']];
        yield 'empty levels dropped' => ['A > > B >', ['A', 'B']];
        yield 'escaped separator' => ['Maison > Cuisine \\> Salon', ['Maison', 'Cuisine > Salon']];
        yield 'escaped backslash' => ['A \\\\ B > C', ['A \\ B', 'C']];
        yield 'escaped separator at the end' => ['Maison \\>', ['Maison >']];
        yield 'blank' => ['  ', []];
    }
}
