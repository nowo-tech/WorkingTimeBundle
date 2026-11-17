<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\InvalidRequestKeyException;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\IpPrefix;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\NoticeMessage;
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;
use function strlen;

/**
 * Value objects of the shared kernel: opaque refs, dates, keys, IP prefixes, profiles and messages.
 */
final class SharedTest extends TestCase
{
    public function testOpaqueRefsKeepTheirValueAndCompare(): void
    {
        $tenant = new TenantRef('acme');

        self::assertSame('acme', $tenant->value);
        self::assertSame('acme', (string) $tenant);
        self::assertTrue($tenant->equals(new TenantRef('acme')));
        self::assertFalse($tenant->equals(new TenantRef('other')));
        self::assertSame('default', TenantRef::default()->value);
        self::assertSame('g', (string) new GroupRef('g'));
    }

    public function testActorAndWorkerConvert(): void
    {
        $actor = new ActorRef('u1');

        self::assertSame('u1', $actor->asWorker()->value);
        self::assertTrue((new WorkerRef('u1'))->isActor($actor));
        self::assertFalse((new WorkerRef('u2'))->isActor($actor));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badRefs(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'invalid utf8' => ["\xFF"];
        yield 'control' => ["a\x01b"];
        yield 'format char' => ["a\u{200B}b"];
    }

    #[DataProvider('badRefs')]
    public function testOpaqueRefsRefuseBadValues(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkerRef($value);
    }

    public function testOpaqueRefAcceptsTheMaximum(): void
    {
        self::assertSame(128, strlen((new GroupRef(str_repeat('a', 128)))->value));
    }

    public function testAuditEntry(): void
    {
        $entry = new AuditEntry('working_time.clock.in', new TenantRef('t'), new ActorRef('a'), new WorkerRef('w'), ['n' => 1, 'x' => null, 's' => 'v']);

        self::assertSame('working_time.clock.in', $entry->key);
        self::assertSame(1, $entry->context['n']);
    }

    public function testAuditEntryRefusesAForeignKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditEntry('other.key', new TenantRef('t'));
    }

    public function testAuditEntryRefusesNonScalarContext(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore argument.type (the guard under test is the runtime check)
        new AuditEntry('working_time.x', new TenantRef('t'), null, null, ['a' => [1]]);
    }

    public function testIpPrefixFromIp(): void
    {
        self::assertNull(IpPrefix::fromIp('nope'));
        self::assertSame('203.0.113.0/24', (string) IpPrefix::fromIp('203.0.113.77'));
        self::assertSame('2001:db8:1:2::/64', IpPrefix::fromIp('2001:db8:1:2:3:4:5:6')?->value);
    }

    public function testIpPrefixFromStored(): void
    {
        self::assertSame('10.0.0.0/24', IpPrefix::fromStored('10.0.0.0/24')->value);
        self::assertSame('2001:db8::/64', IpPrefix::fromStored('2001:db8::/64')->value);
    }

    public function testIpPrefixFromStoredRefusesFullAddresses(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IpPrefix::fromStored('10.0.0.1');
    }

