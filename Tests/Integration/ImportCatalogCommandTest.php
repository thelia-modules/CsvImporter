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

namespace CsvImporter\Tests\Integration;

use CsvImporter\Command\ImportCatalogCommand;
use CsvImporter\Job\ImportJobRepository;
use CsvImporter\Profile\ProfileRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Thelia\Test\IntegrationTestCase;

final class ImportCatalogCommandTest extends IntegrationTestCase
{
    use ImportTestSupport;

    private function tester(): CommandTester
    {
        $command = new ImportCatalogCommand($this->runner(), new ProfileRepository(), new ImportJobRepository());
        $command->setContainer(static::getContainer());

        return new CommandTester($command);
    }

    private function file(array $rows): string
    {
        $path = sys_get_temp_dir().'/csv-importer-cmd-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, $this->csv($rows));
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function profileName(): string
    {
        return 'cmd-'.bin2hex(random_bytes(6));
    }

    public function testAnImportGuessesTheMappingKeepsItAndWritesTheCatalog(): void
    {
        $name = $this->profileName();
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->file([['Référence', 'Titre', 'Prix HT', 'Catégorie'], ['CMD-1', 'Mug', '12,50', 'Cuisine CMD']]), '--save-mapping' => $name]);

        self::assertSame(0, $status, $tester->getDisplay());
        self::assertNotNull((new ProfileRepository())->findByName($name));
        self::assertSame('12.500000', $this->priceOf('CMD-1'));
        self::assertStringContainsString('Created', $tester->getDisplay());
    }

    public function testASimulationWritesNothingToTheCatalog(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->file([['Référence', 'Titre', 'Prix HT', 'Catégorie'], ['CMD-2', 'Mug', '12,50', 'Cuisine CMD']]), '--save-mapping' => $this->profileName(), '--dry-run' => true]);

        self::assertSame(0, $status, $tester->getDisplay());
        self::assertNull($this->product('CMD-2'));
        self::assertStringContainsString('nothing was written', $tester->getDisplay());
    }

    public function testARefusedRowGivesTheDedicatedExitCodeAndItsReason(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->file([['Référence', 'Titre', 'Prix HT', 'Catégorie'], ['CMD-3', 'Mug', 'abc', 'Cuisine CMD'], ['CMD-4', 'Mug', '3,00', 'Cuisine CMD']]), '--save-mapping' => $this->profileName()]);

        self::assertSame(ImportCatalogCommand::EXIT_ROWS_FAILED, $status);
        self::assertStringContainsString('"abc" of the column "Prix HT" is not a number', $tester->getDisplay());
        self::assertNull($this->product('CMD-3'));
        self::assertSame('3.000000', $this->priceOf('CMD-4'));
    }

    public function testTheConsoleReportListsTheLinesThatNeedAttentionOnly(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => $this->file([['Référence', 'Titre', 'Prix HT', 'Catégorie'], ['CMD-REPORT-1', 'Mug', 'abc', 'Cuisine CMD'], ['CMD-REPORT-2', 'Mug', '3,00', 'Cuisine CMD']]), '--save-mapping' => $this->profileName()]);

        self::assertSame(ImportCatalogCommand::EXIT_ROWS_FAILED, $status);
        self::assertMatchesRegularExpression('/^\s*2\s+failed\s+CMD-REPORT-1\s/m', $tester->getDisplay());
        self::assertDoesNotMatchRegularExpression('/^\s*3\s+created\s/m', $tester->getDisplay(), 'a created line stays in the stored report, the console shows the lines that need attention');
    }

    public function testAKnownProfileIsReusedByNameAndByIdentifier(): void
    {
        $name = $this->profileName();
        $this->tester()->execute(['file' => $this->file([['Référence', 'Titre', 'Prix HT', 'Catégorie'], ['CMD-5', 'Mug', '1,00', 'Cuisine CMD']]), '--save-mapping' => $name]);
        $profile = (new ProfileRepository())->findByName($name);

        $byName = $this->tester();
        $byId = $this->tester();

        self::assertSame(0, $byName->execute(['file' => $this->file([['Référence', 'Titre', 'Prix HT', 'Catégorie'], ['CMD-5', 'Mug', '2,00', 'Cuisine CMD']]), '--mapping' => $name]));
        self::assertSame('2.000000', $this->priceOf('CMD-5'));
        self::assertSame(0, $byId->execute(['file' => $this->file([['Référence', 'Titre', 'Prix HT', 'Catégorie'], ['CMD-5', 'Mug', '3,00', 'Cuisine CMD']]), '--mapping' => (string) $profile->id]));
        self::assertSame('3.000000', $this->priceOf('CMD-5'));
    }

    public function testTheCommandRefusesWhatItCannotRunWithoutTouchingTheCatalog(): void
    {
        $name = $this->profileName();
        $existing = $this->tester();
        $existing->execute(['file' => $this->file([['Référence', 'Titre', 'Catégorie'], ['CMD-6', 'Mug', 'Cuisine CMD']]), '--save-mapping' => $name]);
        $valid = $this->file([['Référence', 'Titre', 'Catégorie'], ['CMD-7', 'Mug', 'Cuisine CMD']]);

        foreach ([
            ['file' => $valid],
            ['file' => $valid, '--mapping' => 'unknown profile'],
            ['file' => $valid, '--mapping' => $name, '--save-mapping' => 'other'],
            ['file' => $valid, '--save-mapping' => $name],
            ['file' => $valid, '--mapping' => $name, '--mode' => 'overwrite'],
            ['file' => '/nonexistent/file.csv', '--mapping' => $name],
        ] as $arguments) {
            $tester = $this->tester();

            self::assertSame(Command::INVALID, $tester->execute($arguments), json_encode($arguments));
        }

        self::assertNull($this->product('CMD-7'));
    }

    public function testTheTextOfTheFileCannotInjectConsoleFormatting(): void
    {
        $tester = $this->tester();

        $tester->execute(['file' => $this->file([['Référence', 'Titre', 'Prix HT', 'Catégorie'], ['<error>X</error>', 'Mug', 'abc', 'Cuisine CMD']]), '--save-mapping' => $this->profileName()], ['decorated' => true]);

        self::assertStringNotContainsString("\033[37;41m", $tester->getDisplay());
        self::assertStringContainsString('<error>X</error>', $tester->getDisplay());
    }

    public function testTheNameOfTheFileCannotInjectConsoleFormatting(): void
    {
        $directory = sys_get_temp_dir().'/csv-importer-name-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $path = $directory.'/<bg=red>x.csv';
        file_put_contents($path, $this->csv([['Référence', 'Titre', 'Prix HT', 'Catégorie'], ['CMD-9', 'Mug', '1,00', 'Cuisine CMD']]));
        $tester = $this->tester();

        $tester->execute(['file' => $path, '--save-mapping' => $this->profileName(), '--dry-run' => true], ['decorated' => true]);
        @unlink($path);
        @rmdir($directory);

        self::assertStringNotContainsString("\033[41m", $tester->getDisplay());
        self::assertStringContainsString('<bg=red>x.csv', $tester->getDisplay());
    }
}
