<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Holiday;

use Nowo\WorkingTimeBundle\Application\Holiday\Port\HolidayStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\AfterCommitEvents;
use Nowo\WorkingTimeBundle\Application\Shared\DuplicateRowException;
use Nowo\WorkingTimeBundle\Application\Shared\StaleRowException;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Application\Shared\WriteConflictException;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayOrigin;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\ImportStatus;
use Nowo\WorkingTimeBundle\Domain\Holiday\Event\HolidaysImported;
use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarId;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarInput;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayFetchResult;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayLocation;
use Nowo\WorkingTimeBundle\Domain\Holiday\Import\HolidayImportParser;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportPreview;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportResult;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayCalendar;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayDay;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\WorkCentre;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\NotAvailableException;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function count;
use function in_array;
use function mb_substr;
use function sprintf;

/**
 * Holiday administration (contract sections 7, 9.2 and 15.2; Beacon's `LeaveAdministration` holiday half and the
 * festivos.io / OpenHolidays controllers). Each write is one write unit on the bundle's entity manager; audit rows and
 * events go out after the commit (a replayed attempt records nothing twice). Providers are called outside any
 * transaction, only from {@see self::previewProvider()} and {@see self::confirmProvider()} (an admin action).
 */
final readonly class HolidayCalendarService implements HolidayCalendarServiceInterface
{
    /** Audit key of every holiday change. */
    public const AUDIT_KEY = 'working_time.holidays_changed';

    /** JSON error code of a calendar edit on a version that is no longer current. */
    public const CALENDAR_STALE = 'holiday_calendar_stale';

    /** JSON error code of a confirm that raced another one twice. */
    public const IMPORT_CONFLICT = 'holiday_import_conflict';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private HolidayAccess $access,
        private HolidayStoreInterface $store,
        private HolidayProviderRegistry $providers,
        private TransactionRunnerInterface $runner,
        private IdGeneratorInterface $ids,
        private WorkingTimeAuditSinkInterface $audit,
        private ClockInterface $clock,
        private ?EventDispatcherInterface $events = null,
        private bool $fileImports = true,
        private int $maxRows = HolidayImportParser::MAX_ROWS,
        private int $maxBytes = HolidayImportParser::MAX_BYTES,
    ) {
    }

    /**
     * See the implemented interface.
     */
    public function calendars(): array
    {
        $context = $this->access->context();

        return $this->runner->read(fn (): array => $this->store->calendars($context->tenant)); // @igor-ignore - the runner calls this closure at once and keeps nothing
    }

    /**
     * See the implemented interface.
     */
    public function calendar(string $id): HolidayCalendar
    {
        $context = $this->access->context();

        return $this->runner->read(fn (): HolidayCalendar => $this->own($context->tenant, $id)); // @igor-ignore - the runner calls this closure at once and keeps nothing
    }

    /**
     * See the implemented interface.
     */
    public function saveCalendar(?string $id, HolidayCalendarInput $input, ?int $expectedVersion = null): HolidayCalendar
    {
        $context  = $this->access->context();
        $calendar = $this->versioned(fn (): HolidayCalendar => $this->runner->write(function () use ($context, $id, $input, $expectedVersion): HolidayCalendar { // @igor-ignore - the runner calls this closure at once and keeps nothing
            if ($id === null) {
                $calendar = HolidayCalendar::create($this->ids->generate(), $context->tenant->value, $input->name, $input->scope, $input->year, $input->sourceNote, $input->location, $this->clock->now());
                $this->store->addCalendar($calendar); // @igor-ignore - persistence call on a stateless collaborator

                return $calendar;
            }
            $calendar = $this->own($context->tenant, $id, true);
            if ($expectedVersion !== null && $calendar->getVersion() !== $expectedVersion) {
                throw new WriteConflictException(self::CALENDAR_STALE);
            }
            if ($calendar->getYear() !== $input->year && $this->store->days($context->tenant, [$calendar->getId()]) !== []) {
                throw new HolidayRefusedException('holiday.calendar.error.year_in_use');
            }
            $calendar->change($input->name, $input->scope, $input->year, $input->sourceNote);
            $calendar->locate($input->location);

            return $calendar;
        }));
        $this->record($context, ['what' => $id === null ? 'calendar_created' : 'calendar_changed', 'calendar' => $calendar->getId()]);

        return $calendar;
    }

    /**
     * See the implemented interface.
     */
    public function removeCalendar(string $id): void
    {
        $context = $this->access->context();
        $this->runner->write(function () use ($context, $id): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $calendar = $this->own($context->tenant, $id, true);
            foreach ($this->store->centres($context->tenant) as $centre) {
                $centre->forget($calendar->getId());
            }
            $this->store->removeCalendar($calendar); // @igor-ignore - persistence call on a stateless collaborator
        });
        $this->record($context, ['what' => 'calendar_removed', 'calendar' => $id]);
    }

    /**
     * See the implemented interface.
     */
    public function days(string $calendarId): array
    {
        $context = $this->access->context();

        return $this->runner->read(function () use ($context, $calendarId): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $calendar = $this->own($context->tenant, $calendarId);

            return $this->store->days($context->tenant, [$calendar->getId()]);
        });
    }

    /**
     * See the implemented interface.
     */
    public function addDay(string $calendarId, LocalDate $date, string $name): HolidayDay
    {
        $context = $this->access->context();
        try {
            $day = $this->runner->write(function () use ($context, $calendarId, $date, $name): HolidayDay { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $calendar = $this->own($context->tenant, $calendarId, true);
                $day      = HolidayDay::create($this->ids->generate(), $calendar, $date, $name);
                $this->store->addDay($day); // @igor-ignore - persistence call on a stateless collaborator

                return $day;
            });
        } catch (DuplicateRowException) {
            throw new HolidayRefusedException('holiday.day.error.duplicate');
        }
        $this->record($context, ['what' => 'day_added', 'calendar' => $calendarId, 'count' => 1]);

        return $day;
    }

    /**
     * See the implemented interface.
     */
    public function changeDay(string $calendarId, string $dayId, LocalDate $date, string $name): void
    {
        $context = $this->access->context();
        try {
            $this->runner->write(function () use ($context, $calendarId, $dayId, $date, $name): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $calendar = $this->own($context->tenant, $calendarId, true);
                $day      = $this->store->day($context->tenant, $calendar->getId(), $dayId) ?? throw new NotAvailableException();
                if ($date->year() !== $calendar->getYear()) {
                    throw new HolidayRefusedException('holiday.day.error.year');
                }
                $day->change($date, $name);
            });
        } catch (DuplicateRowException) {
            throw new HolidayRefusedException('holiday.day.error.duplicate');
        }
        $this->record($context, ['what' => 'day_changed', 'calendar' => $calendarId, 'count' => 1]);
    }

    /**
     * See the implemented interface.
     */
    public function removeDay(string $calendarId, string $dayId): void
    {
        $context = $this->access->context();
        $this->runner->write(function () use ($context, $calendarId, $dayId): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $calendar = $this->own($context->tenant, $calendarId, true);
            $this->store->removeDay($this->store->day($context->tenant, $calendar->getId(), $dayId) ?? throw new NotAvailableException()); // @igor-ignore - persistence call on a stateless collaborator
        });
        $this->record($context, ['what' => 'day_removed', 'calendar' => $calendarId, 'count' => 1]);
    }

    /**
     * See the implemented interface.
     */
    public function previewFile(string $calendarId, string $content, string $filename): ImportPreview
    {
        $context = $this->access->context();
        if (!$this->fileImports) {
            throw new HolidayRefusedException('holiday.import.error.disabled');
        }

        return $this->runner->read(function () use ($context, $calendarId, $content, $filename): ImportPreview { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $calendar = $this->own($context->tenant, $calendarId);

            return HolidayImportParser::parse($content, $filename, $calendar->getYear(), $calendar->getScope(), $this->dates($context->tenant, $calendar), $this->maxRows, $this->maxBytes);
        });
    }

    /**
     * See the implemented interface.
     */
    public function confirmFile(string $calendarId, array $rows): ImportResult
    {
        $context = $this->access->context();
        if (!$this->fileImports) {
            throw new HolidayRefusedException('holiday.import.error.disabled');
        }
        if (count($rows) > $this->maxRows) {
            throw new HolidayRefusedException('holiday.import.error.too_many', ['%max%' => $this->maxRows]);
        }
        $events = new AfterCommitEvents();
        // A racing confirm stores the same dates first: the unique (calendar, date) key refuses ours and the second
        // attempt skips them (audit TD-L1); a second refusal is a 409.
        $added = WriteConflictException::writeOnceMore($this->runner, function () use ($context, $calendarId, $rows, $events): int { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $events->clear();
            $calendar = $this->own($context->tenant, $calendarId, true);
            $taken    = $this->dates($context->tenant, $calendar);
            $added    = 0;
            foreach (array_slice($rows, 0, $this->maxRows) as $row) {
                $date = LocalDate::tryParse($row['date']);
                $name = HolidayEntry::cleanName($row['name']);
                if (!$date instanceof LocalDate || $name === null || $date->year() !== $calendar->getYear() || in_array($date->value, $taken, true)) {
                    continue;
                }
                $this->store->addDay(HolidayDay::create($this->ids->generate(), $calendar, $date, $name, HolidayOrigin::Import)); // @igor-ignore - persistence call on a stateless collaborator
                $taken[] = $date->value;
                ++$added;
            }
            $calendar->recordFileImport();
            $events->add(new HolidaysImported($context->tenant, new HolidayCalendarId($calendar->getId()), 'file', $added, 0));

            return $added;
        }, self::IMPORT_CONFLICT);
        $events->dispatch($this->events);
        $this->record($context, ['what' => 'import', 'origin' => 'file', 'calendar' => $calendarId, 'count' => $added]);

        return new ImportResult($added);
    }

    /**
     * See the implemented interface.
     */
    public function providers(): array
    {
        return $this->providers->all();
    }

    /**
     * See the implemented interface.
     */
    public function previewProvider(string $calendarId, string $providerKey, HolidayLocation $location, string $language): ImportPreview
    {
        $context  = $this->access->context();
        $calendar = $this->runner->read(fn (): HolidayCalendar => $this->own($context->tenant, $calendarId)); // @igor-ignore - the runner calls this closure at once and keeps nothing
        $fetched  = $this->fetch($providerKey, $location, $calendar->getYear(), $language);
        $existing = $this->runner->read(fn (): array => $this->entries($context->tenant, $calendar)); // @igor-ignore - the runner calls this closure at once and keeps nothing

        return new ImportPreview(HolidayImportParser::compare($fetched->entries, $existing, HolidayOrigin::ofProvider($providerKey), $providerKey), null, $fetched);
    }

    /**
     * See the implemented interface.
     */
    public function confirmProvider(string $calendarId, string $providerKey, HolidayLocation $location, string $language): ImportResult
    {
        $context  = $this->access->context();
        $calendar = $this->runner->read(fn (): HolidayCalendar => $this->own($context->tenant, $calendarId)); // @igor-ignore - the runner calls this closure at once and keeps nothing
        // The list is asked again (never taken from the browser): what is saved is what the provider says now.
        $fetched = $this->fetch($providerKey, $location, $calendar->getYear(), $language);
        $origin  = HolidayOrigin::ofProvider($providerKey);
        $events  = new AfterCommitEvents();
        $result  = WriteConflictException::writeOnceMore($this->runner, function () use ($context, $calendarId, $fetched, $origin, $providerKey, $location, $events): ImportResult { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $events->clear();
            $calendar = $this->own($context->tenant, $calendarId, true);
            $rows     = HolidayImportParser::compare($fetched->entries, $this->entries($context->tenant, $calendar), $origin, $providerKey);
            $removed  = 0;
            foreach ($this->store->days($context->tenant, [$calendar->getId()]) as $day) {
                foreach ($rows as $row) {
                    if ($row->status === ImportStatus::Removed && $row->date === $day->date()->value && $day->entry($calendar)->isFrom($origin, $providerKey)) {
                        $this->store->removeDay($day); // @igor-ignore - persistence call on a stateless collaborator
                        ++$removed;
                    }
                }
            }
            $this->runner->flush();
            $added = 0;
            foreach ($rows as $row) {
                $date = LocalDate::tryParse($row->date);
                if ($row->status === ImportStatus::New && $date instanceof LocalDate && $date->year() === $calendar->getYear()) {
                    $this->store->addDay(HolidayDay::create($this->ids->generate(), $calendar, $date, $row->name, $origin, $row->sourceRef, $row->sourceUrl, $providerKey)); // @igor-ignore - persistence call on a stateless collaborator
                    ++$added;
                }
            }
            $calendar->locate($location);
            $calendar->recordFetch($providerKey, $fetched->provisional, $fetched->attribution, $this->note($fetched), $this->clock->now());
            $events->add(new HolidaysImported($context->tenant, new HolidayCalendarId($calendar->getId()), $providerKey, $added, $removed));

            return new ImportResult($added, $removed, $fetched->provisional);
        }, self::IMPORT_CONFLICT);
        $events->dispatch($this->events);
        $this->record($context, ['what' => 'import', 'origin' => $providerKey, 'calendar' => $calendarId, 'count' => $result->added, 'removed' => $result->removed]);

        return $result;
    }

    /**
     * See the implemented interface.
     */
    public function centres(): array
    {
        $context = $this->access->context();

        return $this->runner->read(fn (): array => $this->store->centres($context->tenant)); // @igor-ignore - the runner calls this closure at once and keeps nothing
    }

    /**
     * See the implemented interface.
     */
    public function centre(string $id): WorkCentre
    {
        $context = $this->access->context();

        return $this->runner->read(fn (): WorkCentre => $this->store->centre($context->tenant, $id) ?? throw new NotAvailableException()); // @igor-ignore - the runner calls this closure at once and keeps nothing
    }

    /**
     * See the implemented interface.
     */
    public function saveCentre(?string $id, string $name, array $calendarIds, bool $default): WorkCentre
    {
        $context = $this->access->context();
        $centre  = $this->runner->write(function () use ($context, $id, $name, $calendarIds, $default): WorkCentre { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $tenant = [];
            foreach ($this->store->calendars($context->tenant) as $calendar) {
                $tenant[] = $calendar->getId();
            }
            $known = [];
            foreach ($calendarIds as $calendarId) {
                if (in_array($calendarId, $tenant, true)) {
                    $known[] = $calendarId;
                }
            }
            if ($id === null) {
                $centre = WorkCentre::create($this->ids->generate(), $context->tenant->value, $name, $known, $default);
                $this->store->addCentre($centre); // @igor-ignore - persistence call on a stateless collaborator
            } else {
                $centre = $this->store->centre($context->tenant, $id) ?? throw new NotAvailableException();
                $centre->change($name, $known, $default);
            }
            if ($default) {
                // Over all the tenant's rows (audit TD-M2): whatever another writer committed meanwhile, the last
                // switch leaves exactly one default.
                $this->runner->flush();
                $this->store->makeDefault($context->tenant, $centre->getId());
            }

            return $centre;
        });
        $this->record($context, ['what' => $id === null ? 'centre_created' : 'centre_changed', 'centre' => $centre->getId()]);

        return $centre;
    }

    /**
     * See the implemented interface.
     */
    public function removeCentre(string $id): void
    {
        $context = $this->access->context();
        $this->runner->write(function () use ($context, $id): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->store->removeCentre($this->store->centre($context->tenant, $id) ?? throw new NotAvailableException()); // @igor-ignore - persistence call on a stateless collaborator
        });
        $this->record($context, ['what' => 'centre_removed', 'centre' => $id]);
    }

    /**
     * The tenant's calendar, else 404; `$lock` serialises the writes of one calendar (its year against its days).
     *
     * @throws NotAvailableException
     */
    private function own(TenantRef $tenant, string $id, bool $lock = false): HolidayCalendar
    {
        $calendar = $this->store->calendar($tenant, $id, $lock);
        if (!$calendar instanceof HolidayCalendar || $calendar->getTenantId() !== $tenant->value) {
            throw new NotAvailableException();
        }

        return $calendar;
    }

    /**
     * @return list<string> `Y-m-d` of the calendar's days
     */
    private function dates(TenantRef $tenant, HolidayCalendar $calendar): array
    {
        $dates = [];
        foreach ($this->store->days($tenant, [$calendar->getId()]) as $day) {
            $dates[] = $day->date()->value;
        }

        return $dates;
    }

    /**
     * @return list<HolidayEntry>
     */
    private function entries(TenantRef $tenant, HolidayCalendar $calendar): array
    {
        $entries = [];
        foreach ($this->store->days($tenant, [$calendar->getId()]) as $day) {
            $entries[] = $day->entry($calendar);
        }

        return $entries;
    }

    /**
     * @throws HolidayRefusedException
     */
    private function fetch(string $providerKey, HolidayLocation $location, int $year, string $language): HolidayFetchResult
    {
        $provider = $this->providers->get($providerKey);
        if (!$provider->supports($location)) {
            throw new HolidayRefusedException('holiday.provider.error.location');
        }

        return $provider->fetch($location, $year, $language);
    }

    /**
     * The source note of a provider import: the URL read, the source's update date, the import date.
     */
    private function note(HolidayFetchResult $fetched): string
    {
        return mb_substr(sprintf('%s · %s%s · %s', $fetched->sourceUrl, $fetched->lastUpdated ?? '-', $fetched->provisional ? ' · provisional' : '', $this->clock->now()->format('Y-m-d')), 0, 255);
    }

    /**
     * Records the audit row of a change (ids and counts only).
     *
     * @param array<string, scalar|null> $context
     */
    private function record(HolidayContext $holiday, array $context): void
    {
        $this->audit->record(new AuditEntry(self::AUDIT_KEY, $holiday->tenant, $holiday->actor, null, $context));
    }

    /**
     * A version that moved under the flush is a stale edit (409).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function versioned(callable $work): mixed
    {
        try {
            return $work();
        } catch (StaleRowException $e) {
            throw new WriteConflictException(self::CALENDAR_STALE, $e);
        }
    }
}
