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

namespace CsvImporter\Import;

use CsvImporter\Report\ReportMessage;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RowResult
{
    public function __construct(
        public int $lineNumber,
        public RowOutcome $outcome,
        public ?string $reference,
        public ?ReportMessage $message = null,
        public array $warnings = [],
        public array $creations = [],
    ) {
    }

    public function withCreations(array $creations): self
    {
        return new self($this->lineNumber, $this->outcome, $this->reference, $this->message, $this->warnings, $creations);
    }
}
