<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Domain\Register\Chain;

use LogicException;
use Nowo\WorkingTimeBundle\Domain\Register\Chain\BeaconChainCodec;
use Nowo\WorkingTimeBundle\Domain\Register\Chain\BundleChainCodec;
use Nowo\WorkingTimeBundle\Domain\Register\Chain\CanonicalJson;
use Nowo\WorkingTimeBundle\Domain\Register\Chain\ChainCodecs;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ChainFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function hash;
use function ini_get;
use function ini_set;
use function json_decode;
use function str_repeat;
use function strlen;

/**
 * Canonical JSON and the two hash-chain codecs. The literals below are golden vectors: changing the bytes breaks every
 * stored chain, so they are written by hand and never produced by the code under test.
 */
final class ChainTest extends TestCase
{
    private const SEGMENT = '00000005-0000-4000-8000-000000000005';

    public function testCanonicalJsonSortsObjectKeysRecursivelyAndKeepsLists(): void
    {
        $json = CanonicalJson::encode(['b' => ['z' => 1, 'a' => [3, 1, 2]], 'a' => [['y' => 1, 'x' => 2]]]);

        self::assertSame('{"a":[{"x":2,"y":1}],"b":{"a":[3,1,2],"z":1}}', $json);
    }

    public function testCanonicalJsonDoesNotEscapeUnicodeOrSlashesAndKeepsFloats(): void
    {
        self::assertSame('{"p":"/a/ñ€","v":1.0}', CanonicalJson::encode(['v' => 1.0, 'p' => '/a/ñ€']));
        self::assertSame('5', CanonicalJson::encode(5));
        self::assertSame('[]', CanonicalJson::encode([]));
    }

    public function testCanonicalJsonHazardsThatKeepFloatsAndEmptyMapsOutOfPayloads(): void
    {
        // Floats follow serialize_precision, so payloads never carry them (audit ST); the same value prints differently.
        $precision = ini_get('serialize_precision');
        try {
            // @phpstan-ignore frankenphp.worker.noPersistentIniSet (shows the hazard; restored in finally)
            ini_set('serialize_precision', '17');
            self::assertSame('0.10000000000000001', CanonicalJson::encode(0.1));
        } finally {
            // @phpstan-ignore frankenphp.worker.noPersistentIniSet (restores the setting)
            ini_set('serialize_precision', (string) $precision);
        }
        self::assertSame('0.1', CanonicalJson::encode(0.1));
        // An empty map and a numeric-key map collapse to lists after a JSON round trip.
        self::assertSame('[]', CanonicalJson::encode(json_decode('{}', true)));
        self::assertSame('{"1":"a"}', CanonicalJson::encode([1 => 'a']));
        self::assertSame('["a"]', CanonicalJson::encode(json_decode('{"0":"a"}', true)));
    }

    public function testFormatOneGoldenVector(): void
    {
        $revision = WorkdayRevision::migrated(
            '00000009-0000-4000-8000-000000000009',
            Fx::workday(),
            1,
            RevisionKind::ClockIn,
            self::SEGMENT,
            ['at' => '2026-11-10T08:00:00Z', 'action' => 'clock-in'],
            null,
            '7',
            'Ana',
            Fx::at('2026-11-10 08:00:00'),
            'web:k1',
            str_repeat('0', 64),
            ['organization' => 3, 'user' => 5, 'workday' => 11, 'author' => 7],
        );

        $literal = '{"at":"2026-11-10T08:00:00Z","author":7,"author_name":"Ana","kind":"clock_in","organization":3,"payload":{"action":"clock-in","at":"2026-11-10T08:00:00Z"},"reason":null,"request_key":"web:k1","segment":"00000005-0000-4000-8000-000000000005","seq":1,"user":5,"workday":11}';

        self::assertSame($literal, (new BeaconChainCodec())->canonical($revision));
        self::assertSame(hash('sha256', str_repeat('0', 64) . $literal), $revision->getHash());
        self::assertSame(ChainFormat::Beacon, (new BeaconChainCodec())->format());
        self::assertTrue(ChainCodecs::isIntact($revision, ChainCodecs::GENESIS));
        self::assertFalse(ChainCodecs::isIntact($revision, str_repeat('1', 64)));
    }

