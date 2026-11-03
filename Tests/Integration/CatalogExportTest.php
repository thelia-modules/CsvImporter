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

use CsvImporter\Export\CatalogExporter;
use CsvImporter\Reader\CsvFileReader;
use Thelia\Model\Currency;
use Thelia\Test\IntegrationTestCase;

final class CatalogExportTest extends IntegrationTestCase
{
    use ImportTestSupport;

    private const HEADER = ['Référence', 'Désignation', 'Description courte', 'Description', 'Prix HT', 'Stock', 'Poids', 'Famille', 'Marque', 'Gabarit', 'EAN', 'C:Couleur'];

    private function source(): string
    {
        return $this->csv([
            self::HEADER,
            ['EXP-1', 'Chaise "pliante" 😀', 'Courte', 'Longue', '12,5', '7', '1,25', 'Maison EXP > Cuisine EXP', 'Marque EXP', 'Gabarit EXP', '4006381333931', 'Rouge'],
            ['EXP-2', 'Table', '', '', '1234,56', '0', '', 'Maison EXP', '', '', '', ''],
            ['EXP-3', 'Étagère a>b', 'x', '<p>Paragraphe</p>', '3', '2', '0,5', 'Famille \> escapée', '', '', '', 'Bleu'],
            ['EXP-4', '=HYPERLINK("http://x")', 'a', '<p class="c" style="color:red">Texte<br>suite</p>', '5,25', '1', '1,2340', 'Maison EXP', 'Marque \\"EXP4\\"', '', '4006381333-931', 'Rouge|Bleu'],
            ['EXP-5', "'=déjà un apostrophe", '', '', '1,5', '3', '12,3450', 'Maison EXP', '', '', '', ''],
        ]);
    }

    private function exported(): array
    {
        $path = sys_get_temp_dir().'/csv-importer-export-'.bin2hex(random_bytes(6)).'.csv';
        $this->temporaryFiles[] = $path;

        (new CatalogExporter())->export($path, $this->locale(), Currency::getDefaultCurrency()->getId());

        return [$path, $this->ourLines($path)];
    }

    private function ourLines(string $path): string
    {
        $in = fopen($path, 'r');
        fseek($in, 3);
        $out = fopen('php://memory', 'w+');
        $header = true;

        while (false !== $record = fgetcsv($in, null, ';', '"', '')) {
            if ($header || str_starts_with((string) $record[0], 'EXP-')) {
                fputcsv($out, $record, ';', '"', '', "\n");
            }

            $header = false;
        }

        rewind($out);

        return "\xEF\xBB\xBF".stream_get_contents($out);
    }

    public function testTheExportCarriesEveryImportedFieldInTheFormatTheImportReads(): void
    {
        $this->importCsv($this->source());
        [$path] = $this->exported();

        $rows = [];

        foreach ((new CsvFileReader($path))->rows() as $row) {
            if (str_starts_with($row->cells['Référence'] ?? '', 'EXP-')) {
                $rows[$row->cells['Référence']] = $row->cells;
            }
        }

        self::assertCount(5, $rows);
        self::assertSame('Rouge|Bleu', $rows['EXP-4']['C:Couleur']);
        self::assertSame('1,2340', $rows['EXP-4']['Poids']);
        self::assertSame('12,3450', $rows['EXP-5']['Poids']);
        self::assertSame('Marque \\"EXP4\\"', $rows['EXP-4']['Marque']);
        self::assertSame('4006381333931', $rows['EXP-4']['EAN']);
        self::assertStringContainsString(';"\'=HYPERLINK(""http://x"")"', (string) file_get_contents($path));
        self::assertSame('=HYPERLINK("http://x")', $rows['EXP-4']['Titre']);
        self::assertSame('Chaise "pliante" 😀', $rows['EXP-1']['Titre']);
        self::assertSame('12,5', $rows['EXP-1']['Prix HT']);
        self::assertSame('Maison EXP > Cuisine EXP', $rows['EXP-1']['Catégorie']);
        self::assertSame('Marque EXP', $rows['EXP-1']['Marque']);
        self::assertSame('Rouge', $rows['EXP-1']['C:Couleur']);
        self::assertSame('4006381333931', $rows['EXP-1']['EAN']);
        self::assertSame('1234,56', $rows['EXP-2']['Prix HT']);
        self::assertSame('Famille \> escapée', $rows['EXP-3']['Catégorie']);
    }

    public function testWhatTheExportWritesImportsBackAsUnchangedAndExportsAgainIdentically(): void
    {
        $this->importCsv($this->source());
        [, $first] = $this->exported();

        $job = $this->importCsv($first);

        self::assertSame(5, $job->getUnchangedRows(), implode(' | ', array_map(static fn ($line): string => (string) $line->getMessage(), $this->reportLines($job, 'failed'))));
        self::assertSame(0, $job->getFailedRows());

        [, $second] = $this->exported();

        self::assertSame($first, $second);
    }
}
