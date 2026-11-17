<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleSettingsException;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\TriggerInspectorInterface;
use Nowo\WorkingTimeBundle\Application\Shared\AfterCommitEvents;
use Nowo\WorkingTimeBundle\Application\Shared\DuplicateRowException;
use Nowo\WorkingTimeBundle\Application\Shared\StaleRowException;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Event\RegisterSettingsChanged;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\SettingsRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterSettingsEditorInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Edits a tenant's register settings (contract sections 6.2, 15.2): version-checked, audited, and with
 * `integrity.triggers: require` the register cannot be turned on while the append-only triggers are missing.
 */
final readonly class RegisterSettingsEditor implements RegisterSettingsEditorInterface
{
    public function __construct(
        private RegisterAccess $access,
        private RegisterStoreInterface $store,
        private TransactionRunnerInterface $runner,
        private WorkingTimeAuditSinkInterface $audit,
        private TriggerInspectorInterface $triggers,
        private ClockInterface $clock,
        private ?EventDispatcherInterface $dispatcher = null,
        private bool $requireTriggers = false,
        private bool $ipEvidenceAllowed = true,
    ) {
    }

    /**
     * The settings and their version.
     *
     * @return array{RegisterSettings, int}
     */
    public function get(): array
    {
        $context = $this->access->context(WorkingTimePermission::ManageSettings, null, false);

        return $this->runner->read(function () use ($context): array {
            $stored = $this->store->settings($context->tenant);

            return [$stored ?? $context->settings, $stored?->getVersion() ?? 0];
        });
    }

    /**
     * Saves the values on the version the admin saw.
     */
    public function update(RegisterSettingsInput $input, int $expectedVersion): RegisterSettings
    {
        $context = $this->access->context(WorkingTimePermission::ManageSettings, null, false);
        if ($input->ipEvidence && !$this->ipEvidenceAllowed) {
            throw new SettingsRefusedException('ipEvidence', 'settings.error.ip_evidence_off');
        }
        if ($input->enabled && $this->requireTriggers && $this->triggers->missing() !== []) {
            throw new SettingsRefusedException('enabled', 'settings.error.triggers_missing');
        }
        $events = new AfterCommitEvents();
        try {
            $settings = $this->runner->write(function () use ($context, $input, $expectedVersion, $events): RegisterSettings {
                $now    = $this->clock->now();
                $stored = $this->store->settings($context->tenant);
                if (($stored?->getVersion() ?? 0) !== $expectedVersion) {
                    throw new StaleSettingsException();
                }
                $settings = $stored ?? RegisterSettings::defaults($context->tenant->value, $now);
                $settings->change($input, $context->actor, $now);
                if ($stored === null) {
                    $this->store->addSettings($settings);
                }
                $this->runner->flush();
                $this->audit->record(new AuditEntry('working_time.settings_changed', $context->tenant, $context->actor, null, ['enabled' => $input->enabled, 'ip_evidence' => $input->ipEvidence, 'retention_years' => $input->retentionYears]));
                $events->clear();
                $events->add(new RegisterSettingsChanged($context->tenant, $settings->getVersion()));

                return $settings;
            });
        } catch (StaleRowException|DuplicateRowException $e) {
            throw new StaleSettingsException($e);
        }
        $events->dispatch($this->dispatcher);

        return $settings;
    }
}