    public function testLocalDate(): void
    {
        $date = new LocalDate('2026-11-10');

        self::assertSame('2026-11', $date->month());
        self::assertSame(2026, $date->year());
        self::assertSame(2, $date->weekday());
        self::assertSame('2026-11-11', (string) $date->plusDays(1));
        self::assertSame('2026-11-09', $date->plusDays(-1)->value);
        self::assertSame(-1, $date->compare(new LocalDate('2026-11-11')));
        self::assertTrue($date->equals(new LocalDate('2026-11-10')));
        self::assertFalse($date->equals(new LocalDate('2026-11-11')));
        self::assertSame('2026-11-10 00:00:00', $date->toDateTime()->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Madrid', $date->toDateTime(new DateTimeZone('Europe/Madrid'))->getTimezone()->getName());
    }

    public function testLocalDateFromInstants(): void
    {
        $at = new DateTimeImmutable('2026-11-10 23:30:00', new DateTimeZone('UTC'));

        self::assertSame('2026-11-11', LocalDate::of($at, new DateTimeZone('Europe/Madrid'))->value);
        self::assertSame('2026-11-10', LocalDate::fromDateTime($at)->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badDates(): iterable
    {
        yield 'format' => ['2026-1-1'];
        yield 'impossible' => ['2026-02-30'];
        yield 'text' => ['today'];
    }

    #[DataProvider('badDates')]
    public function testLocalDateRefusesBadValues(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LocalDate($value);
    }

    public function testRequestKey(): void
    {
        self::assertSame('abc', (new RequestKey('abc'))->value);
        self::assertSame('web:k', RequestKey::web('k')->value);
        self::assertSame('api:k', RequestKey::api('k')->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['a b'];
        yield 'long' => [str_repeat('a', 81)];
        yield 'newline' => ["a\n"];
    }

    #[DataProvider('badKeys')]
    public function testRequestKeyRefusesBadValues(string $value): void
    {
        $this->expectException(InvalidRequestKeyException::class);

        new RequestKey($value);
    }

    public function testUuid(): void
    {
        self::assertTrue(Uuid::isValid('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'));
        self::assertFalse(Uuid::isValid('0190A1B2-C3D4-7E5F-8A9B-0C1D2E3F4A5B'));
        self::assertSame('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', Uuid::assert('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'));
    }

    public function testUuidAssertNamesTheField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The segment is not a lowercase UUID.');

        Uuid::assert('nope', 'segment');
    }

    public function testWorkerProfile(): void
    {
        $profile = new WorkerProfile(new WorkerRef('w'), 'Ana', 'a@x.test', 'es', true, new LocalDate('2026-01-01'), null, (static function () {
            yield new GroupRef('g1');
        })(), 0.5);

        self::assertCount(1, $profile->groups);
        self::assertTrue($profile->inGroup(new GroupRef('g1')));
        self::assertFalse($profile->inGroup(new GroupRef('g2')));
        self::assertSame(0.5, $profile->contractRatio);
    }

    /**
     * @return iterable<string, array{string, ?float}>
     */
    public static function badProfiles(): iterable
    {
        yield 'blank name' => ['   ', null];
        yield 'long name' => [str_repeat('a', 181), null];
        yield 'zero ratio' => ['Ana', 0.0];
        yield 'ratio over one' => ['Ana', 1.5];
    }

    #[DataProvider('badProfiles')]
    public function testWorkerProfileRefusals(string $name, ?float $ratio): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkerProfile(new WorkerRef('w'), $name, contractRatio: $ratio);
    }

    public function testTenantProfileDefaults(): void
    {
        $profile = new TenantProfile(new TenantRef('t'), 'Acme');

        self::assertSame('en', $profile->locale);
        self::assertSame('UTC', $profile->timezone);
    }

    public function testNoticeMessage(): void
    {
        $message = new NoticeMessage(new TenantRef('t'), new WorkerRef('w'), 'k', ['a' => 1, 'b' => null], '/today', 'es');

        self::assertSame('/today', $message->actionPath);
        self::assertSame('es', $message->locale);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, ?string}>
     */
    public static function badNotices(): iterable
    {
        yield 'too many params' => [array_fill(0, 21, 1), null];
        yield 'non scalar' => [['a' => new stdClass()], null];
        yield 'relative path' => [[], 'today'];
        yield 'protocol relative' => [[], '//evil.test'];
    }

    /**
     * @param array<array-key, mixed> $params
     */
    #[DataProvider('badNotices')]
    public function testNoticeMessageRefusals(array $params, ?string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore argument.type (the guard under test is the runtime check)
        new NoticeMessage(new TenantRef('t'), new WorkerRef('w'), 'k', $params, $path);
    }

    public function testPermissionValues(): void
    {
        self::assertSame('manage_team', WorkingTimePermission::ManageTeam->value);
        self::assertCount(9, WorkingTimePermission::cases());
    }
}
