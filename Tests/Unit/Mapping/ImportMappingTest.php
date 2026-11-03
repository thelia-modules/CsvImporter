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

use CsvImporter\Import\ImportMode;
use CsvImporter\Mapping\CatalogField;
use CsvImporter\Mapping\ColumnTarget;
use CsvImporter\Mapping\HeaderGuesser;
use CsvImporter\Mapping\ImportMapping;
use CsvImporter\Mapping\MappingValidator;
use PHPUnit\Framework\TestCase;

final class ImportMappingTest extends TestCase
{
    public function testItSurvivesAJsonRoundTrip(): void
    {
        $mapping = new ImportMapping([
            'Référence' => new ColumnTarget(CatalogField::Reference),
            'Désignation 😀' => new ColumnTarget(CatalogField::Title),
            'C:Couleur' => new ColumnTarget(CatalogField::Feature, 'Couleur'),
        ]);

        self::assertEquals($mapping, ImportMapping::fromJson($mapping->toJson()));
    }

    public function testItIsBuiltFromAProposalWithoutTheUnmappedHeaders(): void
    {
        $mapping = ImportMapping::fromProposal((new HeaderGuesser())->guess(['Référence', 'Autre', 'Prix HT']));

        self::assertSame('Référence', $mapping->headerFor(CatalogField::Reference));
        self::assertSame('Prix HT', $mapping->headerFor(CatalogField::Price));
        self::assertNull($mapping->targetOf('Autre'));
    }

    public function testItGivesTheHeaderOfAFieldAndNullForAnUnmappedOne(): void
    {
        $mapping = new ImportMapping(['Réf' => new ColumnTarget(CatalogField::Reference)]);

        self::assertSame('Réf', $mapping->headerFor(CatalogField::Reference));
        self::assertNull($mapping->headerFor(CatalogField::Title));
    }

    public function testItListsTheFeatureColumnsWithTheirName(): void
    {
        $mapping = new ImportMapping([
            'C:Couleur' => new ColumnTarget(CatalogField::Feature, 'Couleur'),
            'Contenance' => new ColumnTarget(CatalogField::Feature, 'Contenance (ml)'),
            'Réf' => new ColumnTarget(CatalogField::Reference),
        ]);

        self::assertSame(['C:Couleur' => 'Couleur', 'Contenance' => 'Contenance (ml)'], $mapping->featureHeaders());
    }

    public function testItRefusesAJsonThatIsNotAMapping(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImportMapping::fromJson('{"Réf":{"field":"nothing"}}');
    }

    public function testAMappingWithoutReferenceIsRefused(): void
    {
        $issues = (new MappingValidator())->validate(new ImportMapping(['Titre' => new ColumnTarget(CatalogField::Title)]), ImportMode::CreateOrUpdate);

        self::assertSame(['The column holding the reference is required'], array_map(static fn ($issue) => $issue->key, $issues));
    }

    public function testAFieldMappedTwiceIsRefused(): void
    {
        $issues = (new MappingValidator())->validate(new ImportMapping([
            'Réf' => new ColumnTarget(CatalogField::Reference),
            'Code' => new ColumnTarget(CatalogField::Reference),
        ]), ImportMode::UpdateOnly);

        self::assertCount(1, $issues);
        self::assertSame('The field "%field" is mapped to several columns', $issues[0]->key);
    }

    public function testACombinationColumnIsRefusedForNow(): void
    {
        $issues = (new MappingValidator())->validate(new ImportMapping([
            'Réf' => new ColumnTarget(CatalogField::Reference),
            'D:Taille' => new ColumnTarget(CatalogField::Attribute, 'Taille'),
        ]), ImportMode::CreateOrUpdate);

        self::assertCount(1, $issues);
        self::assertSame('The column "%column" holds combinations, which this version does not import yet', $issues[0]->key);
    }

    public function testAValidMappingHasNoIssue(): void
    {
        $issues = (new MappingValidator())->validate(new ImportMapping([
            'Réf' => new ColumnTarget(CatalogField::Reference),
            'Prix' => new ColumnTarget(CatalogField::Price),
        ]), ImportMode::CreateOrUpdate);

        self::assertSame([], $issues);
    }
}
