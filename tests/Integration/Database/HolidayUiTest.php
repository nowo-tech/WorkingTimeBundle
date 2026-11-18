<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Nowo\WorkingTimeBundle\Tests\Fixtures\RecordedHolidayApi;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;

use function array_fill;
use function array_replace_recursive;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;

/**
 * The holiday pages end to end (contract section 11.1, holidays_manage), with the register and leave switched off
 * (the stand-alone component, contract section 19 S2 "stand-alone holiday demo page"): calendars, days, a CSV import
 * (preview → confirm), a festivos.io import from the recorded API (preview with the attribution → confirm), work
 * centres, the municipality list (cache only; loading it is a button), and the 404 / 403 / 400 answers.
 */
final class HolidayUiTest extends UiTestCase
{
    public function testTheStandAlonePagesWithoutTheRegisterOrLeave(): void
    {
        $this->bootStandAlone();
        self::assertSame(404, $this->statusOf('GET', '/working-time'), 'no register routes');
        self::assertSame(404, $this->statusOf('GET', '/leave'), 'no leave routes');
        $crawler = $this->client->request('GET', '/holidays');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="wt-holiday-centres-empty"]'));

        $this->client->submit($crawler->filter('[data-testid="wt-holiday-calendar-form"] form')->form(), [
            'wt_holiday_calendar[name]'         => 'Madrid',
            'wt_holiday_calendar[scope]'        => 'local',
            'wt_holiday_calendar[year]'         => '2026',
            'wt_holiday_calendar[country]'      => 'es',
            'wt_holiday_calendar[municipality]' => '28079',
        ]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Madrid (2026)', $crawler->text());
        $calendarUrl = (string) $this->client->getRequest()->getPathInfo();

        // A day by hand, then its edit and removal.
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-day-form"] form')->form(), ['wt_holiday_day[date]' => '2026-05-15', 'wt_holiday_day[name]' => 'San Isidro']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('San Isidro', $crawler->filter('table, .nowo-ui-list')->text());
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-day-form"] form')->form(), ['wt_holiday_day[date]' => '2026-05-15', 'wt_holiday_day[name]' => 'Twice']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('already has a holiday', (string) $this->client->getResponse()->getContent());
        $edit    = $this->client->getCrawler()->selectLink('Edit')->link();
        $crawler = $this->client->click($edit);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-day-form"] form')->form(), ['wt_holiday_day[date]' => '2027-05-15', 'wt_holiday_day[name]' => 'Wrong year']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="wt-holiday-day-form"] form')->form(), ['wt_holiday_day[date]' => '2026-05-14', 'wt_holiday_day[name]' => 'San Isidro (moved)']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('San Isidro (moved)', $crawler->text());

        // festivos.io: preview (nothing saved, attribution shown), then confirm (asked again).
        $crawler = $this->client->submit($crawler->filter('[data-testid="wt-holiday-provider-festivos_io"] form')->form());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('festivos.io — CC BY 4.0', $crawler->filter('[data-testid="wt-holiday-attribution"]')->text());
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_holiday_day'));
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-import-confirm"] form')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame(15, $this->int('SELECT COUNT(*) FROM nowo_holiday_day'), 'the day by hand on 14 May stays next to festivos.io\'s 14');
        self::assertStringContainsString('festivos.io — CC BY 4.0', $crawler->filter('[data-testid="wt-holiday-attribution"]')->text());

        // A CSV file: preview, confirm.
        $file = (string) tempnam(sys_get_temp_dir(), 'wt-csv');
        file_put_contents($file, "date,name,scope\n2026-12-24,Nochebuena,local\n2026-12-25,Navidad,local\n2026-12-31,Otra,national\n");
        $form = $crawler->filter('[data-testid="wt-holiday-import-form"] form')->form();
        $this->upload($form, $file);
        $crawler = $this->client->submit($form);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Already there', $crawler->text());
        self::assertStringContainsString('Another scope', $crawler->text());
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-import-confirm"] form')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('1 holiday(s) imported.', $crawler->text());
        self::assertSame(16, $this->int('SELECT COUNT(*) FROM nowo_holiday_day'));

        // A day removed; the calendar changed; a work centre; the calendar removed.
        $this->client->submit($crawler->filter('form[data-testid="wt-holiday-day-remove"]')->first()->form());
        $crawler = $this->client->followRedirect();
        self::assertSame(15, $this->int('SELECT COUNT(*) FROM nowo_holiday_day'));
        $forms = $crawler->filter('[data-testid="wt-holiday-calendar-form"] form');
        $this->client->submit($forms->form(), ['wt_holiday_calendar[name]' => 'Madrid city', 'wt_holiday_calendar[country]' => 'Spain']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'a bad country code');
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="wt-holiday-calendar-form"] form')->form(), ['wt_holiday_calendar[name]' => 'Madrid city', 'wt_holiday_calendar[country]' => 'ES']);
        $this->client->followRedirect();
        $crawler = $this->client->request('GET', '/holidays');
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-centre-form"] form')->form(), ['wt_work_centre[name]' => 'Office', 'wt_work_centre[default]' => '1']);
        $crawler = $this->client->followRedirect();
        $this->client->click($crawler->filter('a[data-testid="wt-holiday-centre"]')->link());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="wt-holiday-centre-form"] form')->form(), ['wt_work_centre[name]' => ' ']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="wt-holiday-centre-form"] form')->form(), ['wt_work_centre[name]' => 'Head office']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Head office', $crawler->text());
        $this->client->click($crawler->filter('a[data-testid="wt-holiday-centre"]')->link());
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="wt-holiday-centre-remove"] form')->form());
        $this->client->followRedirect();
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_holiday_work_centre'));
        $crawler = $this->client->request('GET', $calendarUrl);
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-calendar-remove"] form')->form());
        $this->client->followRedirect();
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_holiday_calendar'));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_holiday_day'));
    }

    public function testRefusalsAndFailures(): void
    {
        $this->bootStandAlone();
        $crawler = $this->client->request('GET', '/holidays');
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-calendar-form"] form')->form(), ['wt_holiday_calendar[name]' => 'Spain', 'wt_holiday_calendar[year]' => '1999']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="wt-holiday-calendar-form"] form')->form(), ['wt_holiday_calendar[name]' => 'Spain', 'wt_holiday_calendar[year]' => '2026', 'wt_holiday_calendar[country]' => 'ES']);
        $crawler = $this->client->followRedirect();
        $url     = (string) $this->client->getRequest()->getPathInfo();

        // A provider that fails, a bad place, an empty upload, a bad file and bad confirms.
        $this->api()->fail = 'timeout';
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-provider-openholidays"] form')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('could not be read', $crawler->text());
        $this->api()->fail = null;
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-provider-festivos_io"] form')->form(), ['wt_holiday_provider_festivos_io[municipality]' => '123']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('five-digit INE code', $crawler->text());
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-import-form"] form')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('UTF-8 text file', $crawler->text());
        $file = (string) tempnam(sys_get_temp_dir(), 'wt-csv');
        file_put_contents($file, "day,label\n");
        $form = $crawler->filter('[data-testid="wt-holiday-import-form"] form')->form();
        $this->upload($form, $file);
        $crawler = $this->client->submit($form);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="wt-holiday-import-error"]'));
        self::assertCount(0, $crawler->filter('[data-testid="wt-holiday-import-confirm"]'));
        file_put_contents($file, "date,name\n2026-01-01,Año Nuevo\n");
        $form = $this->client->request('GET', $url)->filter('[data-testid="wt-holiday-import-form"] form')->form();
        $this->upload($form, $file);
        $confirm = $this->client->submit($form)->filter('[data-testid="wt-holiday-import-confirm"] form')->form();
        $this->client->submit($confirm, ['wt_holiday_import_confirm[rows]' => 'not json']);
        self::assertStringContainsString('0 holiday(s) imported.', $this->client->followRedirect()->text(), 'bad rows are dropped');
        $this->client->submit($confirm, ['wt_holiday_import_confirm[rows]' => (string) json_encode(array_fill(0, 201, ['date' => '2026-01-01', 'name' => 'X']))]);
        self::assertStringContainsString('more than 200 rows', $this->client->followRedirect()->text());
        $crawler = $this->client->request('GET', $url);
        $crawler = $this->client->submit($crawler->filter('[data-testid="wt-holiday-provider-openholidays"] form')->form());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->api()->fail = 'body';
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-import-confirm"] form')->form());
        self::assertStringContainsString('could not be read', $this->client->followRedirect()->text(), 'the confirm asks again; a failure saves nothing');
        $this->api()->fail = null;
        $this->client->request('POST', $url . '/import/confirm', ['wt_holiday_import_confirm' => ['rows' => '[]']]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode(), 'no CSRF token');
        $this->client->request('POST', $url . '/provider/festivos_io/confirm', []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $url . '/provider/festivos_io', []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $url . '/add', []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $url . '/remove', []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(404, $this->statusOf('POST', $url . '/provider/nope'), 'unknown provider');
        self::assertSame(404, $this->statusOf('GET', '/holidays/01900000-0000-7000-8000-000000000000'));
        self::assertSame(404, $this->statusOf('GET', $url . '/holiday/01900000-0000-7000-8000-000000000000/edit'));
        self::assertSame(404, $this->statusOf('GET', '/holidays/centres/01900000-0000-7000-8000-000000000000'));

        // The municipality list: empty until loaded, then searched from the cache.
        $this->client->request('GET', '/holidays/municipalities?query=madrid');
        self::assertSame(['results' => [], 'loaded' => false], json_decode((string) $this->client->getResponse()->getContent(), true));
        $crawler = $this->client->request('GET', '/holidays');
        $this->client->submit($crawler->filter('[data-testid="wt-municipalities"] form')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('20 municipalities loaded.', $crawler->text());
        $this->client->request('GET', '/holidays/municipalities?query=madrid');
        $found = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($found);
        $results = $found['results'] ?? null;
        self::assertIsArray($results);
        $first = $results[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('28079', $first['value'] ?? null);
        $this->api()->fail = '404';
        $this->client->submit($crawler->filter('[data-testid="wt-municipalities"] form')->form());
        self::assertStringContainsString('could not be read', $this->client->followRedirect()->text());
        $this->client->request('POST', '/holidays/municipalities/refresh', []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Deny by default.
        $this->ctx()->grants = [];
        self::assertSame(403, $this->statusOf('GET', '/holidays'));
        self::assertSame(403, $this->statusOf('GET', '/holidays/municipalities'));
        $this->ctx()->actor = null;
        self::assertSame(404, $this->statusOf('GET', '/holidays'));
    }

    public function testFileImportsSwitchedOff(): void
    {
        $this->bootStandAlone(['holidays' => ['providers' => ['file' => ['enabled' => false]]]]);
        $crawler = $this->client->request('GET', '/holidays');
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-calendar-form"] form')->form(), ['wt_holiday_calendar[name]' => 'Spain', 'wt_holiday_calendar[year]' => '2026']);
        $crawler = $this->client->followRedirect();
        $file    = (string) tempnam(sys_get_temp_dir(), 'wt-csv');
        file_put_contents($file, "date,name\n2026-01-01,X\n");
        $form = $crawler->filter('[data-testid="wt-holiday-import-form"] form')->form();
        $this->upload($form, $file);
        $this->client->submit($form);
        self::assertStringContainsString('File imports are switched off.', $this->client->followRedirect()->text());
    }

    public function testWithoutFestivosIoThereIsNoTownList(): void
    {
        $this->bootStandAlone(['holidays' => ['providers' => ['festivos_io' => ['enabled' => false]]]]);
        $crawler = $this->client->request('GET', '/holidays');
        self::assertCount(0, $crawler->filter('[data-testid="wt-municipalities"]'));
        $this->client->request('GET', '/holidays/municipalities?query=madrid');
        self::assertSame(['results' => [], 'loaded' => false], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->client->submit($crawler->filter('[data-testid="wt-holiday-calendar-form"] form')->form(), ['wt_holiday_calendar[name]' => 'Spain', 'wt_holiday_calendar[year]' => '2026']);
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('[data-testid="wt-holiday-provider-festivos_io"]'));
        self::assertCount(1, $crawler->filter('[data-testid="wt-holiday-provider-openholidays"]'));
    }

    /**
     * Register and leave off; Ana manages holidays.
     *
     * @param array<string, mixed> $config
     */
    private function bootStandAlone(array $config = []): void
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(['register' => ['enabled' => false], 'leave' => ['enabled' => false]], $config);
        $this->boot($merged);
        $this->ctx()->member('acme', 'ana', 'Ana');
        $this->actAs('ana', 'holidays_manage');
    }

    private function upload(Form $form, string $file): void
    {
        $field = $form['wt_holiday_import[file]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($file);
    }

    private function api(): RecordedHolidayApi
    {
        $api = $this->kernel->getContainer()->get('test.holiday_api');
        self::assertInstanceOf(RecordedHolidayApi::class, $api);

        return $api;
    }
}
