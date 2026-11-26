<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\ChecksFrameBalance;
use Preflow\Testing\ContextFactory;
use Preflow\View\TemplateEngineInterface;

/**
 * The embed, table and location fields in Folio (0.91): the editors, a save storing the core's
 * shapes, the core's refusals on the field (also in a block), a table translated cell by cell
 * (the translation status sees each cell), the list cells, the page output (no request to the
 * video's host before a click) and transfer.
 */
final class EmbedTableLocationAppTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    private const string UID = 'abcdef0123456789';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\VenueBlock::class]];\n");
        $this->writeModel('venue', ['label' => 'Venues', 'publishing' => false, 'list' => ['columns' => ['name', 'video', 'prices', 'place']], 'fields' => [
            'name' => ['type' => 'string'],
            'video' => ['type' => 'embed', 'label' => 'Video'],
            'prices' => ['type' => 'table', 'label' => 'Prices', 'max_columns' => 3, 'max_rows' => 4],
            'place' => ['type' => 'location', 'label' => 'Place'],
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
            'name' => 'Hall',
            'video' => ['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ'],
            'prices' => ['head' => ['Size', 'CHF', ''], 'rows' => [['Small', '10', ''], ['Large', '14', ''], ['', '', '']]],
            'place' => ['lat' => '47,3769', 'lng' => '8.5417', 'address' => 'Bahnhofplatz, Zürich'],
        ];
    }

    /** @return mixed the stored value, decoded from SQL's JSON text */
    private static function decoded(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }

    #[RunInSeparateProcess]
    public function test_the_editors_name_their_inputs(): void
    {
        $html = (string) $this->get('/folio/venue/new')->getBody();

        $this->assertStringContainsString('name="video[url]"', $html);
        $this->assertStringContainsString('name="video[poster]', $html, 'the poster picker');
        $this->assertStringContainsString('name="prices[head][0]"', $html);
        $this->assertStringContainsString('name="prices[rows][1][1]"', $html);
        $this->assertStringContainsString('<input type="number" id="folio-location-place-lat" name="place[lat]"', $html);
        $this->assertStringContainsString('name="place[address]"', $html);
    }

    #[RunInSeparateProcess]
    public function test_a_save_stores_the_cores_shapes_and_the_editor_reads_them_back(): void
    {
        $res = $this->post('/folio/venue', $this->valid() + ['_locale' => 'en']);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());

        $stored = $this->first();
        $this->assertSame(['provider' => 'youtube', 'id' => 'aqz-KE-bpKQ', 'url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'poster' => null], self::decoded($stored->get('video')));
        $this->assertSame(
            ['head' => [['en' => 'Size'], ['en' => 'CHF']], 'rows' => [[['en' => 'Small'], ['en' => '10']], [['en' => 'Large'], ['en' => '14']]]],
            self::decoded($stored->get('prices')),
            'the empty row and column are dropped; a multilingual site stores a text per language',
        );
        $this->assertSame(['lat' => 47.3769, 'lng' => 8.5417, 'address' => 'Bahnhofplatz, Zürich'], self::decoded($stored->get('place')));

        $html = (string) $this->get('/folio/venue/' . $stored->getId() . '/edit')->getBody();
        $this->assertStringContainsString('value="https://www.youtube.com/watch?v=aqz-KE-bpKQ"', $html);
        $this->assertStringContainsString('Plays from YouTube (video aqz-KE-bpKQ).', $html);
        $this->assertStringContainsString('name="prices[rows][1][0]" value="Large"', $html);
        $this->assertStringContainsString('name="place[lat]" value="47.3769"', $html);
    }

    #[RunInSeparateProcess]
    public function test_a_save_refuses_each_type_with_the_cores_message_on_its_field(): void
    {
        foreach ([
            ['video', ['url' => 'https://www.dailymotion.com/video/x8abc'], 'Videos from www.dailymotion.com cannot be embedded here; paste the address of a video on YouTube, Vimeo.'],
            ['video', ['url' => 'not a video'], 'Must be the address of a video on YouTube, Vimeo, such as https://www.youtube.com/watch?v=aqz-KE-bpKQ.'],
            ['prices', ['head' => ['a', 'b', 'c', 'd'], 'rows' => [['1', '2', '3', '4']]], 'This table has 4 columns; at most 3 are allowed. Remove a column.'],
            ['prices', ['rows' => [['1'], ['2'], ['3'], ['4'], ['5']]], 'This table has 5 rows; at most 4 are allowed. Remove a row.'],
            ['place', ['lat' => '95', 'lng' => '8', 'address' => ''], 'The latitude must be a number from -90 to 90 (north is positive), such as 47.3769.'],
            ['place', ['lat' => '47', 'lng' => 'east', 'address' => ''], 'Must be a place with a latitude and a longitude, such as 47.3769, 8.5417.'],
        ] as [$field, $value, $message]) {
            $res = $this->post('/folio/venue', [$field => $value] + $this->valid());
            $this->assertSame(422, $res->getStatusCode(), $field);
            $body = (string) $res->getBody();
            $this->assertStringContainsString('<div class="form-error">' . htmlspecialchars($message, ENT_QUOTES | ENT_HTML5) . '</div>', $body, $field);
            $this->assertSame([], $this->dm()->queryType('venue')->get()->items(), $field . ': nothing saved');
        }
        $res = $this->post('/folio/venue', ['video' => ['url' => 'https://example.org/clip']] + $this->valid());
        $this->assertStringContainsString('value="https://example.org/clip"', (string) $res->getBody(), 'what was typed comes back');
    }

    #[RunInSeparateProcess]
    public function test_each_type_works_in_a_block(): void
    {
        $this->dm()->saveType(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('venue'), ['uuid' => 'v2', 'name' => 'Blocks', 'sections' => [['_uid' => self::UID, '_block' => 'venue_info']]]), validate: false);
        $html = (string) $this->get('/folio/venue/v2/edit')->getBody();
        foreach (['name="sections[' . self::UID . '][video][url]"', 'name="sections[' . self::UID . '][hours][rows][0][0]"', 'name="sections[' . self::UID . '][place][lat]"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $block = ['_block' => 'venue_info', 'video' => ['url' => 'https://vimeo.com/76979871'], 'hours' => ['head' => ['Day', 'Hours'], 'rows' => [['Mon', '9–17']]], 'place' => ['lat' => '-33.8568', 'lng' => '151.2153', 'address' => '']];
        $post = static fn (array $over): array => ['name' => 'Blocks', '_locale' => 'en', 'sections' => ['_order' => [self::UID], self::UID => $over + $block]];

        $res = $this->post('/folio/venue/v2', $post(['video' => ['url' => 'https://www.dailymotion.com/video/x8abc']]));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Videos from www.dailymotion.com cannot be embedded here', (string) $res->getBody());
        $res = $this->post('/folio/venue/v2', $post(['hours' => ['rows' => [['a', 'b', 'c']]]]));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('This table has 3 columns; at most 2 are allowed. Remove a column.', (string) $res->getBody(), 'the block field\'s own max_columns');
        $res = $this->post('/folio/venue/v2', $post(['place' => ['lat' => '0', 'lng' => '-181', 'address' => '']]));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('The longitude must be a number from -180 to 180', (string) $res->getBody());

        $res = $this->post('/folio/venue/v2', $post([]));
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $saved = $this->stored('venue', 'v2')->get('sections')[0];
        $this->assertSame(['provider' => 'vimeo', 'id' => '76979871', 'url' => 'https://vimeo.com/76979871', 'poster' => null], self::decoded($saved['video']));
        $this->assertSame(['head' => [['en' => 'Day'], ['en' => 'Hours']], 'rows' => [[['en' => 'Mon'], ['en' => '9–17']]]], self::decoded($saved['hours']));
        $this->assertSame(['lat' => -33.8568, 'lng' => 151.2153, 'address' => null], self::decoded($saved['place']));
    }

    #[RunInSeparateProcess]
    public function test_a_table_is_translated_cell_by_cell_and_the_translation_status_sees_each_cell(): void
    {
        $this->assertSame(302, $this->post('/folio/venue', $this->valid() + ['_locale' => 'en'])->getStatusCode());
        $id = (string) $this->first()->getId();
        $status = $this->app()->container()->get(\Preflow\Folio\Content\TranslationStatus::class);
        $def = $this->dm()->getTypeRegistry()->get('venue');

        $missing = array_map(static fn ($t) => $t->path, $status->missing($def, $this->stored('venue', $id), 'de'));
        $this->assertSame(['prices.head.0', 'prices.head.1', 'prices.rows.0.0', 'prices.rows.0.1', 'prices.rows.1.0', 'prices.rows.1.1'], $missing);

        $editor = (string) $this->get('/folio/venue/' . $id . '/edit?locale=de')->getBody();
        $this->assertStringContainsString('name="prices[rows][0][0]" value="" aria-label="Row 1, column 1" placeholder="Small"', $editor);
        $this->assertStringNotContainsString('name="prices[rows][2]', $editor, 'another language keeps the rows');

        $res = $this->post('/folio/venue/' . $id, ['_locale' => 'de', 'name' => 'Hall', 'video' => $this->valid()['video'], 'place' => $this->valid()['place'],
            'prices' => ['head' => ['Grösse', 'CHF'], 'rows' => [['Klein', '10'], ['Gross', '14']]]]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $prices = self::decoded($this->stored('venue', $id)->get('prices'));
        $this->assertSame(['en' => 'Small', 'de' => 'Klein'], $prices['rows'][0][0]);
        $this->assertSame(['en' => 'Size', 'de' => 'Grösse'], $prices['head'][0]);
        $this->assertSame([], $status->missing($def, $this->stored('venue', $id), 'de'));

        $this->assertSame(
            '<table class="preflow-table"><thead><tr><th scope="col">Grösse</th><th scope="col">CHF</th></tr></thead><tbody><tr><td>Klein</td><td>10</td></tr><tr><td>Gross</td><td>14</td></tr></tbody></table>',
            $this->page('{{ table_html(prices) }}', ['prices' => $prices], 'de'),
        );
    }

    #[RunInSeparateProcess]
    public function test_the_list_shows_the_cells_without_a_sort_header(): void
    {
        $this->assertSame(302, $this->post('/folio/venue', $this->valid())->getStatusCode());

        $html = (string) $this->get('/folio/venue')->getBody();
        $this->assertStringContainsString('YouTube · aqz-KE-bpKQ', $html);
        $this->assertStringContainsString('2 rows × 2 columns', $html);
        $this->assertStringContainsString('47.3769, 8.5417 · Bahnhofplatz, Zürich', $html);
        foreach (['video', 'prices', 'place'] as $field) {
            $this->assertStringNotContainsString('sort=' . $field, $html, $field);
        }
    }

    #[RunInSeparateProcess]
    public function test_the_page_plays_the_video_only_after_a_click(): void
    {
        $this->assertSame(302, $this->post('/folio/venue', $this->valid())->getStatusCode());
        $video = $this->first()->get('video');
        $app = $this->app();

        $html = $this->page('{{ embed_player(video, "The hall") }}{{ head() }}{{ assets() }}', ['video' => $video]);
        $outside = (string) preg_replace('~<template\b[^>]*>.*?</template>~s', '', $html);
        $this->assertStringNotContainsString('<iframe', $outside);
        $this->assertDoesNotMatchRegularExpression('~\s(src|srcset)="https?://~', $outside, 'nothing outside the template loads from another host');
        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?autoplay=1" title="The hall"', $html);
        $this->assertMatchesRegularExpression('~<script nonce="[^"]+">~', $html);

        // Folio's frontend output of the field is the same player.
        $rendered = Contexts::run(ContextFactory::forTest()->in('en'), static fn (): string => $app->container()->get(FieldTypeRegistry::class)->get('embed')->renderFrontend($video, []));
        $this->assertStringContainsString('<button type="button" class="preflow-embed-play" data-preflow-embed-play aria-label="Play video: Video (YouTube)">', $rendered);
    }

    #[RunInSeparateProcess]
    public function test_transfer_carries_the_stored_values_unchanged(): void
    {
        $values = [
            'name' => 'Hall',
            'video' => ['provider' => 'youtube', 'id' => 'aqz-KE-bpKQ', 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'poster' => null],
            'prices' => ['head' => null, 'rows' => [[['en' => 'Small', 'de' => 'Klein'], '10']]],
            'place' => ['lat' => 47.3769, 'lng' => 8.5417, 'address' => null],
        ];
        $this->working('venue', 'v4', $values);
        $app = $this->app();
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $stream = fopen('php://temp', 'w+b');
        (new \Preflow\Folio\Transfer\Exporter(\Preflow\Folio\Transfer\ContentSet::fromApp($app), $dm))->export($stream);
        rewind($stream);
        $dm->deleteType('venue', 'v4');
        $importer = new \Preflow\Folio\Transfer\Importer(\Preflow\Folio\Transfer\ContentSet::fromApp($app), $dm);
        $plan = $importer->read($stream);
        try {
            $importer->apply($plan);
        } finally {
            $plan->cleanup();
        }

        $stored = $this->stored('venue', 'v4');
        foreach (['video', 'prices', 'place'] as $field) {
            $this->assertSame($values[$field], self::decoded($stored->get($field)), $field);
        }
    }

    private function first(): DynamicRecord
    {
        return $this->dm()->queryType('venue')->get()->items()[0] ?? throw new \RuntimeException('nothing saved');
    }

    /** @param array<string, mixed> $context */
    private function page(string $template, array $context, string $locale = 'en'): string
    {
        $name = '_etl_probe_' . md5($template . $locale) . '.twig';
        $this->write('app/pages/' . $name, $template);
        $app = $this->app();

        return Contexts::run(
            ContextFactory::forTest()->in($locale),
            static fn (): string => $app->container()->get(TemplateEngineInterface::class)->render($name, $context),
        );
    }
}
