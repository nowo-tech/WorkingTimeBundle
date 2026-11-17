<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

/**
 * Format of a register export: one worker's PDF or CSV, or the team ZIP (PDF + CSV per worker, team CSV, SHA256SUMS).
 */
enum ExportFormat: string
{
    case Pdf = 'pdf';
    case Csv = 'csv';
    case Zip = 'zip';
}
