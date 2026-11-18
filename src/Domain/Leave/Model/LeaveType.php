<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Model;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceSource;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeInput;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

use function checkdate;
use function in_array;
use function is_string;
use function mb_strlen;
use function preg_match;
use function preg_replace;
use function reset;
use function substr;
use function trim;

/**
 * A kind of leave of one tenant (contract section 6.6; spec 204 FR-003 / FR-004): seeded on enabling (`seed_code`),
 * editable, archivable; archived types stay on old records and are never deleted while referenced.
 *
 * Privacy (GDPR art. 9; owner fixed point "no health detail anywhere in leave"): there is no field for a diagnosis, a
 * cause or any health detail. The *medical* flag only makes the type show as *Absent* to everyone without `leave_manage`
 * and lets a request carry *justified yes / no* and, when the tenant allows it, a document (S3).
 */
class LeaveType
{
    /** Colour-blind-safe palette tokens (`nowo-wt-leave--<token>`). */
    public const COLOURS = ['blue', 'orange', 'teal', 'purple', 'yellow', 'sky', 'vermillion', 'grey'];

    /** Longest name. */
    public const NAME_MAX = 80;

    /** @var array<mixed> locale => name (the seeder's translations, or the one edited name) */
    private array $names = [];

    private bool $paid = true;

    private AllowanceSource $allowanceSource = AllowanceSource::None;

    private LeaveUnit $unit = LeaveUnit::WorkingDays;

    private bool $needsApproval = true;

    private bool $needsAttachment = false;

    private bool $medical = false;

    private string $colour = 'blue';

    private ?int $maxPerRequest = null;

    private bool $carryOver = false;

    private ?int $carryOverMax = null;

    private ?string $carryOverExpiry = null;

    private bool $archived = false;

