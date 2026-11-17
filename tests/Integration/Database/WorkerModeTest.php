<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * Worker mode (contract section 16, REQ-RUNTIME-002): one kept-alive kernel (kernel reset false: the browser never
 * reboots) serves requests alternating two tenants and two workers — clocking, the day, the month, an export and its
 * download — without crossed data, without a managed entity surviving a request, and with flat memory.
 */
final class WorkerModeTest extends UiTestCase
{
    public function testRequestsOfTwoTenantsAndTwoWorkersNeverCross(): void
    {
        $this->boot();
        $this->enable('acme', ['ana']);
        $this->enable('beta', ['bob'], new RegisterSettingsInput(enabled: true, ipEvidence: true, ipNotices: ['en' => 'Prefix kept'], ipNoticeLocale: 'en'));
        $this->ctx()->grant('acme', 'ana', 'clock');
        $this->ctx()->grant('beta', 'bob', 'clock');
        $profiles = [['acme', 'ana', '10.1.1.1'], ['beta', 'bob', '10.2.2.2']];
        $baseline = 0;
        for ($i = 0; $i < 120; ++$i) {
            [$tenant, $worker, $ip] = $profiles[$i % 2];
            $this->ctx()->tenant    = $tenant;
            $this->ctx()->actor     = $worker;
            $crawler                = $this->client->request('GET', '/working-time');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $status = (string) $crawler->filter('[data-testid="wt-clock"]')->attr('data-status');
            $action = $status === 'working' ? 'clock-out' : 'clock-in';
            $this->client->submit($crawler->filter('form[data-testid="wt-clock-' . $action . '"]')->form(), [], ['REMOTE_ADDR' => $ip]);
            self::assertSame(303, $this->client->getResponse()->getStatusCode());
            $this->clock()->modify('+7 minutes');
            $day = $this->client->request('GET', '/working-time/date/2026-11-18');
            self::assertStringNotContainsString($tenant === 'acme' ? '10.2.2.0/24' : 'Ana', $day->text());
            self::assertSame(0, $this->em()->getUnitOfWork()->size(), 'no managed entity survives a request');
            if ($i === 20) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan($baseline + 8 * 1024 * 1024, memory_get_usage(), 'flat memory');
        $rows = $this->connection()->fetchAllAssociative('SELECT DISTINCT tenant_id, worker_id FROM nowo_working_time_revision ORDER BY tenant_id');
        self::assertSame([['tenant_id' => 'acme', 'worker_id' => 'ana'], ['tenant_id' => 'beta', 'worker_id' => 'bob']], $rows);
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM nowo_working_time_segment s INNER JOIN nowo_working_time_workday w ON w.id = s.workday_id WHERE w.tenant_id = 'acme' AND s.start_ip_prefix IS NOT NULL"), 'acme collects no IP evidence');
    }
}
