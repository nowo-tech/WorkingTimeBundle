<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;
use Nowo\WorkingTimeBundle\Application\Shared\DuplicateRowException;
use Nowo\WorkingTimeBundle\Application\Shared\StaleRowException;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;

/**
 * The bundle's units of work on its entity manager (contract sections 15 and 16).
 *
 * - `write()`: a transaction-doctrine write unit (replay after a deadlock / lock-wait timeout, `ConcurrencyConflict`
 *   ⇒ 409 + Retry-After after the last attempt) around one explicit transaction that flushes before the commit; a
 *   unique key refusal becomes {@see DuplicateRowException}, an optimistic lock failure {@see StaleRowException}.
 *   A write inside a running transaction (its own, or a host's on a shared connection) runs and flushes into it.
 * - `read()`: runs and clears.
 * - Both clear the entity manager when the outermost unit ends, and a closed entity manager is reset through the
 *   registry before the next unit (worker mode: no managed entity and no broken manager survive a call).
 *
 * Never flushes the host's entity manager (rule 6): with `entity_manager: null` it is the default one, used only in
 * explicit transactions.
 */
final readonly class DoctrineTransactionRunner implements TransactionRunnerInterface
{
    /**
     * @param string|null $managerName the bundle's entity manager (null: the default one)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private WriteUnitInterface $writeUnit,
        private ?string $managerName = null,
    ) {
    }

    /**
     * Runs a write unit.
     */
    public function write(callable $work): mixed
    {
        $manager = $this->manager();
        if ($manager->getConnection()->isTransactionActive()) {
            // Inside a running unit (ours, or a host transaction on a shared connection: a UserKit erasure): one
            // transaction, one replay layer; our changes are flushed into it.
            $result = $work();
            $manager->flush();

            return $result;
        }

        return $this->writeUnit->run(function () use ($work): mixed {
            $manager = $this->manager();
            $manager->clear();
            try {
                return $manager->wrapInTransaction(static function (EntityManagerInterface $manager) use ($work): mixed {
                    $result = $work();
                    $manager->flush();

                    return $result;
                });
            } catch (UniqueConstraintViolationException $e) {
                throw new DuplicateRowException($e);
            } catch (OptimisticLockException $e) {
                throw new StaleRowException($e);
            } finally {
                $this->manager()->clear();
            }
        });
    }

    /**
     * Runs a read.
     */
    public function read(callable $work): mixed
    {
        $manager = $this->manager();
        if ($manager->getConnection()->isTransactionActive()) {
            return $work();
        }
        try {
            return $work();
        } finally {
            $manager->clear();
        }
    }

    /**
     * Flushes inside the running write.
     */
    public function flush(): void
    {
        $this->manager()->flush();
    }

    /**
     * The bundle's entity manager, reset first when a failure closed it.
     */
    public function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $manager = $this->registry->resetManager($this->managerName);
        }
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('nowo_working_time needs an ORM entity manager.');
        }

        return $manager;
    }
}
