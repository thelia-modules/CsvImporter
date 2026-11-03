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

use CsvImporter\Mapping\CatalogField;
use CsvImporter\Mapping\ColumnTarget;
use CsvImporter\Mapping\HeaderGuesser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeaderGuesserTest extends TestCase
{
    #[DataProvider('supplierHeaders')]
    public function testItProposesTheFieldASupplierHeaderMeans(string $header, CatalogField $expected): void
    {
        $proposal = (new HeaderGuesser())->guess([$header]);

        self::assertEquals(new ColumnTarget($expected), $proposal[$header]);
    }

    public static function supplierHeaders(): iterable
    {
        yield ['Référence', CatalogField::Reference];
        yield ['REF', CatalogField::Reference];
        yield ['Code produit', CatalogField::Reference];
        yield ['sku', CatalogField::Reference];
        yield ['Réf fournisseur', CatalogField::Reference];
        yield ['Référence fournisseur', CatalogField::Reference];
        yield ['Qté dispo', CatalogField::Stock];
        yield ['Quantité disponible', CatalogField::Stock];
        yield ['Désignation', CatalogField::Title];
        yield ['Titre', CatalogField::Title];
        yield ['Description courte', CatalogField::Chapo];
        yield ['Description', CatalogField::Description];
        yield ['Description longue', CatalogField::Description];
        yield ['PRIX HT', CatalogField::Price];
        yield ['Prix de vente', CatalogField::Price];
        yield ['Prix promo', CatalogField::PromoPrice];
        yield ['Quantité', CatalogField::Stock];
        yield ['Stock', CatalogField::Stock];
        yield ['Poids', CatalogField::Weight];
        yield ['Famille', CatalogField::Category];
        yield ['Catégorie', CatalogField::Category];
        yield ['Marque', CatalogField::Brand];
        yield ['Gabarit', CatalogField::Template];
        yield ['URL image', CatalogField::Image];
        yield ['Photo', CatalogField::Image];
        yield ['EAN13', CatalogField::Ean];
        yield ['Code-barres', CatalogField::Ean];
    }

    public function testItLeavesAnUnknownHeaderUnmapped(): void
    {
        $proposal = (new HeaderGuesser())->guess(['Colour code', 'Prix TTC']);

        self::assertNull($proposal['Colour code']);
        self::assertNull($proposal['Prix TTC']);
    }

    public function testTheSupplierReferenceNeverTakesTheFieldFromTheProductReference(): void
    {
        $proposal = (new HeaderGuesser())->guess(['Réf fournisseur', 'Référence', 'Qté dispo', 'Stock']);

        self::assertNull($proposal['Réf fournisseur']);
        self::assertEquals(new ColumnTarget(CatalogField::Reference), $proposal['Référence']);
        self::assertNull($proposal['Qté dispo']);
        self::assertEquals(new ColumnTarget(CatalogField::Stock), $proposal['Stock']);
    }

    public function testAFieldIsProposedOnceAndTheFirstHeaderWins(): void
    {
        $proposal = (new HeaderGuesser())->guess(['Prix', 'Tarif', 'Marque']);

        self::assertEquals(new ColumnTarget(CatalogField::Price), $proposal['Prix']);
        self::assertNull($proposal['Tarif']);
        self::assertEquals(new ColumnTarget(CatalogField::Brand), $proposal['Marque']);
    }

    public function testAFeatureAndAnAttributeKeepTheirName(): void
    {
        $proposal = (new HeaderGuesser())->guess(['C:Couleur', 'C:Contenance', 'D:Taille']);

        self::assertEquals(new ColumnTarget(CatalogField::Feature, 'Couleur'), $proposal['C:Couleur']);
        self::assertEquals(new ColumnTarget(CatalogField::Feature, 'Contenance'), $proposal['C:Contenance']);
        self::assertEquals(new ColumnTarget(CatalogField::Attribute, 'Taille'), $proposal['D:Taille']);
    }

    public function testItKeepsTheOrderOfTheHeaders(): void
    {
        $proposal = (new HeaderGuesser())->guess(['Stock', 'Référence', 'Autre']);

        self::assertSame(['Stock', 'Référence', 'Autre'], array_keys($proposal));
    }
}
