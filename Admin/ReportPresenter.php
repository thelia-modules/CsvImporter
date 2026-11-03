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

namespace CsvImporter\Admin;

use CsvImporter\Model\CsvImporterJobRow;
use CsvImporter\Report\ReportMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ReportPresenter
{
    public const DOMAIN = 'csvimporter.bo.default-twig';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function text(ReportMessage $message): string
    {
        return $this->translator->trans($message->key, $this->stringParameters($message->parameters), self::DOMAIN);
    }

    public function textOfJson(?string $json): string
    {
        if (null === $json || '' === $json) {
            return '';
        }

        try {
            return $this->text(ReportMessage::fromJson($json));
        } catch (\JsonException|\TypeError) {
            return '';
        }
    }

    /**
     * @return array{line: ?int, outcome: string, reference: string, message: string}
     */
    public function line(CsvImporterJobRow $row): array
    {
        return [
            'line' => 0 === $row->getLineNumber() ? null : $row->getLineNumber(),
            'outcome' => $row->getOutcome(),
            'reference' => (string) $row->getReference(),
            'message' => $this->textOfJson($row->getMessage()),
        ];
    }

    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return array<string, string>
     */
    private function stringParameters(array $parameters): array
    {
        $strings = [];

        foreach ($parameters as $key => $value) {
            $text = \is_scalar($value) ? (string) $value : '';
            $strings[(string) $key] = '%kind' === $key ? $this->translator->trans('kind.'.$text, [], self::DOMAIN) : $text;
        }

        return $strings;
    }
}
