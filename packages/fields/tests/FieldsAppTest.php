<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\SystemClock;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Fields\RecordAddresses;
use Preflow\Fields\Tests\Support\FakeAddresses;
use Preflow\Validation\ValidationException;
use Preflow\View\TemplateEngineInterface;

/**
 * preflow/fields in an app without Folio: FieldsServiceProvider registers the link field's column
 * and check with the data package and its template functions with the app's engine; a record link
 * resolves through the RecordAddresses the site binds, in the render's locale.
 */
final class FieldsAppTest extends TestCase
{
    private string $dir;

    private ?Application $app = null;

    private int $renders = 0;

    private string $previousZone = 'UTC';

    protected function setUp(): void
    {
        $this->previousZone = date_default_timezone_get(); // booting the app sets app.timezone as the default zone
        $this->dir = sys_get_temp_dir() . '/fields_app_' . bin2hex(random_bytes(4));
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Fields', 'debug' => 1, 'engine' => 'twig', 'timezone' => 'Europe/Zurich', 'key' => 'fields-app-test-key-0123456789abcdef'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Fields\\FieldsServiceProvider::class];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/models/teaser.json', (string) json_encode([
            'key' => 'teaser', 'table' => 'teaser', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'cta' => ['type' => 'link', 'link' => ['kinds' => ['record', 'url'], 'types' => ['page']]]],
        ]));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousZone);
        Contexts::reset();
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_the_link_column_and_check_are_registered_with_the_data_package(): void
    {
        $dm = $this->app()->container()->get(DataManager::class);
        $def = $dm->getTypeRegistry()->get('teaser');

        $this->assertSame(ColumnKind::Text, $dm->fieldKinds()->columnKindOf($def->fields['cta']));
        try {
            $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 't1', 'cta' => ['kind' => 'email', 'target' => 'team@example.org']]));
            $this->fail('saved a kind the field does not offer');
        } catch (ValidationException $e) {
            $this->assertSame(['cta' => ['Choose what the link points to: record, url.']], $e->errors());
        }
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 't2', 'cta' => ['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'p1']]]));
        $this->assertNotNull($dm->findType('teaser', 't2'));
    }

    public function test_template_functions_give_href_attributes_and_label_in_the_render_locale(): void
    {
        $this->app()->container()->instance(RecordAddresses::class, new FakeAddresses(['page/p1/en' => '/about', 'page/p1/de' => '/de/ueber-uns']));
        $cta = ['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'p1'], 'label' => ['en' => 'About us', 'de' => 'Über uns'], 'new_tab' => true];
        $template = '<a{{ link_attrs(cta) }}>{{ link_label(cta) }}</a>|{{ link_href(cta) }}|{{ link_href(cta, "en") }}';

        $this->assertSame('<a href="/de/ueber-uns" target="_blank" rel="noopener noreferrer">Über uns</a>|/de/ueber-uns|/about', $this->render($template, ['cta' => $cta], 'de'));
        $this->assertSame('<a href="/about" target="_blank" rel="noopener noreferrer">About us</a>|/about|/about', $this->render($template, ['cta' => json_encode($cta)], 'en'), 'JSON text as SQL storage gives it');
    }

    public function test_without_record_addresses_a_record_link_renders_no_href(): void
    {
        $cta = ['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'p1'], 'label' => 'About us', 'new_tab' => false];

        $this->assertSame('<a>About us</a>||', $this->render('<a{{ link_attrs(cta) }}>{{ link_label(cta) }}</a>|{{ link_href(cta) }}|{{ link_href(none) }}', ['cta' => $cta, 'none' => null]));
    }

    public function test_attributes_are_escaped(): void
    {
        $cta = ['kind' => 'url', 'target' => 'https://example.org/?a=1&b="2"', 'label' => '<b>Hi</b>'];

        $this->assertSame('<a href="https://example.org/?a=1&amp;b=&quot;2&quot;">&lt;b&gt;Hi&lt;/b&gt;</a>', $this->render('<a{{ link_attrs(cta) }}>{{ link_label(cta) }}</a>', ['cta' => $cta]));
    }

    public function test_template_functions_for_email_phone_code_and_json(): void
    {
        $values = [
            'email' => 'a?bcc=x@example.org',
            'phone' => ['as_typed' => '044 123 45 67', 'e164' => '+41441234567'],
            'code' => '<script>alert("x")</script>',
            'settings' => '{"limit": 3, "tags": ["a"]}',
            'none' => null,
        ];
        $template = '{{ mailto_href(email) }}|{{ tel_href(phone) }}|{{ tel_href("+41 44 123 45 67") }}|{{ code_html(code, "html") }}|{{ json_data(settings).limit }}|{{ json_data(settings).tags[0] }}'
            . '|[{{ mailto_href(none) }}][{{ tel_href(none) }}][{{ code_html(none) }}][{{ json_data(none) }}]';

        $this->assertSame(
            'mailto:a%3Fbcc%3Dx@example.org|tel:+41441234567|tel:+41441234567|<pre><code class="language-html">&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;</code></pre>|3|a|[][][][]',
            $this->render($template, $values),
        );
        $this->assertSame('<pre><code class="language-html">&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;</code></pre>', $this->render('{{ code_html(code, "html") }}', $values), 'safe markup, escaped inside');
        $this->assertSame('<pre><code>&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;</code></pre>', $this->render('{{ code_html(code) }}', $values), 'no language: no class');
        $this->assertSame('tel:+41441234567', $this->render('{{ tel_href(phone) }}', ['phone' => json_encode($values['phone'])]), 'JSON text as SQL storage gives it');
    }

    public function test_template_functions_for_datetime_time_and_range_in_the_site_zone(): void
    {
        $values = [
            'at' => '2026-07-15T10:30:00Z',
            'winter' => '2026-01-15T10:30:00Z',
            'opens' => '09:05',
            'stay' => ['start' => '2026-03-01', 'end' => '2026-03-07'],
            'slot' => json_encode(['start' => '2026-07-15T07:00:00Z', 'end' => '2026-07-15T15:30:00Z']),
            'none' => null,
        ];
        $template = '{{ datetime_local(at) }}|{{ datetime_local(winter) }}|{{ datetime_local(at, "j.n.Y, G:i") }}|{{ datetime_local(at, "H:i", "Asia/Tokyo") }}'
            . '|{{ time_format(opens) }}|{{ time_format(opens, "g:i a") }}'
            . '|{{ daterange_start(stay) }}|{{ daterange_end(stay, "j M") }}|{{ daterange_start(slot) }}|{{ daterange_end(slot, "H:i") }}|{{ daterange_end(slot, "H:i", "UTC") }}'
            . '|[{{ datetime_local(none) }}][{{ time_format(none) }}][{{ daterange_start(none) }}][{{ daterange_end("junk") }}]';

        $this->assertSame(
            '2026-07-15 12:30|2026-01-15 11:30|15.7.2026, 12:30|19:30|09:05|9:05 am|2026-03-01|7 Mar|2026-07-15 09:00|17:30|15:30|[][][][]',
            $this->render($template, $values),
        );
    }

    public function test_the_embed_player_brings_its_script_and_style_with_the_nonce_and_its_poster_from_the_bound_addresses(): void
    {
        $this->app()->container()->instance(RecordAddresses::class, new FakeAddresses([], ['p1' => '/media/poster.jpg']));
        $video = ['provider' => 'youtube', 'id' => 'aqz-KE-bpKQ', 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'poster' => 'p1'];

        $html = $this->render('{{ embed_player(video, "Our film") }}{{ head() }}{{ assets() }}', ['video' => json_encode($video)], 'de');

        $this->assertStringContainsString('<div class="preflow-embed" data-preflow-embed data-provider="youtube">', $html);
        $this->assertStringContainsString('<img class="preflow-embed-poster" src="/media/poster.jpg" alt="" loading="lazy">', $html);
        $this->assertStringContainsString('aria-label="Video abspielen: Our film"', $html, 'in the render\'s language');
        $this->assertMatchesRegularExpression('~<script nonce="[^"]+">[^<]*data-preflow-embed-play~', $html, 'the player script, inline with the nonce');
        $this->assertMatchesRegularExpression('~<style nonce="[^"]+">[^<]*\.preflow-embed~', $html);
        $this->assertSame('', $this->render('{{ embed_player(none) }}{{ assets() }}', ['none' => null]), 'no video: no markup, no script');
    }

    public function test_a_site_replaces_the_player_markup_and_adds_a_provider_in_its_container(): void
    {
        $container = $this->app()->container();
        $container->get(\Preflow\Fields\EmbedProviders::class)->add(new Support\ExampleVideoProvider());
        $container->instance(\Preflow\Fields\EmbedPlayer::class, new class implements \Preflow\Fields\EmbedPlayer {
            public function html(\Preflow\Fields\EmbedView $view): string
            {
                return '<figure data-preflow-embed>' . htmlspecialchars($view->providerLabel . ' ' . $view->playerUrl . ' ' . $view->title) . '</figure>';
            }
        });
        $clip = ['provider' => 'example', 'id' => 'kT2pQx', 'url' => 'https://video.example.org/w/kT2pQx', 'poster' => null];

        $this->assertSame('<figure data-preflow-embed>Example Video https://video.example.org/videos/embed/kT2pQx Video (Example Video)</figure>', $this->render('{{ embed_player(clip) }}', ['clip' => $clip]));
        $this->assertSame('example|kT2pQx|https://video.example.org/videos/embed/kT2pQx|Example Video|', $this->render('{% set d = embed_data(clip) %}{{ d.provider }}|{{ d.id }}|{{ d.player }}|{{ d.label }}|{{ d.poster_url }}', ['clip' => $clip]));
    }

    public function test_template_functions_for_table_and_location(): void
    {
        $prices = ['head' => [['en' => 'Size', 'de' => 'Grösse'], 'CHF'], 'rows' => [[['en' => 'Small', 'de' => 'Klein'], '10']]];
        $place = ['lat' => 47.3769, 'lng' => 8.5417, 'address' => 'Bahnhofplatz, Zürich'];

        $this->assertSame(
            '<table class="preflow-table"><thead><tr><th scope="col">Grösse</th><th scope="col">CHF</th></tr></thead><tbody><tr><td>Klein</td><td>10</td></tr></tbody></table>|Klein|Size',
            $this->render('{{ table_html(prices) }}|{{ table_data(prices).rows[0][0] }}|{{ table_data(prices, "en").head[0] }}', ['prices' => json_encode($prices)], 'de'),
        );
        $this->assertSame('47.3769|8.5417|Bahnhofplatz, Zürich|geo:47.3769,8.5417|[][][]', $this->render(
            '{% set p = location_data(place) %}{{ p.lat }}|{{ p.lng }}|{{ p.address }}|{{ geo_href(place) }}|[{{ table_html(none) }}][{{ location_data(none) }}][{{ geo_href(none) }}]',
            ['place' => $place, 'none' => null],
        ));
    }

    public function test_a_zone_name_that_is_no_zone_in_a_template_is_an_error_naming_it(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/Unknown time zone "Nowhere\/Land"; use a name such as "Europe\/Zurich"/');

        $this->render('{{ datetime_local(at, "H:i", "Nowhere/Land") }}', ['at' => '2026-07-15T10:30:00Z']);
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    private function app(): Application
    {
        if ($this->app === null) {
            $this->app = Application::create($this->dir);
            $this->app->boot();
        }

        return $this->app;
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context, string $locale = 'en'): string
    {
        $name = 'page' . ++$this->renders . '.twig'; // the engine caches a template by its name
        $this->write($name, $template);
        $app = $this->app();

        return Contexts::run(
            RenderContext::fromRequest(new ServerRequest('GET', '/'), $app->site(), new SystemClock())->in($locale),
            static fn (): string => $app->container()->get(TemplateEngineInterface::class)->render($name, $context),
        );
    }
}
