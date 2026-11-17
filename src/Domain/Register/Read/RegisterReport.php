<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Read;

use DateTimeImmutable;

/**
 * One worker's register for a range of months, translated and laid out for a renderer (spec 198 FR-021 / FR-022): key
 * figures, one row per day, the corrections with their reasons and who approved or recorded them, and the integrity
 * block (generation date, who generated it, the chain head, the result of the check). Every page header repeats the
 * worker, the period and the chain head (`header`). Plain strings only: the renderer escapes them.
 */
final readonly class RegisterReport
{
    /**
     * @param list<array{0: string, 1: string}> $figures label, value
     * @param list<string> $dayHeaders
     * @param list<list<string>> $dayRows
     * @param list<string> $correctionHeaders
     * @param list<list<string>> $correctionRows
     * @param list<array{0: string, 1: string}> $integrity label, value
     * @param array<string, string> $labels section titles (`summary`, `days`, `corrections`, `integrity`, `empty`)
     */
    public function __construct(
        public string $title,
        public string $header,
        public DateTimeImmutable $generatedAt,
        public array $figures,
        public array $dayHeaders,
        public array $dayRows,
        public array $correctionHeaders,
        public array $correctionRows,
        public array $integrity,
        public string $legalNote,
        public string $chainHead,
        public string $locale,
        public array $labels = [],
    ) {
    }
}