    private function __construct(
        private string $id,
        private string $tenantId,
        private ?string $seedCode,
        private int $sortOrder,
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * A new type; `$names` holds the seeded translations (locale => name), else the input's name is kept under the
     * locale.
     *
     * @param array<string, string> $names
     *
     * @throws LeaveRefusedException `leave.type.error.*`
     */
    public static function create(string $id, string $tenantId, LeaveTypeInput $input, string $locale, DateTimeImmutable $at, ?string $seedCode = null, int $sortOrder = 1000, array $names = []): self
    {
        $type = new self(Uuid::assert($id), $tenantId, $seedCode, $sortOrder, $at->setTimezone(new DateTimeZone('UTC')));
        $type->change($input, $locale);
        if ($names !== []) {
            $type->names = $names;
        }

        return $type;
    }

    /**
     * Changes the attributes. A changed name replaces the translations with the one edited name.
     *
     * @throws LeaveRefusedException `leave.type.error.*`
     */
    public function change(LeaveTypeInput $input, string $locale): void
    {
        $name = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $input->name));
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new LeaveRefusedException('leave.type.error.name');
        }
        if (!in_array($input->colour, self::COLOURS, true)) {
            throw new LeaveRefusedException('leave.type.error.colour');
        }
        if ($input->maxPerRequest !== null && $input->maxPerRequest <= 0) {
            throw new LeaveRefusedException('leave.type.error.max');
        }
        if ($input->source === AllowanceSource::Overtime && $input->unit !== LeaveUnit::Hours) {
            throw new LeaveRefusedException('leave.type.error.overtime_hours');
        }
        if ($input->medical && $input->source !== AllowanceSource::None) {
            throw new LeaveRefusedException('leave.type.error.medical_source');
        }
        $expiry = $input->carryOverExpiry === null ? '' : trim($input->carryOverExpiry);
        if ($expiry !== '' && (preg_match('/^(\d{2})-(\d{2})$/D', $expiry, $m) !== 1 || !checkdate((int) $m[1], (int) $m[2], 2024))) {
            throw new LeaveRefusedException('leave.type.error.expiry');
        }
        $carry = $input->carryOver && $input->source === AllowanceSource::Allowance;
        if ($carry && $input->carryOverMax !== null && $input->carryOverMax <= 0) {
            throw new LeaveRefusedException('leave.type.error.carry_max');
        }
        if ($name !== $this->nameIn($locale)) {
            $this->names = [$locale => $name]; // @igor-ignore - entity state, not a service
        }
        $this->paid            = $input->paid; // @igor-ignore - entity state, not a service
        $this->allowanceSource = $input->source; // @igor-ignore - entity state, not a service
        $this->unit            = $input->unit; // @igor-ignore - entity state, not a service
        $this->needsApproval   = $input->needsApproval; // @igor-ignore - entity state, not a service
        $this->needsAttachment = $input->needsAttachment; // @igor-ignore - entity state, not a service
        $this->medical         = $input->medical; // @igor-ignore - entity state, not a service
        $this->colour          = $input->colour; // @igor-ignore - entity state, not a service
        $this->maxPerRequest   = $input->maxPerRequest; // @igor-ignore - entity state, not a service
        $this->carryOver       = $carry; // @igor-ignore - entity state, not a service
        $this->carryOverMax    = $carry ? $input->carryOverMax : null; // @igor-ignore - entity state, not a service
        $this->carryOverExpiry = $carry && $expiry !== '' ? $expiry : null; // @igor-ignore - entity state, not a service
    }

    /**
     * The attributes as an input (the edit form).
     */
    public function input(string $locale): LeaveTypeInput
    {
        return new LeaveTypeInput($this->nameIn($locale), $this->paid, $this->allowanceSource, $this->unit, $this->needsApproval, $this->needsAttachment, $this->medical, $this->colour, $this->maxPerRequest, $this->carryOver, $this->carryOverMax, $this->carryOverExpiry);
    }

    /** Archives or restores the type. */
    public function setArchived(bool $archived): void
    {
        $this->archived = $archived; // @igor-ignore - entity state, not a service
    }

    /** Row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** Tenant reference. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** Seed code (`vacation`, `medical`, …) of a seeded type. */
    public function getSeedCode(): ?string
    {
        return $this->seedCode;
    }

    /**
     * The name in the locale (the seeder's translation), else the language's, else the first stored one.
     */
    public function nameIn(string $locale): string
    {
        foreach ([$locale, substr($locale, 0, 2), 'en'] as $key) {
            if (is_string($this->names[$key] ?? null)) {
                return $this->names[$key];
            }
        }
        $first = reset($this->names);

        return is_string($first) ? $first : '';
    }

    /**
     * The stored names (locale => name).
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->names as $locale => $name) {
            if (is_string($locale) && is_string($name)) {
                $names[$locale] = $name;
            }
        }

        return $names;
    }

    /** Whether it is paid. */
    public function isPaid(): bool
    {
        return $this->paid;
    }

    /** What it draws from. */
    public function getSource(): AllowanceSource
    {
        return $this->allowanceSource;
    }

    /** How it counts. */
    public function getUnit(): LeaveUnit
    {
        return $this->unit;
    }

    /** Whether a request needs approval. */
    public function needsApproval(): bool
    {
        return $this->needsApproval;
    }

    /** Whether a request needs a document. */
    public function needsAttachment(): bool
    {
        return $this->needsAttachment;
    }

    /** Whether it is a medical absence (shown as *Absent* to anyone without `leave_manage`). */
    public function isMedical(): bool
    {
        return $this->medical;
    }

    /** Palette token. */
    public function getColour(): string
    {
        return $this->colour;
    }

    /** Largest amount of one request (stored units), null = no limit. */
    public function getMaxPerRequest(): ?int
    {
        return $this->maxPerRequest;
    }

    /** Whether unused allowance may be carried over. */
    public function allowsCarryOver(): bool
    {
        return $this->carryOver;
    }

    /** Largest carry-over (stored units), null = no limit. */
    public function getCarryOverMax(): ?int
    {
        return $this->carryOverMax;
    }

    /** `MM-DD` of the receiving year when a carry-over expires. */
    public function getCarryOverExpiry(): ?string
    {
        return $this->carryOverExpiry;
    }

    /** Whether it is archived. */
    public function isArchived(): bool
    {
        return $this->archived;
    }

    /** Sort order. */
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    /** When it was created. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
