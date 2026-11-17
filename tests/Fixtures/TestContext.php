<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;

use function in_array;

/**
 * Mutable state of the test host (tenant of the call, actor, members, grants), set by the tests between calls. A
 * fixture only: the real ports are stateless.
 */
final class TestContext
{
    public ?string $tenant = 'acme';

    public ?string $actor = null;

    /** @var array<string, array<string, WorkerProfile>> tenant => worker => profile */
    public array $members = [];

    /** @var array<string, array<string, list<string>>> tenant => actor => permissions ('perm' or 'perm:worker') */
    public array $grants = [];

    /** @var array<string, list<array{GroupRef, string}>> */
    public array $groups = [];

    /** @var array<string, array<string, list<string>>> tenant => manager => workers */
    public array $manages = [];

    /**
     * Adds a member.
     *
     * @param list<string> $groups
     */
    public function member(string $tenant, string $worker, ?string $name = null, array $groups = [], ?string $email = null, string $locale = 'en'): self
    {
        $refs = [];
        foreach ($groups as $group) {
            $refs[] = new GroupRef($group);
        }
        $this->members[$tenant][$worker] = new WorkerProfile(new WorkerRef($worker), $name ?? $worker, $email, $locale, true, null, null, $refs);

        return $this;
    }

    /**
     * Grants permissions to an actor ('clock', or 'manage_team:worker' for one worker).
     */
    public function grant(string $tenant, string $actor, string ...$permissions): self
    {
        foreach ($permissions as $permission) {
            $this->grants[$tenant][$actor][] = $permission;
        }

        return $this;
    }

    /**
     * Whether the actor holds the permission.
     */
    public function holds(string $tenant, string $actor, WorkingTimePermission $permission, ?WorkerRef $subject): bool
    {
        $mine = $this->grants[$tenant][$actor] ?? [];

        return in_array($permission->value, $mine, true) || ($subject !== null && in_array($permission->value . ':' . $subject->value, $mine, true));
    }

    /**
     * The tenant reference.
     */
    public function tenantRef(): ?TenantRef
    {
        return $this->tenant === null ? null : new TenantRef($this->tenant);
    }

    /**
     * The actor reference.
     */
    public function actorRef(): ?ActorRef
    {
        return $this->actor === null ? null : new ActorRef($this->actor);
    }
}
