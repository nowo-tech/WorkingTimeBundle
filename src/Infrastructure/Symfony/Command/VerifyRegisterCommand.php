<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Register\Chain\ChainCodecs;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * `nowo:working-time:verify` (weekly, contract section 12): checks every register chain (or one tenant's / worker's);
 * exit 1 on a broken chain (each also audited and raised as `ChainBroken`).
 *
 * `--heads-out` writes `{"tenant\u001fworker": {"hash", "seq", "at"}}`; `--heads` reads that file back. A file written by
 * 0.1.0 (a bare hash per chain) is refused (exit 2): without the link's `seq` a purge cannot be told apart from a
 * removed tail (audit ST-D1), so it would weaken the check; write a new file with `--heads-out` instead.
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
            ->addOption('worker', null, InputOption::VALUE_REQUIRED, 'Only this worker')
            ->addOption('heads', null, InputOption::VALUE_REQUIRED, 'JSON file of heads published earlier ({"tenant\\u001fworker": {"hash": "…", "seq": 12, "at": "…"}}, as --heads-out writes): each must still be a link unless a purge removed it')
            ->addOption('heads-out', null, InputOption::VALUE_REQUIRED, 'Write the current heads to this JSON file (keep it outside the database)')
            ->addOption('publish-heads', null, InputOption::VALUE_NONE, 'Record every head through the audit sink (working_time.chain_head)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $tenant    = $input->getOption('tenant');
        $worker    = $input->getOption('worker');
        $file      = $input->getOption('heads');
        $published = [];
        if (is_string($file)) {
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (!is_array($decoded)) {
                $io->error('The heads file is not a JSON object.');

                return Command::INVALID;
            }
            foreach ($decoded as $key => $head) {
                if (is_string($head)) {
                    $io->error('The heads file was written by 0.1.0 (a hash without its seq): a purge cannot be told apart from a removed tail with it. Write a new one with --heads-out (see UPGRADING.md).');

                    return Command::INVALID;
                }
                $at  = is_array($head) ? $head['at'] ?? null : null;
                $day = is_array($head) ? $head['day'] ?? null : null;
                if (!is_string($key) || !is_array($head) || !is_string($head['hash'] ?? null) || !is_int($head['seq'] ?? null) || (!is_string($at) && $at !== null) || !$this->validTime($at)
                    || ($day !== null && !LocalDate::tryParse($day) instanceof LocalDate)
                    // Only the genesis (an empty chain) has a seq below 1 (audit ST hardening).
                    || ($head['seq'] < 1 && ($head['hash'] !== ChainCodecs::GENESIS || $head['seq'] !== 0))) {
                    $io->error(sprintf('The heads file has an invalid entry (%s): each chain needs {"hash", "seq", "at"}, a seq of at least 1 (0 only for the genesis), "at" as UTC ISO 8601 or null and "day" as Y-m-d or null.', $key));

                    return Command::INVALID;
                }
                $published[$key] = ['hash' => $head['hash'], 'seq' => $head['seq'], 'at' => $at, 'day' => is_string($day) ? $day : null];
            }
        }
        $report = $this->verifier->verify(is_string($tenant) ? new TenantRef($tenant) : null, is_string($worker) ? new WorkerRef($worker) : null, $published);
        $out    = $input->getOption('heads-out');
        if (is_string($out)) {
            file_put_contents($out, json_encode($report->headLinks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        if ($input->getOption('publish-heads') === true) {
            $io->writeln(sprintf('%d head(s) published.', $this->verifier->publishHeads($report)));
        }
        foreach ($report->broken as $broken) {
            $io->error(sprintf('Broken chain: tenant %s, worker %s, link %d (%s).', $broken['tenant'], $broken['worker'], $broken['seq'], $broken['reason']));
        }
        if (!$report->isIntact()) {
            return Command::FAILURE;
        }
        $io->success(sprintf('%d chain(s), %d link(s) verified.', $report->chains, $report->links));

        return Command::SUCCESS;
    }

    /**
     * A head's time exactly as `--heads-out` writes it (`2026-11-17T16:30:00Z`), or null (the head of an emptied chain).
     * An overflowing value (`2026-02-31T25:61:00Z`) does not read back the same and is refused.
     */
    private function validTime(?string $at): bool
    {
        $parsed = $at === null ? null : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $at, new DateTimeZone('UTC'));

        return $at === null || ($parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d\TH:i:s\Z') === $at);
    }
}
