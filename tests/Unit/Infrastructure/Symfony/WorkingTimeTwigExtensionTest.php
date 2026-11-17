<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Symfony;

use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterClockInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Twig\WorkingTimeTwigExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Node\Expression\ConstantExpression;

/**
 * Names and the minutes formatter of the Twig extension (the header clock is covered by the integration tests).
 */
final class WorkingTimeTwigExtensionTest extends TestCase
{
    private function extension(): WorkingTimeTwigExtension
    {
        return new WorkingTimeTwigExtension($this->createStub(RegisterClockInterface::class), $this->createStub(UrlGeneratorInterface::class));
    }

    public function testFiltersAndFunctionsAreNamed(): void
    {
        $filters   = $this->extension()->getFilters();
        $functions = $this->extension()->getFunctions();

        self::assertCount(1, $filters);
        self::assertSame('working_time_format_minutes', $filters[0]->getName());
        self::assertCount(1, $functions);
        self::assertSame('working_time_header_clock', $functions[0]->getName());
        self::assertTrue($functions[0]->needsEnvironment());
        self::assertSame(['html'], $functions[0]->getSafe(new ConstantExpression('x', 1)));
    }

    /**
     * @return iterable<string, array{float|int|null, string}>
     */
    public static function minutes(): iterable
    {
        yield 'null' => [null, '0:00'];
        yield 'zero' => [0, '0:00'];
        yield 'negative' => [-30, '0:00'];
        yield 'under an hour' => [5, '0:05'];
        yield 'float' => [90.9, '1:30'];
        yield 'long' => [1500, '25:00'];
    }

    #[DataProvider('minutes')]
    public function testFormatMinutes(int|float|null $value, string $expected): void
    {
        self::assertSame($expected, WorkingTimeTwigExtension::formatMinutes($value));
    }
}
