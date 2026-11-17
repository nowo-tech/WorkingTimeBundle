<?php

declare(strict_types=1);

namespace App\WorkingTime;

use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function array_filter;
use function array_keys;
use function array_values;
use function in_array;

/**
 * Demo people: three fixed users of the in-memory provider. The signed-in one is read per call from the token (no
 * state kept, safe in FrankenPHP worker mode); managers (mia, admin) manage everyone else.
 */
final readonly class DemoWorkerDirectory implements WorkerDirectoryInterface
{
    private const PEOPLE = [
        'ana'   => 'Ana Worker',
        'mia'   => 'Mia Manager',
        'admin' => 'Ada Admin',
    ];

    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    public function current(): ?WorkerRef
    {
        $user = $this->tokens->getToken()?->getUser();
        if ($user === null) {
            return null;
        }
        $id = $user->getUserIdentifier();

        return isset(self::PEOPLE[$id]) ? new WorkerRef($id) : null;
    }

    public function worker(TenantRef $tenant, WorkerRef $worker): ?WorkerProfile
    {
        return isset(self::PEOPLE[$worker->value]) ? new WorkerProfile($worker, self::PEOPLE[$worker->value], $worker->value . '@example.test', 'en') : null;
    }

    public function workers(TenantRef $tenant, ?GroupRef $group = null): iterable
    {
        if ($group !== null) {
            return;
        }
        foreach (self::PEOPLE as $id => $name) {
            yield new WorkerProfile(new WorkerRef($id), $name, $id . '@example.test', 'en');
        }
    }

    public function groups(TenantRef $tenant): array
    {
        return [];
    }

    public function managedBy(TenantRef $tenant, ActorRef $actor): array
    {
        if (!in_array($actor->value, ['mia', 'admin'], true)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $id): WorkerRef => new WorkerRef($id),
            array_filter(array_keys(self::PEOPLE), static fn (string $id): bool => $id !== $actor->value),
        ));
    }
}
