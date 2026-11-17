<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command;

use Nowo\WorkingTimeBundle\Application\Register\Port\PurgeRefusedException;
use Nowo\WorkingTimeBundle\Application\Register\RegisterExportService;
use Nowo\WorkingTimeBundle\Application\Register\RegisterPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;

/**
 * `nowo:working-time:purge [--dry-run]` (daily, contract section 6.5): the retention purge, then the expiry of export
 * files. Exit 0 done, 1 error, 2 the triggers refused the deletes (purge guard misconfigured).
 */
#[AsCommand(name: 'nowo:working-time:purge', description: 'Delete working-time register rows past their retention')]
final class PurgeRegisterCommand extends Command
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private readonly RegisterPurger $purger,
        private readonly RegisterExportService $exports,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be deleted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $dry = $input->getOption('dry-run') === true;
        try {
            $result = $this->purger->purge($dry);
        } catch (PurgeRefusedException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (Throwable $e) {
            $io->error('The purge failed: ' . $e::class);

            return Command::FAILURE;
        }
        foreach ($result['tenants'] as $tenant => $counts) {
            $io->writeln(sprintf('%s: before %s, %d workday(s), %d link(s), %d anchor(s)%s', $tenant, $counts['first_kept'], $counts['workdays'], $counts['revisions'], $counts['anchors'], $dry ? ' (dry run)' : ''));
        }
        if (!$dry) {
            $io->writeln(sprintf('%d notice(s) and %d export file(s) expired.', $result['notices'], $this->exports->expire()));
        }

        return Command::SUCCESS;
    }
}
