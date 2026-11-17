<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Register\Service\CorrectionServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

use function str_repeat;
use function strlen;
use function trim;

/**
 * The register pages end to end (contract section 11.1): clocking with the buttons (CSRF, idempotency key), the
 * month, a day and its correction request, the manager's team with the decision on the version shown (a stale one
 * answers 409 + Retry-After with the page and a flash), the manager-recorded correction and the worker's answer,
 * schedules, exports with the download, settings (stale save 409) and preferences.
 */
final class UiFlowTest extends UiTestCase
{
    public function testAWorkerClocksAndSeesTheirDay(): void
    {
        $this->boot();
        $this->enable(input: new RegisterSettingsInput(enabled: true, ipEvidence: true, ipNotices: ['en' => 'We keep the network prefix of each clock action.'], ipNoticeLocale: 'en'));
        $this->actAs('ana', 'clock');
        $crawler = $this->client->request('GET', '/working-time');
        self::assertStringContainsString('We keep the network prefix', $crawler->text());
        $this->client->submit($crawler->filter('form[data-testid="wt-clock-clock-in"]')->form(), [], ['REMOTE_ADDR' => '192.0.2.10']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->followRedirect();
        self::assertSame('working', $crawler->filter('[data-testid="wt-clock"]')->attr('data-status'));
        $form = $crawler->filter('form[data-testid="wt-clock-break-start"]')->form();
        $this->clock()->modify('+1 hour');
        $this->client->submit($form);
        $this->client->submit($form);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'the same key acts once');
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_working_time_revision'));
        $crawler = $this->client->request('GET', '/working-time');
        $this->clock()->modify('+10 minutes');
        $this->client->submit($crawler->filter('form[data-testid="wt-clock-clock-out"]')->form());
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/working-time/month/2026-11');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('2026-11-18', $crawler->text());
        $crawler = $this->client->request('GET', '/working-time/date/2026-11-18');
        self::assertStringContainsString('192.0.2.0/24', $crawler->text());
        self::assertCount(2, $crawler->filter('[data-testid="wt-segment"]'));
        $id = $this->text('SELECT id FROM nowo_working_time_workday');
        $this->client->request('GET', '/working-time/day/' . $id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(404, $this->statusOf('GET', '/working-time/month/2026-13'));
        self::assertSame(404, $this->statusOf('GET', '/working-time/date/2026-02-30'));
        self::assertSame(404, $this->statusOf('GET', '/working-time/day/01900000-0000-7000-8000-000000000000'));
    }

    public function testAClockButtonOutOfStateAnswers409AndABadFormAnswers400(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $crawler = $this->client->request('GET', '/working-time');
        $form    = $crawler->filter('form[data-testid="wt-clock-clock-in"]')->form();
        $this->client->submit($form);
        $this->client->submit($crawler->filter('form[data-testid="wt-clock-clock-in"]')->form(), ['wt_clock_clock_in[requestKey]' => 'another']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/working-time/clock/clock-out', ['wt_clock_clock_out' => ['requestKey' => 'x']]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode(), 'no CSRF token');
        $this->client->submit($form, ['wt_clock_clock_in[requestKey]' => str_repeat('k', 90)]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode(), 'an unusable key');
    }

    public function testACorrectionIsRequestedApprovedAndAStaleDecisionAnswers409(): void
    {
        $this->bootDay();
        $crawler = $this->client->request('GET', '/working-time/date/2026-11-18');
        $form    = $crawler->filter('[data-testid="wt-correction-form"] form')->form();
        $this->client->submit($form, ['wt_correction[operation]' => 'amend', 'wt_correction[segment]' => $this->firstSegment(), 'wt_correction[start]' => '08:30', 'wt_correction[reason]' => 'short']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['wt_correction[operation]' => 'amend', 'wt_correction[segment]' => $this->firstSegment(), 'wt_correction[start]' => '08:30', 'wt_correction[reason]' => 'Came in earlier than clocked']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($crawler->filter('[data-testid="wt-correction-form"] form')->form(), ['wt_correction[operation]' => 'amend', 'wt_correction[reason]' => '']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->ctx()->members['acme']['gone'] = new WorkerProfile(new WorkerRef('gone'), 'Gone', null, null, false);
        $this->actAs('mia', 'manage_team');
        $team = $this->client->request('GET', '/working-time/team?date=2026-11-18');
        self::assertStringNotContainsString('Gone', $team->text(), 'not an active member');
        self::assertStringContainsString('Ana', $team->text());
        $decide = $team->filter('form[data-testid^="wt-decide-"]')->form();
        $this->client->submit($decide);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($decide);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('1', $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_working_time_revision WHERE kind = 'correction'"));
        $this->client->request('POST', '/working-time/requests/01900000-0000-7000-8000-000000000000/approve', []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(404, $this->statusOf('GET', '/working-time/team?date=nope'));
    }

    public function testARejectionAndARefusedDecisionAreFlashed(): void
    {
        $this->bootDay();
        $request = $this->service(CorrectionServiceInterface::class)->request(new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Void, $this->firstSegment(), null, null, null, 'Voiding the first segment'));
        $this->actAs('mia', 'manage_team');
        $team   = $this->client->request('GET', '/working-time/team');
        $form   = $team->filter('form[data-testid^="wt-decide-"]')->form();
        $name   = 'wt_decision_' . $request->getId();
        $values = $form->getValues();
        $this->client->request('POST', '/working-time/requests/' . $request->getId() . '/reject', [$name => ['version' => $values[$name . '[version]'] ?? '', 'comment' => 'No', '_token' => $values[$name . '[_token]'] ?? '']]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame('rejected', $this->connection()->fetchOne('SELECT status FROM nowo_working_time_correction'));
    }

    public function testAManagerRecordsAndTheWorkerAnswers(): void
    {
        $this->bootDay();
        $this->actAs('mia', 'manage_team');
        $page = $this->client->request('GET', '/working-time/team/day?worker=ana&date=2026-11-18');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $page->filter('[data-testid="wt-correction-form"] form')->form();
        $this->client->submit($form, ['wt_correction[operation]' => 'add', 'wt_correction[segmentType]' => 'work', 'wt_correction[start]' => '17:00', 'wt_correction[end]' => '17:30', 'wt_correction[reason]' => 'Too late in the future']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['wt_correction[operation]' => 'add', 'wt_correction[segmentType]' => 'work', 'wt_correction[start]' => '06:00', 'wt_correction[end]' => '07:00', 'wt_correction[reason]' => 'Early shift from the door log']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/working-time/team/record?worker=ana&date=2026-11-18', []);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(404, $this->statusOf('GET', '/working-time/team/day?worker=&date=2026-11-18'));
        $id = $this->text('SELECT id FROM nowo_working_time_workday');
        $this->client->request('GET', '/working-time/day/' . $id);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'a manager is sent to the team day');
        $this->client->request('GET', '/working-time/team/day?worker=mia&date=2026-11-18');
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="wt-correction-form"]'), 'never on one\'s own day');

        $this->ctx()->actor = 'ana';
        $day                = $this->client->request('GET', '/working-time/date/2026-11-18');
        $form               = $day->filter('[data-testid="wt-answer-form"] form')->form();
        $this->client->submit($form, [$form->getName() . '[answer]' => 'contest', $form->getName() . '[comment]' => 'no']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, [$form->getName() . '[answer]' => 'acknowledge']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_working_time_revision WHERE kind = 'acknowledged'"));
        $this->client->request('POST', $form->getUri(), []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/working-time/corrections/01900000-0000-7000-8000-000000000000/answer', []);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testSchedulesExportsSettingsAndPreferences(): void
    {
        $this->bootDay();
        $this->ctx()->grant('acme', 'ana', 'manage_team', 'export', 'manage_settings');
        $page = $this->client->request('GET', '/working-time/schedules');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 2000));
        $form = $page->filter('[data-testid="wt-schedule-form"] form')->form();
        $this->client->submit($form, ['wt_schedule[scope]' => 'tenant', 'wt_schedule[validFrom]' => '2026-11-20', 'wt_schedule[timezone]' => 'Europe/Madrid', 'wt_schedule[mon]' => '09:00-14:00, 15:00-18:00']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['wt_schedule[scope]' => 'tenant', 'wt_schedule[validFrom]' => '2026-11-01', 'wt_schedule[timezone]' => 'Europe/Madrid']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['wt_schedule[scope]' => 'tenant', 'wt_schedule[validFrom]' => '2026-11-20', 'wt_schedule[timezone]' => 'Europe/Madrid', 'wt_schedule[mon]' => '14:00-09:00']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['wt_schedule[scope]' => 'group', 'wt_schedule[validFrom]' => '2026-11-20', 'wt_schedule[timezone]' => 'Europe/Madrid']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', '/working-time/schedules');
        $this->client->submit($page->filter('form[data-testid="wt-withdraw"]')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/working-time/schedules/01900000-0000-7000-8000-000000000000/withdraw', []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $page = $this->client->request('GET', '/working-time/exports');
        $form = $page->filter('[data-testid="wt-export-form"] form')->form();
        $this->client->submit($form, ['wt_export[scope]' => 'own', 'wt_export[from]' => '2026-11', 'wt_export[to]' => '2026-11', 'wt_export[format]' => 'csv']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['wt_export[scope]' => 'worker', 'wt_export[from]' => '2026-11', 'wt_export[to]' => '2026-11', 'wt_export[format]' => 'csv']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['wt_export[scope]' => 'team', 'wt_export[from]' => '2026-11', 'wt_export[to]' => '2026-11', 'wt_export[format]' => 'zip']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', '/working-time/exports');
        $this->client->click($page->filter('[data-testid="wt-export-download"]')->first()->link());
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(64, strlen((string) $response->headers->get('X-Content-SHA256')));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $page = $this->client->request('GET', '/working-time/settings');
        $form = $page->filter('form')->reduce(static fn (Crawler $node): bool => $node->filter('[name="wt_settings[version]"]')->count() > 0)->form();
        $this->client->submit($form, ['wt_settings[retentionYears]' => '5', 'wt_settings[ipEvidence]' => true, 'wt_settings[ipNotice]' => '']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['wt_settings[retentionYears]' => '5', 'wt_settings[ipEvidence]' => false]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('The settings were saved.', $this->client->followRedirect()->text());
        $this->client->submit($form, ['wt_settings[retentionYears]' => '6']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'stale version');
        self::assertSame('1', $this->client->getResponse()->headers->get('Retry-After'));
        $this->client->submit($form, ['wt_settings[flexMinutes]' => '999']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $page = $this->client->request('GET', '/working-time/preferences');
        $this->client->submit($page->filter('form')->form(), ['wt_preferences[monthlySummary]' => false]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/working-time/preferences', ['wt_preferences' => ['monthlySummary' => '1']]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testTheHeaderClockFollowsTheState(): void
    {
        $this->bootDay();
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->createTemplate('{{ working_time_header_clock() }}')->render();
        self::assertStringContainsString('data-status="closed"', $html);
        $this->ctx()->actor = null;
        self::assertSame('', trim($twig->createTemplate('{{ working_time_header_clock() }}')->render()));
    }

    private function bootDay(): void
    {
        $this->boot();
        $this->enable('acme', ['ana', 'mia']);
        $this->ctx()->manages['acme']['mia'] = ['ana'];
        $this->actAs('ana', 'clock');
        $this->clock()->modify('2026-11-18 08:00:00');
        $this->act(ClockAction::ClockIn);
        $this->act(ClockAction::ClockOut, 240);
        $this->clock()->modify('2026-11-18 16:00:00');
    }

    private function firstSegment(): string
    {
        return $this->text('SELECT id FROM nowo_working_time_segment ORDER BY started_at');
    }
}
