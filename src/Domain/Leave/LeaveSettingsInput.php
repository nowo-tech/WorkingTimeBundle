<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave;

use Nowo\WorkingTimeBundle\Domain\Leave\Enum\RoundingMode;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;

use function checkdate;
use function preg_match;

/**
 * The tenant's leave settings a form edits (contract section 6.6; spec 204 FR-002). Approval chains and staffing
 * minimums arrive with the request workflow (S3) and are kept as they are by a save.
 */
final readonly class LeaveSettingsInput
{
    /**
     * Wires and checks the values.
     *
     * @throws LeaveRefusedException `leave.settings.error.*`
     */
    public function __construct(
        public bool $enabled = false,
        public RoundingMode $rounding = RoundingMode::Nearest,
        public int $protectedCarryMonths = 18,
        public bool $colleaguesSeeAbsences = true,
        public bool $medicalDocuments = false,
        public int $attachmentRetentionMonths = 12,
        public int $overtimeRatio = 100,
        public ?string $planningDeadline = null,
    ) {
        if ($protectedCarryMonths < 1 || $protectedCarryMonths > 60) {
            throw new LeaveRefusedException('leave.settings.error.protected_months');
        }
        if ($attachmentRetentionMonths < 1 || $attachmentRetentionMonths > 48) {
            throw new LeaveRefusedException('leave.settings.error.attachment_months');
        }
        if ($overtimeRatio < 1 || $overtimeRatio > 400) {
            throw new LeaveRefusedException('leave.settings.error.overtime_ratio');
        }
        if ($planningDeadline !== null && (preg_match('/^(\d{2})-(\d{2})$/D', $planningDeadline, $m) !== 1 || !checkdate((int) $m[1], (int) $m[2], 2023))) {
            throw new LeaveRefusedException('leave.settings.error.planning_deadline');
        }
    }
}
