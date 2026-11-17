<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use Nowo\WorkingTimeBundle\Domain\Register\Exception\SettingsRefusedException;

use function is_string;
use function mb_strlen;
use function preg_match;
use function trim;

/**
 * The values of a tenant's register settings (contract section 6.2 `working_time_settings`), checked as a whole. The
 * refusals carry the translation key of the field.
 */
final readonly class RegisterSettingsInput
{
    /** Shortest retention (art. 34.9 ET: four years). */
    public const MIN_RETENTION_YEARS = 4;

    /** Longest retention. */
    public const MAX_RETENTION_YEARS = 10;

    /** Longest IP-evidence notice. */
    public const NOTICE_MAX = 2000;

    /**
     * @param array<string, string> $ipNotices locale => notice text shown to workers
     *
     * @throws SettingsRefusedException
     */
    public function __construct(
        public bool $enabled = false,
        public bool $breaksCounted = false,
        public int $flexMinutes = 0,
        public int $minBreakMinutes = 0,
        public ?int $dailyOvertimeMinutes = null,
        public ?int $weeklyOvertimeMinutes = null,
        public bool $taskTimerLink = false,
        public bool $remindersAllowed = false,
        public bool $monthlySummaryMandatory = false,
        public bool $ipEvidence = false,
        public array $ipNotices = [],
        public ?string $ipNoticeLocale = null,
        public int $retentionYears = self::MIN_RETENTION_YEARS,
        public int $correctionWindowDays = 31,
    ) {
        if ($flexMinutes < 0 || $flexMinutes > ScheduleDefinition::MAX_FLEX_MINUTES) {
            throw new SettingsRefusedException('flexMinutes', 'settings.error.flex');
        }
        if ($minBreakMinutes < 0 || $minBreakMinutes > 120) {
            throw new SettingsRefusedException('minBreakMinutes', 'settings.error.min_break');
        }
        if ($dailyOvertimeMinutes !== null && ($dailyOvertimeMinutes < 1 || $dailyOvertimeMinutes > ScheduleDefinition::MAX_DAY_MINUTES)) {
            throw new SettingsRefusedException('dailyOvertimeMinutes', 'settings.error.daily_overtime');
        }
        if ($weeklyOvertimeMinutes !== null && ($weeklyOvertimeMinutes < 1 || $weeklyOvertimeMinutes > 7 * ScheduleDefinition::MAX_DAY_MINUTES)) {
            throw new SettingsRefusedException('weeklyOvertimeMinutes', 'settings.error.weekly_overtime');
        }
        if ($retentionYears < self::MIN_RETENTION_YEARS || $retentionYears > self::MAX_RETENTION_YEARS) {
            throw new SettingsRefusedException('retentionYears', 'settings.error.retention');
        }
        if ($correctionWindowDays < 1 || $correctionWindowDays > 31) {
            throw new SettingsRefusedException('correctionWindowDays', 'settings.error.correction_window');
        }
        foreach ($ipNotices as $locale => $text) {
            if (!is_string($locale) || preg_match('/^[a-z]{2}(_[A-Z]{2})?$/D', $locale) !== 1 || mb_strlen($text) > self::NOTICE_MAX) {
                throw new SettingsRefusedException('ipNotices', 'settings.error.ip_notice');
            }
        }
        if ($ipEvidence && ($ipNoticeLocale === null || trim($ipNotices[$ipNoticeLocale] ?? '') === '')) {
            throw new SettingsRefusedException('ipNotices', 'settings.error.ip_notice_required');
        }
    }
}
