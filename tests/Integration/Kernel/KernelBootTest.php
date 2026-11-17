<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Yaml\Yaml;

use function dirname;

/**
 * The kernel boots on both host kinds, the configuration reaches the container and the bundle works on its private
 * entity manager.
 */
final class KernelBootTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{Host}>
     */
    public static function hosts(): iterable
    {
        yield 'standard host' => [Host::standard()];
        yield 'DDD host' => [Host::ddd()];
    }

    #[DataProvider('hosts')]
    public function testTheKernelBootsOnBothHostKindsWithTheDocumentedParameters(Host $host): void
    {
        $container = $this->bootKernel($host->bundleConfig(), $host->configure())->getContainer();

        self::assertTrue($container->getParameter('nowo_working_time.register.enabled'));
        self::assertTrue($container->getParameter('nowo_working_time.holidays.enabled'));
        self::assertFalse($container->getParameter('nowo_working_time.leave.enabled'));
        self::assertFalse($container->getParameter('nowo_working_time.ui.enabled'));
        self::assertSame('db_row', $container->getParameter('nowo_working_time.leave.lock.store'));
        self::assertSame([], $container->getParameter('nowo_working_time.permissions.roles.clock'));
        self::assertFalse($container->getParameter('nowo_working_time.register.export.pdf.remote_resources'));
        self::assertSame($host->kind === Host::STANDARD ? 'nowo_' : 'ddd_', $container->getParameter('nowo_working_time.doctrine.table_prefix'));
        self::assertSame($host->entityManagerName(), $container->getParameter('nowo_working_time.doctrine.entity_manager'));
    }

    #[DataProvider('hosts')]
    public function testTheBundleUsesItsPrivateEntityManager(Host $host): void
    {
        $container = $this->bootKernel($host->bundleConfig(), $host->configure())->getContainer();

        $private = $container->get('nowo_working_time.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $private);
        $registry = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        self::assertSame($registry->getManager($host->entityManagerName()), $private);
        self::assertNotSame($registry->getManager('default'), $private, 'never the host unit of work');
    }

    public function testTheFlexRecipeDeclaresThePrivateEntityManager(): void
    {
        /** @var array{doctrine: array{orm: array{entity_managers: array<string, array<string, mixed>>}}} $recipe */
        $recipe = Yaml::parseFile(dirname(__DIR__, 3) . '/.symfony/recipe/nowo-tech/working-time-bundle/1.0/config/packages/nowo_working_time_doctrine.yaml');
        $orm    = $recipe['doctrine']['orm'];
        self::assertArrayHasKey('nowo_working_time', $orm['entity_managers']);

        $kernel = $this->bootKernel([], static function (ContainerConfigurator $container, string $url) use ($orm): void {
            $orm['entity_managers']['default'] = ['connection' => 'default'];
            $container->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => $orm]);
        });

        $registry = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        self::assertSame($registry->getManager('nowo_working_time'), $kernel->getContainer()->get('nowo_working_time.entity_manager'));
    }

    public function testANullEntityManagerUsesTheDefaultOneWithExplicitTransactions(): void
    {
        $container = $this->bootKernel(['doctrine' => ['entity_manager' => null]], static function (ContainerConfigurator $container, string $url): void {
            $container->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => []]);
        })->getContainer();

        $registry = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        self::assertSame($registry->getManager(), $container->get('nowo_working_time.entity_manager'));
    }

    public function testAMissingPrivateEntityManagerFailsTheBootWithTheFix(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('doctrine.orm.entity_managers.nowo_working_time is not declared');

        $this->bootKernel([], static function (ContainerConfigurator $container, string $url): void {
            $container->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => []]);
        });
    }

    public function testLeaveWithoutTheRegisterIsRefusedAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires nowo_working_time.register.enabled');

        $this->bootKernel(['leave' => ['enabled' => true], 'register' => ['enabled' => false]]);
    }

    public function testTheThreePartsCanBeSwitchedSeparately(): void
    {
        $container = $this->bootKernel(['register' => ['enabled' => false], 'holidays' => ['enabled' => true]])->getContainer();

        self::assertFalse($container->getParameter('nowo_working_time.register.enabled'));
        self::assertTrue($container->getParameter('nowo_working_time.holidays.enabled'));
        self::assertFalse($container->getParameter('nowo_working_time.leave.enabled'));
    }
}
