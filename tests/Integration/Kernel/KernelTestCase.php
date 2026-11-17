<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Kernel;

use Closure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;

use function count;
use function preg_replace;

/**
 * Boots a fresh {@see TestKernel} per call (own cache directory), shut down and removed after each test.
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(TestKernel::baseDir());
    }

    /**
     * @param array<string, mixed> $config `nowo_working_time` configuration
     * @param Closure(ContainerConfigurator, string): void|null $configure host configuration; null = the standard host
     */
    protected function bootKernel(array $config = [], ?Closure $configure = null): TestKernel
    {
        $id              = (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel          = new TestKernel($id, $config, $configure ?? Host::standard()->configure());
        $this->kernels[] = $kernel;
        $kernel->boot();

        return $kernel;
    }
}
