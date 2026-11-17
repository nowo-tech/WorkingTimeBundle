<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkerPreference;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Psr\Clock\ClockInterface;

/**
 * The signed-in worker's register preferences (spec 198 FR-025 / FR-026): reminders only when the tenant allows them;
 * the monthly summary cannot be turned off when the tenant made it mandatory.
 */
final readonly class WorkerPreferences
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterAccess $access,
        private RegisterStoreInterface $store,
        private TransactionRunnerInterface $runner,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The worker's preferences and what the tenant allows.
     *
     * @return array{WorkerPreference, bool, bool} preferences, reminders allowed, summary mandatory
     */
    public function get(): array
    {
        $context = $this->access->context(WorkingTimePermission::Clock);

        return $this->runner->read(fn (): array => [
            $this->store->preference($context->worker()) ?? WorkerPreference::defaults($context->worker()->value, $this->clock->now()),
            $context->settings->remindersAllowed(),
            $context->settings->monthlySummaryMandatory(),
        ]);
    }

    /**
     * Saves the worker's choices (reminders forced off when not allowed, summary forced on when mandatory).
     */
    public function update(bool $reminders, bool $monthlySummary): WorkerPreference
    {
        $context = $this->access->context(WorkingTimePermission::Clock);

        return $this->runner->write(function () use ($context, $reminders, $monthlySummary): WorkerPreference { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $now        = $this->clock->now();
            $preference = $this->store->preference($context->worker());
            $new        = !$preference instanceof WorkerPreference;
            $preference ??= WorkerPreference::defaults($context->worker()->value, $now);
            $preference->change($reminders && $context->settings->remindersAllowed(), $monthlySummary || $context->settings->monthlySummaryMandatory(), $now);
            if ($new) {
                $this->store->addPreference($preference); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }

            return $preference;
        });
    }
}
