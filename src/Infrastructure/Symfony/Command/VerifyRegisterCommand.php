<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command;

use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

/**
 * `nowo:working-time:verify` (weekly, contract section 12): checks every register chain (or one tenant's / worker's);
 * exit 1 on a broken chain (each also audited and raised as `ChainBroken`).
 */
#[AsCommand(name: 'nowo:working-time:verify', description: 'Verify the working-time register hash chains')]
final class VerifyRegisterCommand extends Command
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private readonly ChainVerifierInterface $verifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Only this tenant')
            ->addOption('worker', null, InputOption::VALUE_REQUIRED, 'Only this worker');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $tenant = $input->getOption('tenant');
        $worker = $input->getOption('worker');
        $report = $this->verifier->verify(is_string($tenant) ? new TenantRef($tenant) : null, is_string($worker) ? new WorkerRef($worker) : null);
        foreach ($report->broken as $broken) {
            $io->error(sprintf('Broken chain: tenant %s, worker %s, link %d (%s).', $broken['tenant'], $broken['worker'], $broken['seq'], $broken['reason']));
        }
        if (!$report->isIntact()) {
            return Command::FAILURE;
        }
        $io->success(sprintf('%d chain(s), %d link(s) verified.', $report->chains, $report->links));

        return Command::SUCCESS;
    }
}
