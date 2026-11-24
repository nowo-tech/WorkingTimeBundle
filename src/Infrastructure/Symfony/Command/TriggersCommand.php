<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command;

use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterTriggersInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\TriggerInstallRefusedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function implode;
use function sprintf;

/**
 * `nowo:working-time:triggers:install|check|uninstall` (contract section 6.4). install: exit 2 (nothing half-created)
 * when the database user may not create triggers; check: exit 1 while triggers are missing.
 */
final class TriggersCommand extends Command
{
    /** Command names. */
    public const INSTALL   = 'nowo:working-time:triggers:install';
    public const CHECK     = 'nowo:working-time:triggers:check';
    public const UNINSTALL = 'nowo:working-time:triggers:uninstall';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private readonly string $mode,
        private readonly RegisterTriggersInterface $triggers,
    ) {
        parent::__construct($mode);
    }

    protected function configure(): void
    {
        $this->setDescription(match ($this->mode) {
            self::INSTALL   => 'Install the append-only triggers of the working-time register',
            self::UNINSTALL => 'Remove the append-only triggers of the working-time register',
            default         => 'Check that the append-only triggers of the working-time register are installed',
        });
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->mode === self::INSTALL) {
            try {
                $installed = $this->triggers->install();
            } catch (TriggerInstallRefusedException $e) {
                $io->error($e->getMessage());

                return Command::INVALID;
            }
            $io->success(sprintf('%d trigger(s) installed.', $installed));

            return Command::SUCCESS;
        }
        if ($this->mode === self::UNINSTALL) {
            $this->triggers->uninstall();
            $io->success('Triggers removed.');

            return Command::SUCCESS;
        }
        $missing = $this->triggers->missing();
        if ($missing !== []) {
            $io->error('Missing triggers: ' . implode(', ', $missing));

            return Command::FAILURE;
        }
        $io->success('All triggers are installed.');

        return Command::SUCCESS;
    }
}
