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

namespace CsvImporter\Tests\Unit\Admin;

use CsvImporter\Admin\ImageArchiveExtractor;
use CsvImporter\Admin\UploadRefused;
use CsvImporter\Image\ImageFetcher;
use PHPUnit\Framework\TestCase;

final class ImageArchiveExtractorTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/csv-importer-archive-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0o700);
    }

    protected function tearDown(): void
    {
        (new ImageArchiveExtractor())->remove($this->workspace);
    }

    public function testItUnpacksTheImagesAndKeepsTheirFoldersButSkipsWhatIsNotAnImage(): void
    {
        $archive = $this->zip(['mug.png' => 'a', 'photos/tasse.JPG' => 'b', 'notes.txt' => 'c', '__MACOSX/mug.png' => 'd', 'photos/' => null]);

        $count = (new ImageArchiveExtractor())->extract($archive, $this->workspace.'/out');

        self::assertSame(2, $count);
        self::assertSame('a', file_get_contents($this->workspace.'/out/mug.png'));
        self::assertSame('b', file_get_contents($this->workspace.'/out/photos/tasse.JPG'));
        self::assertFileDoesNotExist($this->workspace.'/out/notes.txt');
        self::assertFileDoesNotExist($this->workspace.'/out/__MACOSX');
        self::assertSame(0o600, fileperms($this->workspace.'/out/mug.png') & 0o777);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeNames')]
    public function testAPathThatLeavesTheFolderRefusesTheWholeArchiveAndWritesNothing(string $name): void
    {
        $archive = $this->zip(['ok.png' => 'a', $name => 'x']);

        try {
            (new ImageArchiveExtractor())->extract($archive, $this->workspace.'/out');
            self::fail('The archive must be refused');
        } catch (UploadRefused $refused) {
            self::assertSame('The images archive holds a path that leaves its folder: "%name"', $refused->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->workspace.'/out');
        self::assertFileDoesNotExist($this->workspace.'/evil.png');
        self::assertSame(['archive.zip'], array_values(array_diff(scandir($this->workspace) ?: [], ['.', '..'])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeNames(): iterable
    {
        yield 'parent folder' => ['../evil.png'];
        yield 'nested parent folder' => ['photos/../../evil.png'];
        yield 'absolute path' => ['/tmp/evil.png'];
        yield 'backslash' => ['photos\\evil.png'];
        yield 'drive letter' => ['C:evil.png'];
        yield 'empty segment' => ['photos//evil.png'];
    }

    public function testASymbolicLinkRefusesTheWholeArchive(): void
    {
        $archive = $this->zip(['ok.png' => 'a', 'link.png' => '/etc/passwd'], ['link.png' => 0o120777]);

        $this->expectException(UploadRefused::class);
        $this->expectExceptionMessage('The images archive holds a symbolic link: "%name"');

        (new ImageArchiveExtractor())->extract($archive, $this->workspace.'/out');
    }

    public function testAnImageLargerThanTheLimitRefusesTheArchiveBeforeAnythingIsWritten(): void
    {
        $archive = $this->zip(['ok.png' => 'a', 'big.png' => str_repeat("\0", ImageFetcher::MAX_BYTES + 1)]);

        try {
            (new ImageArchiveExtractor())->extract($archive, $this->workspace.'/out');
            self::fail('The archive must be refused');
        } catch (UploadRefused $refused) {
            self::assertSame('The image "%name" of the archive is larger than %limit MB', $refused->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->workspace.'/out');
    }

    public function testTwoNamesThatOnlyDifferByCaseAreRefused(): void
    {
        $archive = $this->zip(['A.png' => 'a', 'a.png' => 'b']);

        $this->expectException(UploadRefused::class);
        $this->expectExceptionMessage('The images archive holds the name "%name" twice');

        (new ImageArchiveExtractor())->extract($archive, $this->workspace.'/out');
    }

    public function testTooManyFilesAreRefused(): void
    {
        $files = [];

        for ($i = 0; $i <= ImageArchiveExtractor::MAX_ENTRIES; ++$i) {
            $files['i'.$i.'.png'] = '';
        }

        $this->expectException(UploadRefused::class);
        $this->expectExceptionMessage('The images archive holds more than %max files');

        (new ImageArchiveExtractor())->extract($this->zip($files), $this->workspace.'/out');
    }

    public function testAnArchiveWithoutAnyImageIsRefused(): void
    {
        $this->expectException(UploadRefused::class);
        $this->expectExceptionMessage('The images archive holds no JPEG, PNG, GIF or WebP image');

        (new ImageArchiveExtractor())->extract($this->zip(['notes.txt' => 'a']), $this->workspace.'/out');
    }

    public function testAFileThatIsNotAZipIsRefused(): void
    {
        $path = $this->workspace.'/not-a-zip.zip';
        file_put_contents($path, 'plain text');

        $this->expectException(UploadRefused::class);
        $this->expectExceptionMessage('The images archive is not a valid zip file');

        (new ImageArchiveExtractor())->extract($path, $this->workspace.'/out');
    }

    /**
     * @param array<string, ?string> $files       a null content is a folder
     * @param array<string, int>     $permissions Unix mode by name
     */
    private function zip(array $files, array $permissions = []): string
    {
        $path = $this->workspace.'/archive.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($files as $name => $content) {
            self::assertTrue(null === $content ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $content), 'the fixture archive holds '.$name);
        }

        foreach ($permissions as $name => $mode) {
            $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, $mode << 16);
        }

        $zip->close();

        return $path;
    }
}
