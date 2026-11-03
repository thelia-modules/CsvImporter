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

namespace CsvImporter\Profile;

use CsvImporter\Mapping\ImportMapping;
use CsvImporter\Model\CsvImporterProfile;
use CsvImporter\Model\CsvImporterProfileQuery;

final readonly class ProfileRepository
{
    public function save(ImportProfile $profile): ImportProfile
    {
        $row = null === $profile->id ? new CsvImporterProfile() : (CsvImporterProfileQuery::create()->findPk($profile->id) ?? new CsvImporterProfile());

        $row
            ->setName($profile->name)
            ->setDelimiter($profile->delimiter)
            ->setEncoding($profile->encoding)
            ->setDecimalSeparator($profile->decimalSeparator)
            ->setMapping($profile->mapping->toJson())
            ->save();

        return $this->toProfile($row);
    }

    /**
     * True when the profile was saved after the given date, or when the profile or one of the
     * dates is missing. Whole seconds: the database keeps no more, and an instance still in
     * memory would carry microseconds, so a change within the same second is not seen.
     */
    public function changedSince(?int $id, ?\DateTimeInterface $since): bool
    {
        $updated = null === $id ? null : CsvImporterProfileQuery::create()->findPk($id)?->getUpdatedAt();

        return null === $updated || null === $since || $updated->getTimestamp() > $since->getTimestamp();
    }

    public function find(int $id): ?ImportProfile
    {
        $row = CsvImporterProfileQuery::create()->findPk($id);

        return null === $row ? null : $this->toProfile($row);
    }

    public function findByName(string $name): ?ImportProfile
    {
        $row = CsvImporterProfileQuery::create()->findOneByName($name);

        return null === $row ? null : $this->toProfile($row);
    }

    public function all(): array
    {
        return array_map($this->toProfile(...), CsvImporterProfileQuery::create()->orderByName()->find()->getData());
    }

    private function toProfile(CsvImporterProfile $row): ImportProfile
    {
        return new ImportProfile(
            $row->getId(),
            $row->getName(),
            $row->getDelimiter(),
            $row->getEncoding(),
            $row->getDecimalSeparator(),
            ImportMapping::fromJson($row->getMapping()),
        );
    }
}
