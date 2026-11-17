<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Domain\Register\Model;

use Nowo\WorkingTimeBundle\Domain\Register\Exception\SettingsRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RetentionPolicy;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkerPreference;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function str_repeat;

/**
 * Register settings, their validated input, worker preferences and the retention policy.
 */
final class SettingsTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusals(): iterable
    {
        yield 'flex negative' => [['flexMinutes' => -1], 'flexMinutes'];
        yield 'flex large' => [['flexMinutes' => 241], 'flexMinutes'];
        yield 'min break negative' => [['minBreakMinutes' => -1], 'minBreakMinutes'];
        yield 'min break large' => [['minBreakMinutes' => 121], 'minBreakMinutes'];
        yield 'daily zero' => [['dailyOvertimeMinutes' => 0], 'dailyOvertimeMinutes'];
        yield 'daily large' => [['dailyOvertimeMinutes' => 721], 'dailyOvertimeMinutes'];
        yield 'weekly zero' => [['weeklyOvertimeMinutes' => 0], 'weeklyOvertimeMinutes'];
        yield 'weekly large' => [['weeklyOvertimeMinutes' => 5041], 'weeklyOvertimeMinutes'];
        yield 'retention low' => [['retentionYears' => 3], 'retentionYears'];
        yield 'retention high' => [['retentionYears' => 11], 'retentionYears'];
        yield 'window zero' => [['correctionWindowDays' => 0], 'correctionWindowDays'];
        yield 'window large' => [['correctionWindowDays' => 32], 'correctionWindowDays'];
        yield 'notice locale int' => [['ipNotices' => [0 => 'x']], 'ipNotices'];
        yield 'notice locale bad' => [['ipNotices' => ['EN' => 'x']], 'ipNotices'];
        yield 'notice too long' => [['ipNotices' => ['en' => 'xy'], 'long' => true], 'ipNotices'];
        yield 'evidence without locale' => [['ipEvidence' => true], 'ipNotices'];
        yield 'evidence with blank notice' => [['ipEvidence' => true, 'ipNoticeLocale' => 'en', 'ipNotices' => ['en' => '  ']], 'ipNotices'];
        yield 'evidence with missing notice' => [['ipEvidence' => true, 'ipNoticeLocale' => 'es', 'ipNotices' => ['en' => 'x']], 'ipNotices'];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('refusals')]
    public function testInputRefusals(array $args, string $field): void
    {
        if (($args['long'] ?? false) === true) {
            $args = ['ipNotices' => ['en' => str_repeat('x', 2001)]];
        }

        try {
            // @phpstan-ignore argument.type (named arguments come from the provider)
            new RegisterSettingsInput(...$args);
            self::fail('Expected a refusal.');
        } catch (SettingsRefusedException $e) {
            self::assertSame($field, $e->field);
        }
    }

    public function testInputAcceptsTheBounds(): void
    {
        $input = new RegisterSettingsInput(true, true, 240, 120, 720, 5040, true, true, true, true, ['en' => 'Notice', 'es_ES' => 'Aviso'], 'en', 10, 31);

        self::assertSame(10, $input->retentionYears);
        self::assertSame(4, (new RegisterSettingsInput())->retentionYears);
    }

    public function testDefaultsClampTheirArguments(): void
    {
        $low  = RegisterSettings::defaults('t', Fx::at('2026-11-10'), 1, 0);
        $high = RegisterSettings::defaults('t', Fx::at('2026-11-10'), 99, 99);

        self::assertSame(4, $low->retentionYears());
        self::assertSame(1, $low->correctionWindowDays());
        self::assertSame(10, $high->retentionYears());
        self::assertSame(31, $high->correctionWindowDays());
        self::assertSame('t', $low->getTenantId());
        self::assertFalse($low->isEnabled());
        self::assertSame(1, $low->getVersion());
        self::assertNull($low->getUpdatedById());
        self::assertSame('UTC', $low->getUpdatedAt()->getTimezone()->getName());
    }

    public function testChangeAndToInput(): void
    {
        $settings = RegisterSettings::defaults('t', Fx::at('2026-11-10'));
        $input    = new RegisterSettingsInput(true, true, 30, 20, 480, 2400, true, true, true, true, ['en' => 'We log prefixes.'], 'en', 6, 15);
        $settings->change($input, new ActorRef('admin'), Fx::at('2026-11-11 10:00:00'));

        self::assertTrue($settings->isEnabled());
        self::assertTrue($settings->breaksCounted());
        self::assertSame(30, $settings->flexMinutes());
        self::assertSame(20, $settings->minBreakMinutes());
        self::assertSame(480, $settings->dailyOvertimeMinutes());
        self::assertSame(2400, $settings->weeklyOvertimeMinutes());
        self::assertTrue($settings->taskTimerLink());
        self::assertTrue($settings->remindersAllowed());
        self::assertTrue($settings->monthlySummaryMandatory());
        self::assertTrue($settings->ipEvidence());
        self::assertSame(['en' => 'We log prefixes.'], $settings->ipNotices());
        self::assertSame('en', $settings->ipNoticeLocale());
        self::assertSame(6, $settings->retentionYears());
        self::assertSame(15, $settings->correctionWindowDays());
        self::assertSame('admin', $settings->getUpdatedById());
        self::assertSame('2026-11-11T10:00:00', $settings->getUpdatedAt()->format('Y-m-d\TH:i:s'));
        self::assertEquals($input, $settings->toInput());
    }

    public function testIpEvidenceNeedsANoticeToCount(): void
    {
        $settings = RegisterSettings::defaults('t', Fx::at('2026-11-10'));
        $this->force($settings, 'ipEvidence', true);

        self::assertFalse($settings->ipEvidence());
        self::assertFalse($settings->toInput()->ipEvidence);

        $this->force($settings, 'ipNoticeLocale', 'en');
        self::assertFalse($settings->ipEvidence());

        $this->force($settings, 'ipNotices', ['en' => 'ok']);
        self::assertTrue($settings->ipEvidence());
    }

    public function testIpNoticeFor(): void
    {
        $settings = RegisterSettings::defaults('t', Fx::at('2026-11-10'));
        self::assertNull($settings->ipNoticeFor('en'));

        $settings->change(new RegisterSettingsInput(ipEvidence: true, ipNotices: ['en' => 'English', 'es' => '   '], ipNoticeLocale: 'en'), null, Fx::at('2026-11-10'));
        self::assertSame('English', $settings->ipNoticeFor('en'));
        self::assertSame('English', $settings->ipNoticeFor('fr'));
        self::assertNull($settings->ipNoticeFor('es'));
        self::assertNull($settings->getUpdatedById());

        $this->force($settings, 'ipNoticeLocale', 'zz');
        self::assertNull($settings->ipNoticeFor('fr'));
    }

    public function testRetentionYearsClampsStoredValues(): void
    {
        $settings = RegisterSettings::defaults('t', Fx::at('2026-11-10'));

        $this->force($settings, 'retentionYears', 99);
        self::assertSame(10, $settings->retentionYears());

        $this->force($settings, 'retentionYears', 1);
        self::assertSame(4, $settings->retentionYears());
    }

    public function testWorkerPreference(): void
    {
        $preference = WorkerPreference::defaults('w', Fx::at('2026-11-10'));

        self::assertSame('w', $preference->getWorkerId());
        self::assertFalse($preference->wantsReminders());
        self::assertTrue($preference->wantsMonthlySummary());

        $preference->change(true, false, Fx::at('2026-11-12 08:00:00'));

        self::assertTrue($preference->wantsReminders());
        self::assertFalse($preference->wantsMonthlySummary());
        self::assertSame('2026-11-12', $preference->getUpdatedAt()->format('Y-m-d'));
    }

    public function testRetentionPolicy(): void
    {
        self::assertSame('2022-11-01', RetentionPolicy::firstKept(4, new LocalDate('2026-11-10'))->value);
        self::assertSame('2016-01-01', RetentionPolicy::firstKept(10, new LocalDate('2026-01-31'))->value);
        self::assertSame('2022-03-01', RetentionPolicy::firstKept(1, new LocalDate('2026-03-05'))->value);
    }

    private function force(object $object, string $property, mixed $value): void
    {
        (new ReflectionProperty($object, $property))->setValue($object, $value);
    }
}
