<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Shared;

/**
 * Runs the bundle's units of work on its entity manager (contract sections 15 and 16): `write()` is a
 * transaction-doctrine write unit (replayed after a deadlock or a lock-wait timeout, 409 + Retry-After after the last
 * attempt) around one transaction that flushes at the end; both clear the entity manager when they finish, so no
 * managed entity survives the call (worker mode).
 */
interface TransactionRunnerInterface
{
    /**
     * Runs `$work` in a write unit and a transaction; flushes before the commit.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @throws DuplicateRowException when a unique key refused a row (a racing writer)
     * @throws StaleRowException when a versioned row changed since it was read
     *
     * @return T
     */
    public function write(callable $work): mixed;

    /**
     * Runs a read and clears the entity manager afterwards.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function read(callable $work): mixed;

    /**
     * Writes pending changes inside the current write (a link must be the head for the next append).
     */
    public function flush(): void;
}
