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

namespace CsvImporter\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TranslationCatalogTest extends TestCase
{
    private const SOURCE_DIRECTORIES = ['Admin', 'Controller', 'Import', 'Image', 'Lookup', 'Mapping', 'Parsing', 'Reader', 'Job'];

    /** English texts that are not shown to the administrator: logs, developer errors, fragments */
    private const NOT_DISPLAYED = [
        'The back-office theme in use cannot render the screens: they need default-twig',
        'The export file cannot be written',
        'The decimal separator is a dot or a comma',
        'The screens need a back-office request',
        'The column "',
    ];

    public function testEveryMessageTheScreensCanShowIsTranslatedInFrench(): void
    {
        $catalog = require \dirname(__DIR__, 2).'/I18n/backOffice/default-twig/fr_FR.php';
        $missing = [];

        foreach (self::SOURCE_DIRECTORIES as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/'.$directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }

                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $matches);

                foreach ($matches[1] as $literal) {
                    $text = str_replace("\\'", "'", $literal);

                    if (1 === preg_match('/^[A-Z][a-z]+ .{8,}/', $text) && !str_contains($text, '%s') && !str_contains($text, '%d') && !\in_array($text, self::NOT_DISPLAYED, true) && !isset($catalog[$text])) {
                        $missing[$text] = $file->getFilename();
                    }
                }
            }
        }

        self::assertSame([], $missing, 'Messages without a French translation');
    }

    public function testEveryOutcomeAndEveryFieldTheScreensListHasALabelInBothLocales(): void
    {
        $keys = [];

        foreach (\CsvImporter\Import\RowOutcome::cases() as $case) {
            $keys[] = 'outcome.'.$case->value;
        }

        foreach (['warning', 'creation', 'image', 'info'] as $outcome) {
            $keys[] = 'outcome.'.$outcome;
        }

        foreach (\CsvImporter\Mapping\CatalogField::cases() as $field) {
            if (\CsvImporter\Mapping\CatalogField::Attribute !== $field) {
                $keys[] = 'field.'.$field->value;
            }
        }

        foreach (['fr_FR', 'en_US'] as $locale) {
            $catalog = require \dirname(__DIR__, 2).'/I18n/backOffice/default-twig/'.$locale.'.php';

            self::assertSame([], array_values(array_diff($keys, array_keys($catalog))), $locale);
        }
    }

    public function testTheFrenchCatalogKeepsTheSamePlaceholdersAsTheEnglishText(): void
    {
        $catalog = require \dirname(__DIR__, 2).'/I18n/backOffice/default-twig/fr_FR.php';
        $broken = [];

        foreach ($catalog as $english => $french) {
            preg_match_all('/%[a-z]+%?/', $english, $inEnglish);
            preg_match_all('/%[a-z]+%?/', $french, $inFrench);

            if ([] !== array_diff($inEnglish[0], $inFrench[0])) {
                $broken[$english] = $french;
            }
        }

        self::assertSame([], $broken);
    }
}
