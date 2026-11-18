<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command;

use Nowo\WorkingTimeBundle\Application\Leave\LeaveAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * `nowo:working-time:leave:daily` (contract section 12, daily): marks the carry-overs past their expiry, one audit row
 * per tenant with the count. The planning and stale-request reminders join it with the request workflow (S3).
 */
#[AsCommand(name: 'nowo:working-time:leave:daily', description: 'Leave: expire carry-overs (daily)')]
final class LeaveDailyCommand extends Command
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private readonly LeaveAdministration $administration)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('%d carry-over line(s) expired.', $this->administration->expireCarryOvers()));

        return Command::SUCCESS;
    }
}