    public function testFormatOneWithoutAnIntegerAuthorWritesNull(): void
    {
        // @phpstan-ignore argument.type (a string author is what a damaged legacy row would carry)
        $revision = WorkdayRevision::migrated('00000009-0000-4000-8000-000000000009', Fx::workday(), 1, RevisionKind::ClockOut, null, [], 'r', null, 'Ana', Fx::at('2026-11-10 08:00:00'), null, ChainCodecs::GENESIS, ['organization' => 3, 'user' => 5, 'workday' => 11, 'author' => 'x']);

        self::assertSame('{"at":"2026-11-10T08:00:00Z","author":null,"author_name":"Ana","kind":"clock_out","organization":3,"payload":[],"reason":"r","request_key":null,"segment":null,"seq":1,"user":5,"workday":11}', (new BeaconChainCodec())->canonical($revision));
    }

    /**
     * @return iterable<string, array{?array<string, mixed>}>
     */
    public static function missingRefs(): iterable
    {
        yield 'none' => [null];
        yield 'no organization' => [['user' => 1, 'workday' => 1]];
        yield 'no user' => [['organization' => 1, 'workday' => 1]];
        yield 'string workday' => [['organization' => 1, 'user' => 1, 'workday' => '1']];
    }

    /**
     * @param ?array<string, mixed> $refs
     */
    #[DataProvider('missingRefs')]
    public function testFormatOneNeedsItsLegacyRefs(?array $refs): void
    {
        $this->expectException(LogicException::class);

        // migrated() computes the hash on construction, which needs the refs.
        // @phpstan-ignore argument.type (incomplete refs are exactly the case under test)
        WorkdayRevision::migrated('00000009-0000-4000-8000-000000000009', Fx::workday(), 1, RevisionKind::ClockIn, null, [], null, null, 'Ana', Fx::at('2026-11-10'), null, ChainCodecs::GENESIS, $refs ?? []);
    }

    public function testFormatTwoGoldenVector(): void
    {
        $previous = str_repeat('a', 64);
        $revision = WorkdayRevision::append(
            '00000009-0000-4000-8000-000000000009',
            2,
            $previous,
            Fx::workday(),
            RevisionKind::ClockIn,
            self::SEGMENT,
            ['at' => '2026-11-10T08:00:00Z', 'action' => 'clock-in'],
            null,
            new ActorRef('u7'),
            'Ana',
            Fx::at('2026-11-10 08:00:00'),
            RequestKey::web('k1'),
        );

        $literal = '{"at":"2026-11-10T08:00:00Z","author":"u7","author_name":"Ana","format":2,"kind":"clock_in","payload":{"action":"clock-in","at":"2026-11-10T08:00:00Z"},"reason":null,"request_key":"web:k1","segment":"00000005-0000-4000-8000-000000000005","seq":2,"tenant":"t1","workday":"00000001-0000-4000-8000-000000000001","worker":"w1"}';

        self::assertSame($literal, (new BundleChainCodec())->canonical($revision));
        self::assertSame(hash('sha256', $previous . $literal), $revision->getHash());
        self::assertSame(ChainFormat::Bundle, (new BundleChainCodec())->format());
        self::assertTrue(ChainCodecs::isIntact($revision, $previous));
        self::assertFalse(ChainCodecs::isIntact($revision, ChainCodecs::GENESIS));
    }

    public function testCodecsFactoryAndHashOf(): void
    {
        self::assertInstanceOf(BeaconChainCodec::class, ChainCodecs::for(ChainFormat::Beacon));
        self::assertInstanceOf(BundleChainCodec::class, ChainCodecs::for(ChainFormat::Bundle));
        self::assertSame(hash('sha256', 'ab'), ChainCodecs::hashOf('a', 'b'));
        self::assertSame(64, strlen(ChainCodecs::GENESIS));
    }
}
