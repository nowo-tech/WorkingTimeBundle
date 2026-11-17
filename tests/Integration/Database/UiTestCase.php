<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * UI base: a browser on the kept kernel (no reboot between requests, as a FrameworkPHP worker), the register under
 * `/working-time`.
 */
abstract class UiTestCase extends RegisterTestCase
{
    protected KernelBrowser $client;

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = [], bool $triggers = true): void
    {
        parent::boot($config, $triggers);
        $client = $this->kernel->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->disableReboot();
        $this->client = $client;
    }

    protected function statusOf(string $method, string $path): int
    {
        $this->client->request($method, $path);

        return $this->client->getResponse()->getStatusCode();
    }
}
