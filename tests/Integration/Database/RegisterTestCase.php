<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAccess;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\RegisterTriggerInstaller;
use Nowo\WorkingTimeBundle\Tests\Fixtures\TestContext;
use Nowo\WorkingTimeBundle\Tests\Fixtures\TestRecorder;
use Nowo\WorkingTimeBundle\Tests\Fixtures\TestServices;
use Nowo\WorkingTimeBundle\Tests\Integration\Kernel\Host;
use Nowo\WorkingTimeBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\WorkingTimeBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Component\Clock\MockClock;

use function array_replace_recursive;
use function str_starts_with;

/**
 * Functional base of the register: a kernel on the test ports (tenant `acme`, members and grants in
 * {@see TestContext}), the bundle's schema created on the configured database (SQLite, or the CI matrix) with the
 * append-only triggers, and helpers. Every test starts from an empty schema.
 */
abstract class RegisterTestCase extends KernelTestCase
{
    protected TestKernel $kernel;

    protected function host(): Host
    {
        return Host::standard();
    }

    /**
     * Boots the kernel and creates the schema.
     *
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = [], bool $triggers = true): void
    {
        $host = $this->host();
        /** @var array<string, mixed> $merged */
        $merged       = array_replace_recursive(TestServices::PORTS + ['ui' => ['enabled' => true], 'register' => ['export' => ['async' => 'sync']]], $config);
        $this->kernel = $this->bootKernel($host->bundleConfig($merged), $host->configure());
        $this->schema($triggers);
    }

    protected function schema(bool $triggers): void
    {
        $em        = $this->em();
        $metadata  = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $class) {
            if (str_starts_with($class->getName(), 'Nowo\WorkingTimeBundle\\')) {
                $metadata[] = $class;
            }
        }
        $installer = $this->installer();
        $installer->uninstall($this->connection());
        $tool = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        if ($triggers) {
            $installer->install($this->connection());
        }
    }

    protected function em(): EntityManagerInterface
    {
        $runner = $this->kernel->getContainer()->get(DoctrineTransactionRunner::class);
        self::assertInstanceOf(DoctrineTransactionRunner::class, $runner);

        return $runner->manager();
    }

    protected function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    protected function installer(): RegisterTriggerInstaller
    {
        $installer = $this->kernel->getContainer()->get(RegisterTriggerInstaller::class);
        self::assertInstanceOf(RegisterTriggerInstaller::class, $installer);

        return $installer;
    }

    protected function ctx(): TestContext
    {
        $context = $this->kernel->getContainer()->get('test.context');
        self::assertInstanceOf(TestContext::class, $context);

        return $context;
    }

    protected function recorder(): TestRecorder
    {
        $recorder = $this->kernel->getContainer()->get('test.recorder');
        self::assertInstanceOf(TestRecorder::class, $recorder);

        return $recorder;
    }

    protected function clock(): MockClock
    {
        $clock = $this->kernel->getContainer()->get('test.clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = $this->kernel->getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * The tenant `acme` with the register on (settings saved directly), and the given members.
     *
     * @param list<string> $workers
     */
    protected function enable(string $tenant = 'acme', array $workers = ['ana'], ?RegisterSettingsInput $input = null): void
    {
        foreach ($workers as $worker) {
            $this->ctx()->member($tenant, $worker, ucfirst($worker));
        }
        $em       = $this->em();
        $settings = RegisterSettings::defaults($tenant, $this->clock()->now());
        $settings->change($input ?? new RegisterSettingsInput(enabled: true), null, $this->clock()->now());
        $em->persist($settings);
        $em->flush();
        $em->clear();
    }

    /**
     * Acts as a worker with the given permissions.
     */
    protected function actAs(string $actor, string ...$permissions): void
    {
        $this->ctx()->actor = $actor;
        $this->ctx()->grant($this->ctx()->tenant ?? 'acme', $actor, ...$permissions);
    }

    protected function access(): RegisterAccess
    {
        return $this->service(RegisterAccess::class);
    }
}
