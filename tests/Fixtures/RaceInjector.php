<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;

use function str_pad;
use function str_repeat;

use const STR_PAD_LEFT;

/**
 * Doctrine `prePersist` listener of the race tests: while `TestContext::$races` is positive, it stores another
 * writer's link with the same chain seq just before the bundle's own (as a concurrent request would), so the unique
 * key refuses the bundle's insert.
 */
final class RaceInjector
{
    public function __construct(private readonly TestContext $context)
    {
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        $revision = $args->getObject();
        if (!$revision instanceof WorkdayRevision || $this->context->races <= 0) {
            return;
        }
        --$this->context->races;
        $em = $args->getObjectManager();
        $em->getConnection()->insert($em->getClassMetadata(WorkdayRevision::class)->getTableName(), [
            'id'           => '01900000-0000-7000-8000-' . str_pad((string) $this->context->races, 12, '0', STR_PAD_LEFT),
            'tenant_id'    => $revision->getTenantId(),
            'worker_id'    => $revision->getWorkerId(),
            'workday_id'   => $revision->getWorkdayId(),
            'seq'          => $revision->getSeq(),
            'kind'         => 'clock_in',
            'payload'      => '{}',
            'author_name'  => 'Racer',
            'created_at'   => '2026-11-18 10:00:00',
            'prev_hash'    => $revision->getPrevHash(),
            'hash'         => str_repeat('f', 64),
            'chain_format' => 2,
        ]);
    }
}
