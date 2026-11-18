<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Enum;

/**
 * What a leave type draws from (spec 204 FR-003 / FR-028): nothing, the yearly allowance (vacation, personal days) or
 * the ratified overtime credit (compensatory time off, S3).
 */
enum AllowanceSource: string
{
    case None      = 'none';
    case Allowance = 'allowance';
    case Overtime  = 'overtime';

    /** Translation key of the label. */
    public function labelKey(): string
    {
        return 'leave.source.' . $this->value;
    }
}
