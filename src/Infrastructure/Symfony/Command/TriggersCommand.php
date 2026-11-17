<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command;

use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\RegisterTriggerInstaller;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\TriggerInstallException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
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

    public function __construct(
        private readonly string $mode,
        private readonly DoctrineTransactionRunner $runner,
        private readonly RegisterTriggerInstaller $installer,
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
        $io         = new SymfonyStyle($input, $output);
        $connection = $this->runner->manager()->getConnection();
        if ($this->mode === self::INSTALL) {
            try {
                $this->installer->install($connection);
            } catch (TriggerInstallException $e) {
                $io->error($e->getMessage());

                return 2;
            }
            $io->success(sprintf('%d trigger(s) installed.', count($this->installer->names($connection->getDatabasePlatform()))));

            return Command::SUCCESS;
        }
        if ($this->mode === self::UNINSTALL) {
            $this->installer->uninstall($connection);
            $io->success('Triggers removed.');

            return Command::SUCCESS;
        }
        $missing = $this->installer->missing($connection);
        if ($missing !== []) {
            $io->error('Missing triggers: ' . implode(', ', $missing));

            return Command::FAILURE;
        }
        $io->success('All triggers are installed.');

        return Command::SUCCESS;
    }
}
