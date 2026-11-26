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

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fields_app_' . bin2hex(random_bytes(4));
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Fields', 'debug' => 1, 'engine' => 'twig', 'key' => 'fields-app-test-key-0123456789abcdef'];\n");
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
