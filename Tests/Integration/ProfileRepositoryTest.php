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

use CsvImporter\Mapping\CatalogField;
use CsvImporter\Mapping\ColumnTarget;
use CsvImporter\Mapping\ImportMapping;
use CsvImporter\Profile\ImportProfile;
use CsvImporter\Profile\ProfileRepository;
use Thelia\Test\IntegrationTestCase;

final class ProfileRepositoryTest extends IntegrationTestCase
{
    private function profile(string $name): ImportProfile
    {
        return new ImportProfile(null, $name, ';', 'CP1252', ',', new ImportMapping([
            'Réf' => new ColumnTarget(CatalogField::Reference),
            'C:Couleur' => new ColumnTarget(CatalogField::Feature, 'Couleur'),
        ]));
    }

    public function testAProfileIsSavedUnderANameAndFoundAgain(): void
    {
        $repository = new ProfileRepository();

        $saved = $repository->save($this->profile('Fournisseur Dupont 😀'));

        self::assertNotNull($saved->id);
        self::assertEquals($saved, $repository->find($saved->id));
        self::assertEquals($saved, $repository->findByName('Fournisseur Dupont 😀'));
        self::assertSame(';', $saved->delimiter);
        self::assertSame(',', $saved->decimalSeparator);
    }

    public function testSavingAgainUnderTheSameIdUpdatesTheProfile(): void
    {
        $repository = new ProfileRepository();
        $saved = $repository->save($this->profile('Profil A'));

        $repository->save(new ImportProfile($saved->id, 'Profil A renommé', null, null, null, $saved->mapping));

        self::assertSame('Profil A renommé', $repository->find($saved->id)->name);
        self::assertNull($repository->find($saved->id)->delimiter);
        self::assertCount(1, array_filter($repository->all(), static fn (ImportProfile $profile): bool => $profile->id === $saved->id));
    }

    public function testAnUnknownProfileIsNull(): void
    {
        self::assertNull((new ProfileRepository())->find(999999999));
        self::assertNull((new ProfileRepository())->findByName('nope'));
    }
}
