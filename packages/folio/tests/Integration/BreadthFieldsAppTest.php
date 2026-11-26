<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Data\DynamicRecord;
use Preflow\Fields\Values\Phone;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\ChecksFrameBalance;
use Preflow\Testing\ContextFactory;
use Preflow\View\TemplateEngineInterface;

/**
 * The email, phone, colour, code and JSON fields in Folio (0.91): the editors, what a save stores
 * (the shapes of preflow/fields), the core checks and their exact messages on the field (also in a
 * block), the list cells, search, a conditional field of each type, the page's template functions
 * and transfer.
 */
final class BreadthFieldsAppTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    private const string UID = 'dddddddddddddddd';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\ContactCardBlock::class]];\n");
        $this->writeModel('contact', ['label' => 'Contacts', 'publishing' => false, 'list' => ['columns' => ['name', 'email', 'phone', 'tint', 'snippet', 'settings']], 'fields' => [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'email', 'label' => 'Email'],
            'phone' => ['type' => 'phone', 'label' => 'Phone', 'default_region' => 'CH'],
            'mobile' => ['type' => 'phone', 'label' => 'Mobile'],
            'tint' => ['type' => 'color', 'label' => 'Tint', 'palette' => ['Brand red' => '#c00000', 'Sky' => '#0a5ca8']],
            'accent' => ['type' => 'color', 'label' => 'Accent', 'palette' => ['Brand red' => '#c00000', 'Sky' => '#0a5ca8'], 'palette_only' => true],
            'snippet' => ['type' => 'code', 'label' => 'Snippet', 'language' => 'php'],
            'settings' => ['type' => 'json', 'label' => 'Settings'],
            'kind' => ['type' => 'select', 'options' => ['mail' => 'Mail', 'none' => 'None']],
            'extra_email' => ['type' => 'email', 'show_when' => ['field' => 'kind', '=' => 'mail']],
            'extra_phone' => ['type' => 'phone', 'show_when' => ['field' => 'kind', '=' => 'mail']],
            'extra_color' => ['type' => 'color', 'show_when' => ['field' => 'kind', '=' => 'mail']],
            'extra_code' => ['type' => 'code', 'show_when' => ['field' => 'kind', '=' => 'mail']],
            'extra_json' => ['type' => 'json', 'show_when' => ['field' => 'kind', '=' => 'mail']],
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
            'uuid' => 'c1', 'name' => 'Ada', 'email' => ' ada@example.org ', 'phone' => '044 123 45 67', 'mobile' => '+49 170 1234567',
            'tint' => '#C00', 'accent' => '#0a5ca8', 'snippet' => "<?php\r\necho 1;", 'settings' => "{\"b\": 1,\n \"a\": [1]}\n",
            'kind' => 'none',
        ];
    }

    #[RunInSeparateProcess]
    public function test_the_editors_name_their_inputs(): void
    {
        $html = (string) $this->get('/folio/contact/new')->getBody();

        $this->assertStringContainsString('<input type="email" name="email"', $html);
        $this->assertStringContainsString('<input type="tel" name="phone"', $html);
        $this->assertStringContainsString('<input type="text" id="folio-color-tint" name="tint"', $html);
        $this->assertStringContainsString('data-folio-swatch="#0a5ca8"', $html);
        $this->assertStringContainsString('<input type="radio" name="accent" value="#c00000">', $html);
        $this->assertStringContainsString('<textarea name="snippet"', $html);
        $this->assertStringContainsString('<span class="folio-code-language">php</span>', $html, 'the field\'s language beside the label');
        $this->assertStringContainsString('<textarea name="settings"', $html);
        $this->assertStringContainsString('fields.js', $html, 'the colour controls');
    }

    #[RunInSeparateProcess]
    public function test_a_save_stores_each_types_shape(): void
    {
        $res = $this->post('/folio/contact', $this->valid());
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());

        $stored = $this->first();
        $this->assertSame('ada@example.org', $stored->get('email'));
        $this->assertSame(['as_typed' => '044 123 45 67', 'e164' => '+41441234567'], Phone::fromStored($stored->get('phone'))?->toStored());
        $this->assertSame(['as_typed' => '+49 170 1234567', 'e164' => '+491701234567'], Phone::fromStored($stored->get('mobile'))?->toStored());
        $this->assertSame('#cc0000', $stored->get('tint'), 'read leniently, stored as #rrggbb');
        $this->assertSame('#0a5ca8', $stored->get('accent'));
        $this->assertSame("<?php\necho 1;", $stored->get('snippet'), 'code is the text, line breaks as \\n');
        $this->assertSame("{\"b\": 1,\n \"a\": [1]}", $stored->get('settings'), 'JSON as written, trimmed');

        $html = (string) $this->get('/folio/contact/' . $stored->getId() . '/edit')->getBody();
        $this->assertStringContainsString('value="044 123 45 67"', $html);
        $this->assertStringContainsString('Dialled as +41441234567.', $html);
        $this->assertStringContainsString('value="#cc0000"', $html);
        $this->assertStringContainsString('<input type="radio" name="accent" value="#0a5ca8" checked>', $html);
    }

    #[RunInSeparateProcess]
    public function test_a_save_refuses_each_type_with_the_cores_message_on_its_field(): void
    {
        foreach ([
            ['email', 'not an address', 'Must be a valid email address.'],
            ['mobile', '044 123 45 67', 'Cannot tell which country this number belongs to: start it with + and the country code, e.g. +41 44 123 45 67.'],
            ['phone', 'call me', 'Must be a phone number: digits, optionally with a leading + and spaces, e.g. +41 44 123 45 67.'],
            ['tint', 'red', 'Must be a colour in hex form, such as #1a2b3c (or #1a2b3c80 with transparency).'],
            ['accent', '#123456', 'Choose one of the colours offered: #c00000, #0a5ca8.'],
            ['settings', '{a: 1}', 'Must be valid JSON, such as {"name": "value"} or [1, 2, 3].'],
        ] as [$field, $value, $message]) {
            $res = $this->post('/folio/contact', ['name' => 'x', $field => $value] + $this->valid());
            $this->assertSame(422, $res->getStatusCode(), $field);
            $this->assertStringContainsString('<div class="form-error">' . htmlspecialchars($message, ENT_QUOTES | ENT_HTML5) . '</div>', (string) $res->getBody(), $field);
            $this->assertSame([], $this->dm()->queryType('contact')->get()->items(), $field . ': nothing saved');
        }
    }

    #[RunInSeparateProcess]
    public function test_each_type_works_in_a_block(): void
    {
        $block = ['_block' => 'contact_card', 'email' => 'ada@example.org', 'phone' => '044 123 45 67', 'accent' => '#c00000', 'snippet' => 'echo 1;', 'settings' => '[1, 2]'];
        $post = static fn (array $over): array => ['name' => 'Ada', 'sections' => ['_order' => [self::UID], self::UID => $over + $block]];

        $html = (string) $this->get('/folio/contact/new')->getBody();
        $this->assertStringNotContainsString('sections[' . self::UID, $html);
        $this->dm()->saveType(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('contact'), ['uuid' => 'c2', 'name' => 'Blocks', 'sections' => [['_uid' => self::UID, '_block' => 'contact_card']]]), validate: false);
        $html = (string) $this->get('/folio/contact/c2/edit')->getBody();
        foreach (['<input type="email" name="sections[' . self::UID . '][email]"', '<input type="tel" name="sections[' . self::UID . '][phone]"', '<input type="radio" name="sections[' . self::UID . '][accent]" value="#c00000">', '<textarea name="sections[' . self::UID . '][snippet]"', '<textarea name="sections[' . self::UID . '][settings]"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        foreach ([
            ['email', 'nope', 'Must be a valid email address.'],
            ['phone', 'call me', 'Must be a phone number'],
            ['accent', '#123456', 'Choose one of the colours offered: #c00000, #0a5ca8.'],
            ['settings', '{oops', 'Must be valid JSON'],
        ] as [$field, $value, $message]) {
            $res = $this->post('/folio/contact/c2', $post([$field => $value]));
            $this->assertSame(422, $res->getStatusCode(), 'block ' . $field);
            $this->assertStringContainsString(htmlspecialchars($message, ENT_QUOTES | ENT_HTML5), (string) $res->getBody(), 'block ' . $field);
        }

        $res = $this->post('/folio/contact/c2', $post([]));
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $saved = $this->stored('contact', 'c2')->get('sections')[0];
        $this->assertSame('ada@example.org', $saved['email']);
        $this->assertSame(['as_typed' => '044 123 45 67', 'e164' => '+41441234567'], Phone::fromStored($saved['phone'])?->toStored());
        $this->assertSame('#c00000', $saved['accent']);
        $this->assertSame('echo 1;', $saved['snippet']);
        $this->assertSame('[1, 2]', $saved['settings']);
    }

    #[RunInSeparateProcess]
    public function test_a_hidden_field_of_each_type_keeps_its_value_and_reads_empty(): void
    {
        $kept = ['extra_email' => 'old@example.org', 'extra_phone' => ['as_typed' => '+41 44 1', 'e164' => '+41441'], 'extra_color' => '#123456', 'extra_code' => 'x', 'extra_json' => '{"a": 1}'];
        $this->dm()->saveType(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('contact'), ['uuid' => 'c3', 'name' => 'Cond', 'kind' => 'mail'] + $kept), validate: false);

        // Switched so they hide, posted with values that would not pass: saved, the values kept.
        $res = $this->post('/folio/contact/c3', ['name' => 'Cond', 'kind' => 'none', 'extra_email' => 'bad', 'extra_phone' => 'call me', 'extra_color' => 'red', 'extra_code' => 'y', 'extra_json' => '{oops']);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $stored = $this->stored('contact', 'c3');
        foreach ($kept as $field => $value) {
            $this->assertEquals($value, is_string($stored->get($field)) && str_starts_with($stored->get($field), '{') && is_array($value) ? json_decode($stored->get($field), true) : $stored->get($field), $field);
        }
        $this->assertSame('#123456', $stored->get('extra_color'));

        // Shown (kind = mail): the same values are checked.
        $res = $this->post('/folio/contact/c3', ['name' => 'Cond', 'kind' => 'mail', 'extra_email' => 'bad']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Must be a valid email address.', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_list_cells(): void
    {
        $this->assertSame(302, $this->post('/folio/contact', $this->valid())->getStatusCode());

        $html = (string) $this->get('/folio/contact')->getBody();
        $this->assertStringContainsString('<td>ada@example.org</td>', $html);
        $this->assertStringContainsString('<td>044 123 45 67</td>', $html, 'the number as typed');
        $this->assertStringContainsString('<td>#cc0000</td>', $html);
        $this->assertStringContainsString('<td>&lt;?php echo 1;</td>', $html, 'code on one line');
        $this->assertStringContainsString('<td>{"b": 1, "a": [1]}</td>', html_entity_decode($html));
    }

    #[RunInSeparateProcess]
    public function test_loose_search_finds_the_email_and_the_code_but_not_the_phone_or_json(): void
    {
        $this->post('/folio/contact', $this->valid() + []);
        $dm = $this->dm();
        $ids = static fn (string $term): array => array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), $dm->queryType('contact')->searchLoose($term)->get()->items());

        $id = $this->first()->getId();
        $this->assertSame([$id], $ids('ada@example.org'));
        $this->assertSame([$id], $ids('echo'), 'code is plain text: found');
        $this->assertSame([], $ids('+41441234567'));
        $this->assertSame([], $ids('"a"'));
    }

    #[RunInSeparateProcess]
    public function test_the_page_gets_links_and_escaped_code(): void
    {
        $this->post('/folio/contact', $this->valid());
        $c = $this->first();

        $this->assertSame(
            'mailto:ada@example.org|tel:+41441234567|<pre><code class="language-php">&lt;?php\necho 1;</code></pre>',
            str_replace("\n", '\n', $this->page('{{ mailto_href(email) }}|{{ tel_href(phone) }}|{{ code_html(snippet, "php") }}', ['email' => $c->get('email'), 'phone' => $c->get('phone'), 'snippet' => $c->get('snippet')])),
        );
    }

    #[RunInSeparateProcess]
    public function test_transfer_carries_the_stored_values_unchanged(): void
    {
        $values = ['name' => 'Ada', 'email' => 'ada@example.org', 'phone' => ['as_typed' => '044 123 45 67', 'e164' => '+41441234567'], 'tint' => '#cc0000', 'snippet' => "a\nb", 'settings' => "{\n \"a\": 1\n}"];
        $this->working('contact', 'c4', $values);
        $app = $this->app();
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $stream = fopen('php://temp', 'w+b');
        (new \Preflow\Folio\Transfer\Exporter(\Preflow\Folio\Transfer\ContentSet::fromApp($app), $dm))->export($stream);
        rewind($stream);
        $dm->deleteType('contact', 'c4');
        $importer = new \Preflow\Folio\Transfer\Importer(\Preflow\Folio\Transfer\ContentSet::fromApp($app), $dm);
        $plan = $importer->read($stream);
        try {
            $importer->apply($plan);
        } finally {
            $plan->cleanup();
        }

        $stored = $this->stored('contact', 'c4');
        $this->assertSame('ada@example.org', $stored->get('email'));
        $this->assertSame($values['phone'], Phone::fromStored($stored->get('phone'))?->toStored());
        $this->assertSame('#cc0000', $stored->get('tint'));
        $this->assertSame("a\nb", $stored->get('snippet'));
        $this->assertSame($values['settings'], $stored->get('settings'));
    }

    private function first(): DynamicRecord
    {
        return $this->dm()->queryType('contact')->get()->items()[0] ?? throw new \RuntimeException('nothing saved');
    }

    /** @param array<string, mixed> $context */
    private function page(string $template, array $context): string
    {
        // A file per template: the engine keeps a template it compiled once.
        $name = '_breadth_probe_' . md5($template) . '.twig';
        $this->write('app/pages/' . $name, $template);
        $app = $this->app();

        return Contexts::run(
            ContextFactory::forTest()->in('en'),
            static fn (): string => $app->container()->get(TemplateEngineInterface::class)->render($name, $context),
        );
    }
}
