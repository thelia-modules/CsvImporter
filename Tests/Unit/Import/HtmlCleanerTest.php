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

namespace CsvImporter\Tests\Unit\Import;

use CsvImporter\Import\HtmlCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlCleanerTest extends TestCase
{
    public function testItKeepsTheFormattingOfADescription(): void
    {
        $html = '<p>Texte <b>gras</b> et <em>italique</em></p><ul><li>un</li></ul><a href="https://example.com/x">lien</a>';

        self::assertSame($html, (new HtmlCleaner())->clean($html));
    }

    #[DataProvider('hostileHtml')]
    public function testItRemovesWhatRunsScripts(string $html, string $forbidden): void
    {
        $clean = (new HtmlCleaner())->clean($html);

        self::assertStringNotContainsStringIgnoringCase($forbidden, $clean);
        self::assertStringContainsString('reste', $clean);
    }

    public static function hostileHtml(): iterable
    {
        yield 'script' => ['<p>reste</p><script>alert(1)</script>', 'script'];
        yield 'event handler' => ['<p onclick="alert(1)">reste</p>', 'onclick'];
        yield 'image error handler' => ['<p>reste</p><img src="x" onerror="alert(1)">', 'onerror'];
        yield 'javascript link' => ['<a href="javascript:alert(1)">reste</a>', 'javascript:'];
        yield 'iframe' => ['<p>reste</p><iframe src="https://example.com"></iframe>', 'iframe'];
        yield 'svg onload' => ['<p>reste</p><svg onload="alert(1)"></svg>', 'onload'];
        yield 'style tag' => ['<p>reste</p><style>body{display:none}</style>', '<style'];
    }
}
