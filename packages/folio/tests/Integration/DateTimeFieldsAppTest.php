<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\ChecksFrameBalance;
use Preflow\Testing\ContextFactory;
use Preflow\View\TemplateEngineInterface;

/**
 * The datetime, time and daterange fields in Folio (0.91), with the site zone Europe/Zurich from
 * config/app.php (never the machine's): what an editor types is read in that zone and stored in
 * UTC, the clock-change rules, the exact messages on the field (also in a block), the list, a
 * conditional field of each type, the page's template functions and transfer.
 */
final class DateTimeFieldsAppTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    private const string UID = 'eeeeeeeeeeeeeeee';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 1, 'engine' => 'twig', 'timezone' => 'Europe/Zurich', 'key' => 'folio-integration-test-key-0123456789abcdef'];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\EventSlotBlock::class]];\n");
        $this->writeModel('event', ['label' => 'Events', 'publishing' => false, 'list' => ['columns' => ['name', 'starts_at', 'opens', 'stay']], 'fields' => [
            'name' => ['type' => 'string'],
            'starts_at' => ['type' => 'datetime', 'label' => 'Starts'],
            'tokyo' => ['type' => 'datetime', 'label' => 'Tokyo time', 'zone' => 'Asia/Tokyo'],
            'opens' => ['type' => 'time', 'label' => 'Opens'],
            'stay' => ['type' => 'daterange', 'label' => 'Stay'],
            'slot' => ['type' => 'daterange', 'label' => 'Slot', 'with_time' => true],
            'kind' => ['type' => 'select', 'options' => ['timed' => 'Timed', 'open' => 'Open']],
            'extra_at' => ['type' => 'datetime', 'show_when' => ['field' => 'kind', '=' => 'timed']],
            'extra_time' => ['type' => 'time', 'show_when' => ['field' => 'kind', '=' => 'timed']],
            'extra_range' => ['type' => 'daterange', 'show_when' => ['field' => 'kind', '=' => 'timed']],
            'sections' => ['type' => 'blocks'],
        ]]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'name' => 'Gala', 'starts_at' => '2026-07-15T12:30', 'tokyo' => '2026-07-15T12:30', 'opens' => '9:05',
            'stay' => ['start' => '2026-03-01', 'end' => '2026-03-07'],
            'slot' => ['start' => '2026-07-15T09:00', 'end' => '2026-07-15T17:30'],
            'kind' => 'open',
        ];
    }

    #[RunInSeparateProcess]
    public function test_the_editors_name_their_inputs_and_the_zone(): void
    {
        $html = (string) $this->get('/folio/event/new')->getBody();

        $this->assertStringContainsString('<input type="datetime-local" name="starts_at"', $html);
        $this->assertStringContainsString('data-zone="Europe/Zurich"', $html);
        $this->assertStringContainsString('data-zone="Asia/Tokyo"', $html);
        $this->assertStringContainsString('<input type="time" name="opens"', $html);
        $this->assertStringContainsString('name="stay[start]"', $html);
        $this->assertStringContainsString('name="stay[end]"', $html);
        $this->assertStringContainsString('<input type="datetime-local" id="folio-daterange-slot-start" name="slot[start]"', $html);
    }

    #[RunInSeparateProcess]
    public function test_a_save_stores_utc_and_the_editor_reads_the_site_zone_back(): void
    {
        $res = $this->post('/folio/event', $this->valid());
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());

        $stored = $this->first();
        $this->assertSame('2026-07-15T10:30:00Z', $stored->get('starts_at'), '12:30 in Zurich in July is 10:30 UTC');
        $this->assertSame('2026-07-15T03:30:00Z', $stored->get('tokyo'), 'the field\'s own zone');
        $this->assertSame('09:05', $stored->get('opens'));
        $stay = $stored->get('stay');
        $this->assertSame(['start' => '2026-03-01', 'end' => '2026-03-07'], is_string($stay) ? json_decode($stay, true) : $stay);
        $slot = $stored->get('slot');
        $this->assertSame(['start' => '2026-07-15T07:00:00Z', 'end' => '2026-07-15T15:30:00Z'], is_string($slot) ? json_decode($slot, true) : $slot);

        $html = (string) $this->get('/folio/event/' . $stored->getId() . '/edit')->getBody();
        $this->assertStringContainsString('name="starts_at" value="2026-07-15T12:30"', $html);
        $this->assertStringContainsString('name="tokyo" value="2026-07-15T12:30"', $html);
        $this->assertStringContainsString('name="opens" value="09:05"', $html);
        $this->assertStringContainsString('name="stay[start]" value="2026-03-01"', $html);
        $this->assertStringContainsString('name="slot[end]" value="2026-07-15T17:30"', $html);
    }

    #[RunInSeparateProcess]
    public function test_a_time_the_clocks_skip_is_refused_and_a_repeated_one_takes_the_earlier_instant(): void
    {
        $res = $this->post('/folio/event', ['starts_at' => '2026-03-29T02:30'] + $this->valid());
        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('<div class="form-error">2026-03-29 02:30 does not exist in Europe/Zurich (clocks go forward). Choose another time.</div>', $body);
        $this->assertStringContainsString('value="2026-03-29T02:30"', $body, 'what was typed comes back');
        $this->assertSame([], $this->dm()->queryType('event')->get()->items());

        $res = $this->post('/folio/event', ['slot' => ['start' => '2026-03-29T02:30', 'end' => '2026-03-29T09:00']] + $this->valid());
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('2026-03-29 02:30 does not exist in Europe/Zurich', (string) $res->getBody());

        $res = $this->post('/folio/event', ['starts_at' => '2026-10-25T02:30'] + $this->valid());
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame('2026-10-25T00:30:00Z', $this->first()->get('starts_at'));
    }

    #[RunInSeparateProcess]
    public function test_a_save_refuses_each_type_with_the_cores_message_on_its_field(): void
    {
        foreach ([
            ['opens', '25:00', 'Must be a time of day as hours and minutes, such as 14:30.'],
            ['starts_at', 'next week', 'Must be a date and time in UTC, such as 2026-03-29T12:30:00Z.'],
            ['stay', ['start' => '2026-03-07', 'end' => '2026-03-01'], 'The end (2026-03-01) is before the start (2026-03-07). Choose an end on or after 2026-03-07.'],
            ['stay', ['start' => '2026-03-01', 'end' => ''], 'Must be a date range with a start and an end, such as 2026-03-01 – 2026-03-07.'],
            ['slot', ['start' => '2026-07-15T17:30', 'end' => '2026-07-15T09:00'], 'The end (2026-07-15 09:00) is before the start (2026-07-15 17:30). Choose an end on or after 2026-07-15 17:30.'],
        ] as [$field, $value, $message]) {
            $res = $this->post('/folio/event', [$field => $value] + $this->valid());
            $this->assertSame(422, $res->getStatusCode(), $field);
            $this->assertStringContainsString('<div class="form-error">' . htmlspecialchars($message, ENT_QUOTES | ENT_HTML5) . '</div>', (string) $res->getBody(), $field);
            $this->assertSame([], $this->dm()->queryType('event')->get()->items(), $field . ': nothing saved');
        }
    }

    #[RunInSeparateProcess]
    public function test_each_type_works_in_a_block(): void
    {
        $this->dm()->saveType(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('event'), ['uuid' => 'e2', 'name' => 'Blocks', 'sections' => [['_uid' => self::UID, '_block' => 'event_slot']]]), validate: false);
        $html = (string) $this->get('/folio/event/e2/edit')->getBody();
        foreach (['<input type="datetime-local" name="sections[' . self::UID . '][starts_at]"', 'name="sections[' . self::UID . '][doors]"', 'name="sections[' . self::UID . '][stay][start]"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $block = ['_block' => 'event_slot', 'starts_at' => '2026-07-15T12:30', 'doors' => '18:00', 'stay' => ['start' => '2026-07-15T09:00', 'end' => '2026-07-15T17:30']];
        $post = static fn (array $over): array => ['name' => 'Blocks', 'sections' => ['_order' => [self::UID], self::UID => $over + $block]];

        $res = $this->post('/folio/event/e2', $post(['starts_at' => '2026-03-29T02:30']));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('2026-03-29 02:30 does not exist in Europe/Zurich (clocks go forward). Choose another time.', (string) $res->getBody());
        $res = $this->post('/folio/event/e2', $post(['stay' => ['start' => '2026-07-15T17:30', 'end' => '2026-07-15T09:00']]));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('The end (2026-07-15 09:00) is before the start (2026-07-15 17:30).', (string) $res->getBody(), 'in the block\'s own zone (Asia/Tokyo): 09:00 there');

        $res = $this->post('/folio/event/e2', $post([]));
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $saved = $this->stored('event', 'e2')->get('sections')[0];
        $this->assertSame('2026-07-15T10:30:00Z', $saved['starts_at']);
        $this->assertSame('18:00', $saved['doors']);
        $this->assertSame(['start' => '2026-07-15T00:00:00Z', 'end' => '2026-07-15T08:30:00Z'], is_string($saved['stay']) ? json_decode($saved['stay'], true) : $saved['stay'], 'the block field\'s zone is Asia/Tokyo');
    }

    #[RunInSeparateProcess]
    public function test_a_hidden_field_of_each_type_keeps_its_value_and_a_shown_one_is_checked(): void
    {
        $kept = ['extra_at' => '2026-07-15T10:30:00Z', 'extra_time' => '09:05', 'extra_range' => ['start' => '2026-03-01', 'end' => '2026-03-07']];
        $this->dm()->saveType(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('event'), ['uuid' => 'e3', 'name' => 'Cond', 'kind' => 'timed'] + $kept), validate: false);

        $res = $this->post('/folio/event/e3', ['name' => 'Cond', 'kind' => 'open', 'extra_at' => 'bad', 'extra_time' => '99:99', 'extra_range' => ['start' => '2026-03-07', 'end' => '2026-03-01']]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $stored = $this->stored('event', 'e3');
        $this->assertSame('2026-07-15T10:30:00Z', $stored->get('extra_at'));
        $this->assertSame('09:05', $stored->get('extra_time'));
        $range = $stored->get('extra_range');
        $this->assertSame($kept['extra_range'], is_string($range) ? json_decode($range, true) : $range);

        $res = $this->post('/folio/event/e3', ['name' => 'Cond', 'kind' => 'timed', 'extra_time' => '99:99']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Must be a time of day', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_the_list_shows_the_cells_and_sorts_by_the_datetime(): void
    {
        foreach (['2026-09-01T12:00', '2026-01-01T12:00', '2026-05-01T12:00'] as $i => $when) {
            $this->assertSame(302, $this->post('/folio/event', ['name' => 'E' . $i, 'starts_at' => $when] + $this->valid())->getStatusCode());
        }

        $html = (string) $this->get('/folio/event')->getBody();
        $this->assertStringContainsString('09:05', $html);
        $this->assertStringContainsString(' – ', $html, 'the range cell');
        $this->assertMatchesRegularExpression('/May 1, 2026.{0,5}12:00/u', $html, 'in the site zone (the stored text is 10:00 UTC)');

        $order = static function (string $html): array {
            preg_match_all('~class="folio-row-link" href="[^"]*">E([012])</a>~', $html, $m);

            return array_values(array_unique($m[1]));
        };
        $this->assertSame(['1', '2', '0'], $order((string) $this->get('/folio/event?sort=starts_at')->getBody()));
        $this->assertSame(['0', '2', '1'], $order((string) $this->get('/folio/event?sort=-starts_at')->getBody()));
        $this->assertStringContainsString('sort=starts_at', $html, 'the header sorts');
    }

    #[RunInSeparateProcess]
    public function test_the_page_gets_the_site_zone_text(): void
    {
        $this->post('/folio/event', $this->valid());
        $e = $this->first();

        $this->assertSame(
            '2026-07-15 12:30|9:05 am|2026-03-01|7 Mar|2026-07-15 09:00',
            $this->page('{{ datetime_local(at) }}|{{ time_format(opens, "g:i a") }}|{{ daterange_start(stay) }}|{{ daterange_end(stay, "j M") }}|{{ daterange_start(slot) }}', ['at' => $e->get('starts_at'), 'opens' => $e->get('opens'), 'stay' => $e->get('stay'), 'slot' => $e->get('slot')]),
        );
    }

    #[RunInSeparateProcess]
    public function test_transfer_carries_the_stored_values_unchanged(): void
    {
        $values = ['name' => 'Gala', 'starts_at' => '2026-07-15T10:30:00Z', 'opens' => '09:05', 'stay' => ['start' => '2026-03-01', 'end' => '2026-03-07']];
        $this->working('event', 'e4', $values);
        $app = $this->app();
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $stream = fopen('php://temp', 'w+b');
        (new \Preflow\Folio\Transfer\Exporter(\Preflow\Folio\Transfer\ContentSet::fromApp($app), $dm))->export($stream);
        rewind($stream);
        $dm->deleteType('event', 'e4');
        $importer = new \Preflow\Folio\Transfer\Importer(\Preflow\Folio\Transfer\ContentSet::fromApp($app), $dm);
        $plan = $importer->read($stream);
        try {
            $importer->apply($plan);
        } finally {
            $plan->cleanup();
        }

        $stored = $this->stored('event', 'e4');
        $this->assertSame('2026-07-15T10:30:00Z', $stored->get('starts_at'));
        $this->assertSame('09:05', $stored->get('opens'));
        $stay = $stored->get('stay');
        $this->assertSame($values['stay'], is_string($stay) ? json_decode($stay, true) : $stay);
    }

    private function first(): DynamicRecord
    {
        return $this->dm()->queryType('event')->get()->items()[0] ?? throw new \RuntimeException('nothing saved');
    }

    /** @param array<string, mixed> $context */
    private function page(string $template, array $context): string
    {
        $name = '_dates_probe_' . md5($template) . '.twig';
        $this->write('app/pages/' . $name, $template);
        $app = $this->app();

        return Contexts::run(
            ContextFactory::forTest()->in('en'),
            static fn (): string => $app->container()->get(TemplateEngineInterface::class)->render($name, $context),
        );
    }
}
