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

namespace CsvImporter\Tests\Unit\Image;

use CsvImporter\Image\ImageRefusal;
use CsvImporter\Image\ImageSourceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageSourceParserTest extends TestCase
{
    public function testSeveralSourcesAreSplitOnThePipeAndClassified(): void
    {
        $sources = (new ImageSourceParser())->parse(' https://cdn.example.com/a.jpg | b.png || sub/c.gif ');

        self::assertCount(3, $sources);
        self::assertTrue($sources[0]->remote);
        self::assertFalse($sources[1]->remote);
        self::assertSame('sub/c.gif', $sources[2]->location);
    }

    public function testAnEmptyCellHoldsNoSource(): void
    {
        self::assertSame([], (new ImageSourceParser())->parse('  '));
    }

    #[DataProvider('refused')]
    public function testASourceThatIsNeitherHttpNorAPlainFileNameIsRefused(string $cell): void
    {
        $this->expectException(ImageRefusal::class);

        (new ImageSourceParser())->parse($cell);
    }

    public static function refused(): iterable
    {
        foreach (['ftp://example.com/a.png', 'file:///etc/hosts', 'gopher://example.com/', 'data:image/png,AAAA', 'php://filter/resource=x', 'javascript:alert(1)', 'http:///a.png', 'https:no-slashes.png', '/etc/hosts', '\\\\server\\share\\a.png', '../a.png', 'a/../../b.png', 'a\\..\\b.png', "a\0.png"] as $cell) {
            yield $cell => [$cell];
        }
    }
}
