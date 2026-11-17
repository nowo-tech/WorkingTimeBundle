<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkingTimeBundle\Tests\Integration\Kernel\Host;
use Nowo\WorkingTimeBundle\Tests\Integration\Kernel\KernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The private entity manager reaches the database of the CI matrix (SQLite, MySQL 8.4, MariaDB 11.4, PostgreSQL 16;
 * `NOWO_TEST_DATABASE_URL`) on both host kinds. The register tables join this suite with S1.
 */
final class DatabaseBootTest extends KernelTestCase
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
    public function testThePrivateEntityManagerAnswersOnTheConfiguredDatabase(Host $host): void
    {
        $container = $this->bootKernel($host->bundleConfig(), $host->configure())->getContainer();

        $em = $container->get('nowo_working_time.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertEquals(1, $em->getConnection()->fetchOne('SELECT 1'));
    }
}
