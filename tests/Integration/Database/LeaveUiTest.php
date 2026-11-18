<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The leave foundation pages end to end (contract section 11.1): the leave settings next to the register's (enabling
 * seeds the types; a stale save answers 409 + Retry-After), the types (seed, add, edit, archive), the allowances
 * (policy, line, a worker's balances, remove) and the worker's own balances; 404 while leave is off, 403 without the
 * permission.
 */
final class LeaveUiTest extends UiTestCase
{
    public function testTheLeavePages(): void
    {
        $this->boot(['leave' => ['enabled' => true]]);
        $this->enable('acme', ['ana', 'bea']);
        $this->ctx()->groups['acme'] = [[new GroupRef('g1'), 'Group one']];
        $this->actAs('ana', 'manage_settings', 'leave_manage', 'leave_request');
        self::assertSame(404, $this->statusOf('GET', '/leave'), 'leave off for the tenant');

        $crawler = $this->client->request('GET', '/working-time/settings/leave');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $stale = $crawler->filter('form[name="wt_leave_settings"]')->form();
        $this->client->submit($crawler->filter('form[name="wt_leave_settings"]')->form(), ['wt_leave_settings[enabled]' => '1', 'wt_leave_settings[planningDeadline]' => '13-45']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($crawler->filter('form[name="wt_leave_settings"]')->form(), ['wt_leave_settings[enabled]' => '1']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->followRedirect();
        $this->client->submit($stale, ['wt_leave_settings[enabled]' => '1']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'a stale save');
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
        self::assertSame(13, $this->int('SELECT COUNT(*) FROM nowo_working_time_leave_type'));

        // Types.
        $crawler = $this->client->request('GET', '/leave/manage');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Vacation', $crawler->text());
        self::assertCount(1, $crawler->filter('[data-testid="wt-leave-no-health"]'));
        $this->client->submit($crawler->filter('[data-testid="wt-leave-seed"] form')->form());
        self::assertStringContainsString('0 default type(s) added.', $this->client->followRedirect()->text());
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="wt-leave-type-form"] form')->form(), ['wt_leave_type[name]' => '', 'wt_leave_type[colour]' => 'blue']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="wt-leave-type-form"] form')->form(), ['wt_leave_type[name]' => 'Training', 'wt_leave_type[source]' => 'allowance', 'wt_leave_type[unit]' => 'hours', 'wt_leave_type[maxPerRequest]' => '8', 'wt_leave_type[colour]' => 'sky']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Training', $crawler->text());
        $this->client->click($crawler->filter('a:contains("Edit")')->last()->link());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->getCrawler();
        self::assertSame('8.00', $crawler->filter('input[name="wt_leave_type[maxPerRequest]"]')->attr('value'));
        $this->client->submit($crawler->filter('[data-testid="wt-leave-type-form"] form')->form(), ['wt_leave_type[medical]' => '1']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'a medical type draws from nothing');
        $form    = $this->client->getCrawler()->filter('[data-testid="wt-leave-type-form"] form')->form();
        $medical = $form['wt_leave_type[medical]'];
        self::assertInstanceOf(ChoiceFormField::class, $medical);
        $medical->untick();
        $this->client->submit($form, ['wt_leave_type[name]' => 'Training hours', 'wt_leave_type[carryOver]' => '1', 'wt_leave_type[carryOverMax]' => '4', 'wt_leave_type[carryOverExpiry]' => '03-31']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->followRedirect();
        $this->client->click($crawler->filter('a:contains("Edit")')->last()->link());
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="wt-leave-type-archive"] form')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Restore the type', $crawler->text());
        $this->client->submit($crawler->filter('[data-testid="wt-leave-type-archive"] form')->form());
        self::assertStringContainsString('Archive the type', $this->client->followRedirect()->text());

        // Allowances.
        $crawler = $this->client->request('GET', '/leave/manage/allowances?year=2026');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $policy = $crawler->filter('[data-testid="wt-leave-policy-form"] form')->form();
        $this->client->submit($policy, ['wt_leave_policy[scope]' => 'group', 'wt_leave_policy[amount]' => '23']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'no group chosen');
        $this->client->submit($policy, ['wt_leave_policy[scope]' => 'tenant', 'wt_leave_policy[amount]' => '15']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'under the legal minimum');
        $this->client->submit($policy, ['wt_leave_policy[scope]' => 'worker', 'wt_leave_policy[worker]' => 'bea', 'wt_leave_policy[amount]' => '25']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->followRedirect();
        $line    = $crawler->filter('[data-testid="wt-leave-line-form"] form')->form();
        $this->client->submit($line, ['wt_leave_line[worker]' => 'bea', 'wt_leave_line[amount]' => '1.5', 'wt_leave_line[reason]' => 'x']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'a reason is required');
        $this->client->submit($line, ['wt_leave_line[worker]' => 'bea', 'wt_leave_line[amount]' => '1.5', 'wt_leave_line[reason]' => 'Moved from 2025', 'wt_leave_line[expiresOn]' => '2026-12-31']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->request('GET', '/leave/manage/allowances?year=2026&worker=bea');
        self::assertStringContainsString('26.5', $crawler->filter('[data-testid="wt-leave-balance-table"]')->text(), '25 days of her own row and 1.5 days added');
        self::assertCount(0, $this->client->request('GET', '/leave/manage/allowances?year=2026&worker=ghost')->filter('[data-testid="wt-leave-balance-table"]'));
        $this->client->request('GET', '/leave/manage/allowances?year=1900');
        self::assertStringContainsString('Allowances 2026', (string) $this->client->getResponse()->getContent());
        $crawler = $this->client->request('GET', '/leave/manage/allowances?year=2026');
        self::assertCount(2, $crawler->filter('form[data-testid="wt-leave-policy-remove"]'));
        $this->client->submit($crawler->filter('form[data-testid="wt-leave-policy-remove"]')->first()->form());
        $this->client->followRedirect();
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_working_time_leave_policy'));

        // The worker's own balances.
        $crawler = $this->client->request('GET', '/leave?year=2026');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('My leave 2026', $crawler->text());
        $this->client->request('POST', '/leave/manage/seed', []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Deny by default.
        $this->ctx()->grants = [];
        $this->ctx()->grant('acme', 'ana', 'leave_request');
        self::assertSame(200, $this->statusOf('GET', '/leave'));
        self::assertSame(403, $this->statusOf('GET', '/leave/manage'));
        self::assertSame(403, $this->statusOf('GET', '/leave/manage/allowances'));
        self::assertSame(403, $this->statusOf('GET', '/working-time/settings/leave'));
        self::assertSame(403, $this->statusOf('GET', '/leave/manage/types/01900000-0000-7000-8000-000000000000'), 'the gate answers before the row is looked up');
        $this->ctx()->grant('acme', 'ana', 'leave_manage');
        self::assertSame(404, $this->statusOf('GET', '/leave/manage/types/01900000-0000-7000-8000-000000000000'));
    }
}
