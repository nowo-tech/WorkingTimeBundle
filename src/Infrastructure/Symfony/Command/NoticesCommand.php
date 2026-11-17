<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command;

use Nowo\WorkingTimeBundle\Application\Register\Notice\NoticeDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * `nowo:working-time:notices` (every 5 minutes, contract section 12): plans reminders and monthly summaries and
 * delivers the due outbox notices.
 */
#[AsCommand(name: 'nowo:working-time:notices', description: 'Queue and deliver working-time notices')]
final class NoticesCommand extends Command
{
    public function __construct(private readonly NoticeDispatcher $notices)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->notices->run();
        $output->writeln(sprintf('%d queued, %d sent, %d failed.', $result['queued'], $result['sent'], $result['failed']));

        return $result['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
