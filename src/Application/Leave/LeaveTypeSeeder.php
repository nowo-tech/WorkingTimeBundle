<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveSeedNamesInterface;
use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceSource;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeInput;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

use function substr;

/**
 * The leave types seeded when a tenant enables leave (contract section 9.3; spec 204 FR-003; Beacon's
 * `LeaveTypeSeeder`, art. 37.3 / 38 ET defaults, each editable). Idempotent: a code that exists is never seeded again.
 * Names are stored in every shipped locale and shown in the reader's. Vacation also gets the tenant default of the
 * current year: 22 working days (the usual working-day equivalent of the 30 natural days of art. 38 ET). Runs inside
 * the caller's write unit.
 */
final readonly class LeaveTypeSeeder
{
    /** The vacation default of the current year, in half-days (22 working days). */
    public const VACATION_DEFAULT = 44;

    /**
     * code => [unit, source, paid, needs approval, max per request (stored unit), colour, medical].
     *
     * @var array<string, array{0: LeaveUnit, 1: AllowanceSource, 2: bool, 3: bool, 4: ?int, 5: string, 6: bool}>
     */
    public const SEEDS = [
        'vacation'            => [LeaveUnit::WorkingDays, AllowanceSource::Allowance, true, true, null, 'blue', false],
        'personal'            => [LeaveUnit::WorkingDays, AllowanceSource::Allowance, true, true, null, 'teal', false],
        'compensatory'        => [LeaveUnit::Hours, AllowanceSource::Overtime, true, true, null, 'purple', false],
        'marriage'            => [LeaveUnit::NaturalDays, AllowanceSource::None, true, true, 30, 'orange', false],
        'family'              => [LeaveUnit::WorkingDays, AllowanceSource::None, true, true, 10, 'yellow', false],
        'bereavement'         => [LeaveUnit::WorkingDays, AllowanceSource::None, true, true, 8, 'grey', false],
        'moving'              => [LeaveUnit::WorkingDays, AllowanceSource::None, true, true, 2, 'sky', false],
        'public_duty'         => [LeaveUnit::Hours, AllowanceSource::None, true, true, null, 'sky', false],
        'unpaid'              => [LeaveUnit::WorkingDays, AllowanceSource::None, false, true, null, 'grey', false],
        'absence'             => [LeaveUnit::WorkingDays, AllowanceSource::None, false, true, null, 'vermillion', false],
        'medical'             => [LeaveUnit::NaturalDays, AllowanceSource::None, false, false, null, 'grey', true],
        'medical_appointment' => [LeaveUnit::Hours, AllowanceSource::None, true, false, null, 'grey', true],
        'work_accident'       => [LeaveUnit::NaturalDays, AllowanceSource::None, false, false, null, 'grey', true],
    ];

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private LeaveStoreInterface $store,
        private LeaveSeedNamesInterface $names,
        private IdGeneratorInterface $ids,
    ) {
    }

    /**
     * Seeds the missing types (inside the caller's write unit); returns how many were added.
     */
    public function seed(TenantRef $tenant, string $locale, DateTimeImmutable $at, ?ActorRef $by): int
    {
        $added = 0;
        $order = 0;
        foreach (self::SEEDS as $code => [$unit, $source, $paid, $approval, $max, $colour, $medical]) {
            $order += 10;
            if ($this->store->typeBySeed($tenant, $code) instanceof LeaveType) {
                continue;
            }
            $names = $this->names->names($code);
            $name  = $names[$locale] ?? $names[substr($locale, 0, 2)] ?? $names['en'] ?? $code;
            $type  = LeaveType::create($this->ids->generate(), $tenant->value, new LeaveTypeInput($name, $paid, $source, $unit, $approval, false, $medical, $colour, $max), $locale, $at, $code, $order, $names);
            $this->store->addType($type); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            ++$added;
            if ($code === 'vacation') {
                $this->store->addPolicy(AllowancePolicy::create($this->ids->generate(), $type, ScheduleScope::Tenant, null, (int) $at->format('Y'), self::VACATION_DEFAULT, false, null, $at, $by)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }

        return $added;
    }
}
