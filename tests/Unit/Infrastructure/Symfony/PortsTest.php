<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Symfony;

use InvalidArgumentException;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\NoticeMessage;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\ConfiguredScheduleFallback;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\CurrentUserWorkerDirectory;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\NeutralBrandProvider;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\NullNotifier;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\NullTaskTimerLink;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\PsrLogAuditSink;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\RoleMapPermissionChecker;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\SingleTenantResolver;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\SymfonyUuidGenerator;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function iterator_to_array;
use function str_repeat;

/**
 * The default (standalone) implementations of the host ports.
 */
final class PortsTest extends TestCase
{
    private function tokens(?string $user): TokenStorage
    {
        $storage = new TokenStorage();
        if ($user !== null) {
            $member = new InMemoryUser($user, null, ['ROLE_USER']);
            $storage->setToken(new UsernamePasswordToken($member, 'main', $member->getRoles()));
        }

        return $storage;
    }

    public function testSingleTenantResolver(): void
    {
        $resolver = new SingleTenantResolver('Acme', 'es', 'Europe/Madrid');

        self::assertSame('default', $resolver->current()->value);
        $profile = $resolver->profile(TenantRef::default());
        self::assertNotNull($profile);
        self::assertSame('Acme', $profile->name);
        self::assertSame('es', $profile->locale);
        self::assertSame('Europe/Madrid', $profile->timezone);
        self::assertNull($resolver->profile(new TenantRef('other')));
        self::assertSame('Working time', (new SingleTenantResolver())->profile(TenantRef::default())?->name);
    }

    public function testRoleMapGrantsThroughAnyMappedRole(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => $role === 'ROLE_HR');
        $checker = new RoleMapPermissionChecker(['clock' => ['ROLE_X', 'ROLE_HR'], 'export' => ['ROLE_X']], $authorization, $this->tokens('ana'));

        self::assertTrue($checker->isGranted(WorkingTimePermission::Clock, TenantRef::default()));
        self::assertFalse($checker->isGranted(WorkingTimePermission::Export, TenantRef::default(), new WorkerRef('w')));
        self::assertFalse($checker->isGranted(WorkingTimePermission::ManageTeam, TenantRef::default()));
        self::assertSame('ana', $checker->currentActor()?->value);
    }

    public function testRoleMapDeniesWithoutRolesToken(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects(self::never())->method('isGranted');

        self::assertFalse((new RoleMapPermissionChecker([], $authorization, $this->tokens('ana')))->isGranted(WorkingTimePermission::Clock, TenantRef::default()));
        self::assertFalse((new RoleMapPermissionChecker(['clock' => ['ROLE_A']], $authorization, $this->tokens(null)))->isGranted(WorkingTimePermission::Clock, TenantRef::default()));
        self::assertFalse((new RoleMapPermissionChecker(['clock' => ['ROLE_A']], $authorization, null))->isGranted(WorkingTimePermission::Clock, TenantRef::default()));
        self::assertFalse((new RoleMapPermissionChecker(['clock' => ['ROLE_A']], null, $this->tokens('ana')))->isGranted(WorkingTimePermission::Clock, TenantRef::default()));
        self::assertNull((new RoleMapPermissionChecker())->currentActor());
    }

    public function testRoleMapDeniesWhenTheCheckerThrows(): void
    {
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willThrowException(new RuntimeException('no token'));

        self::assertFalse((new RoleMapPermissionChecker(['clock' => ['ROLE_A', 'ROLE_B']], $authorization, $this->tokens('ana')))->isGranted(WorkingTimePermission::Clock, TenantRef::default()));
    }

    public function testCurrentUserWorkerDirectory(): void
    {
        $directory = new CurrentUserWorkerDirectory($this->tokens(str_repeat('n', 128)));
        $tenant    = TenantRef::default();
        $me        = $directory->current();

        self::assertNotNull($me);
        $profile = $directory->worker($tenant, $me);
        self::assertNotNull($profile);
        self::assertSame(128, mb_strlen($profile->displayName));
        self::assertNull($directory->worker($tenant, new WorkerRef('someone-else')));
        self::assertCount(1, iterator_to_array($directory->workers($tenant), false));
        self::assertSame([], iterator_to_array($directory->workers($tenant, new GroupRef('g')), false));
        self::assertSame([], $directory->groups($tenant));
        self::assertSame([], $directory->managedBy($tenant, new ActorRef('a')));
    }

    public function testCurrentUserWorkerDirectoryWithoutAUser(): void
    {
        foreach ([new CurrentUserWorkerDirectory(), new CurrentUserWorkerDirectory($this->tokens(null))] as $directory) {
            self::assertNull($directory->current());
            self::assertNull($directory->worker(TenantRef::default(), new WorkerRef('w')));
            self::assertSame([], iterator_to_array($directory->workers(TenantRef::default()), false));
        }
    }

    public function testNeutralBrandProvider(): void
    {
        $brand = (new NeutralBrandProvider('Acme'))->getBrand();

        self::assertSame('Acme', $brand->name);
        self::assertSame('#334155', $brand->colors()->primary);
        self::assertSame('#0f172a', $brand->colors(true)->paper);
        self::assertSame('Working time', (new NeutralBrandProvider())->getBrand()->name);
    }

    public function testNullAdaptersDoNothing(): void
    {
        $notice = new NoticeMessage(TenantRef::default(), new WorkerRef('w'), 'k');

        (new NullNotifier())->send($notice);
        (new NullTaskTimerLink())->stopRunning(TenantRef::default(), new WorkerRef('w'), Fx::at('2026-11-10'));

        $this->addToAssertionCount(1);
    }

    public function testPsrLogAuditSink(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Working-time audit: working_time.clock.in',
            ['tenant' => 't', 'actor' => 'a', 'subject' => null, 'n' => 1],
        );

        (new PsrLogAuditSink($logger))->record(new AuditEntry('working_time.clock.in', new TenantRef('t'), new ActorRef('a'), null, ['n' => 1]));
        (new PsrLogAuditSink())->record(new AuditEntry('working_time.x', new TenantRef('t')));
    }

    public function testConfiguredScheduleFallback(): void
    {
        $date = new LocalDate('2026-11-10');

        self::assertNull((new ConfiguredScheduleFallback())->fallback(TenantRef::default(), $date));

        $fallback = (new ConfiguredScheduleFallback(['ranges' => ['mon' => ['09:00-17:00']], 'daily_target_minutes' => 480], 'Europe/Madrid'))->fallback(TenantRef::default(), $date);
        self::assertNotNull($fallback);
        self::assertSame('Europe/Madrid', $fallback->timezone);
        self::assertSame(480, $fallback->dailyTargetMinutes);
    }

    public function testConfiguredScheduleFallbackRefusesABrokenConfig(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConfiguredScheduleFallback(['ranges' => ['nope' => ['09:00-17:00']]]);
    }

    public function testUuidGeneratorProducesLowercaseVersionSeven(): void
    {
        $generator = new SymfonyUuidGenerator();
        $first     = $generator->generate();
        $second    = $generator->generate();

        self::assertTrue(Uuid::isValid($first));
        self::assertSame($first, strtolower($first));
        self::assertSame('7', $first[14]);
        self::assertNotSame($first, $second);
    }
}
