<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Fields\Rules\ValidLink;
use Preflow\Folio\Field\AcceptsTranslatedTexts;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\ReportsTranslatableTexts;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Folio\Field\Types\LinkFieldType;
use Preflow\Folio\Field\Types\RelationFieldType;
use Preflow\Folio\Tests\Support\TempRoots;

/** The link field's input (0.91): the value is preflow/fields' Link; Folio draws the editor. */
final class LinkFieldTypeTest extends TestCase
{
    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;
    private FieldTypeRegistry $fieldTypes;

    protected function setUp(): void
    {
        $this->base = TempRoots::path('folio_link_');
        mkdir($this->base . '/m', 0777, true);
        foreach (['page' => 'Pages', 'post' => 'Posts'] as $key => $label) {
            file_put_contents($this->base . "/m/{$key}.json", (string) json_encode([
                'key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid', 'label' => $label,
                'fields' => ['title' => ['type' => 'string']],
            ]));
        }
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry);
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('page'), ['uuid' => 'p1', 'title' => 'About us']));
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('post'), ['uuid' => 'n1', 'title' => 'News']));
        $this->fieldTypes = new FieldTypeRegistry();
        $this->fieldTypes->register(new RelationFieldType($this->dm, $this->registry, prefix: '/folio'));
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function type(?\Closure $isLive = null): LinkFieldType
    {
        return new LinkFieldType(
            $this->fieldTypes,
            static fn (): array => ['page', 'post'],
            $isLive ?? fn (string $type, string $id): bool => $this->dm->findType($type, $id) !== null,
            static fn (string $type): string => ['page' => 'Page', 'post' => 'Post'][$type] ?? $type,
        );
    }

    public function test_key_and_capabilities(): void
    {
        $type = $this->type();
        $this->assertSame('link', $type->key());
        $this->assertInstanceOf(StructuredFieldType::class, $type);
        $this->assertInstanceOf(DisplaysInAdmin::class, $type);
        $this->assertInstanceOf(ReportsTranslatableTexts::class, $type);
        $this->assertInstanceOf(AcceptsTranslatedTexts::class, $type);
        $this->assertSame(['show-when-core.js', 'fields.js'], $type->assets());
        $rules = $type->rules(['link' => ['kinds' => ['url']]]);
        $this->assertCount(1, $rules);
        $this->assertInstanceOf(ValidLink::class, $rules[0], 'the core\'s check, the same a save without Folio runs');
    }

    public function test_the_editor_picks_a_record_with_the_relation_picker_per_offered_type(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'cta', label: 'Button', locale: 'en',
            value: ['kind' => 'record', 'target' => ['type' => 'post', 'id' => 'n1'], 'label' => ['en' => 'Read'], 'new_tab' => false],
        ));

        $this->assertStringContainsString('<fieldset class="form-group folio-link" data-folio-link>', $html);
        $this->assertStringContainsString('<legend>Button</legend>', $html);
        $this->assertMatchesRegularExpression('~<select id="folio-link-cta-kind" name="cta\[kind\]"><option value="">No link</option><option value="record" selected>A page or record</option><option value="url">Web address</option>~', $html);
        // One type select, one picker per type, each shown for its type (the conditional-field markup).
        $this->assertStringContainsString('<select id="folio-link-cta-type" name="cta[type]"><option value="page">Page</option><option value="post" selected>Post</option></select>', $html);
        $this->assertStringContainsString('data-folio-relation-name="cta[record][page]"', $html);
        $this->assertStringContainsString('<input type="hidden" name="cta[record][post]" value="n1">', $html);
        $this->assertStringContainsString('<span class="folio-relation-label">News</span>', $html);
        $this->assertMatchesRegularExpression('~<div class="folio-show-when folio-link-part" data-folio-show-when="[^"]*&quot;prefix&quot;:&quot;cta&quot;[^"]*">~', $html, 'the parts read "cta[kind]" and "cta[type]"');
        $this->assertMatchesRegularExpression('~data-folio-show-when="[^"]*&quot;=&quot;:&quot;page&quot;[^"]*" hidden>~', $html, 'the page picker is hidden while a post is linked');
        $this->assertMatchesRegularExpression('~data-folio-show-when="[^"]*&quot;=&quot;:&quot;url&quot;[^"]*" hidden><div class="form-group"><label for="folio-link-cta-url">Web address</label><input type="text" id="folio-link-cta-url" name="cta\[url\]" value=""~', $html);
        $this->assertStringContainsString('name="cta[label]" value="Read"', $html);
        $this->assertStringContainsString('<input type="checkbox" name="cta[new_tab]" value="1"> Open in a new tab', $html);
        $this->assertStringNotContainsString('folio-link-broken', $html);
    }

    public function test_a_large_type_is_searched_through_the_relation_endpoint(): void
    {
        for ($i = 0; $i <= RelationFieldType::INLINE_LIMIT; $i++) {
            $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('page'), ['uuid' => 'x' . $i, 'title' => 'Page ' . $i]));
        }
        $html = $this->type()->renderEditor(new FieldContext(name: 'cta', value: null, config: ['link' => ['types' => ['page']]]));

        $this->assertStringContainsString('data-folio-relation-url="/folio/_relation/page"', $html);
        $this->assertStringContainsString('<input type="hidden" name="cta[type]" value="page">', $html, 'one type: no type select');
        $this->assertStringNotContainsString('name="cta[type]"><option', $html);
    }

    public function test_the_kinds_offered_follow_the_config(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(name: 'cta', value: null, config: ['link' => ['kinds' => ['url', 'email']]]));

        $this->assertStringContainsString('<option value="url">Web address</option><option value="email">Email address</option></select>', $html);
        $this->assertStringNotContainsString('cta[record]', $html);
        $this->assertStringNotContainsString('name="cta[phone]"', $html);
        $this->assertStringContainsString('<input type="email" id="folio-link-cta-email" name="cta[email]"', $html);
    }

    public function test_a_link_to_a_missing_or_unpublished_record_is_marked(): void
    {
        $value = ['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'gone'], 'label' => null, 'new_tab' => false];

        $html = $this->type()->renderEditor(new FieldContext(name: 'cta', value: $value));
        $this->assertStringContainsString('<p class="folio-link-broken" role="status">Linked page no longer available. Choose another one or remove the link.</p>', $html);

        $unpublished = $this->type(static fn (string $type, string $id): bool => false);
        $this->assertStringContainsString('folio-link-broken', $unpublished->renderEditor(new FieldContext(name: 'cta', value: ['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'p1']])));
        $this->assertSame('Page (Linked page no longer available.)', $unpublished->renderAdmin(['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'p1']], []));
        $this->assertStringNotContainsString('folio-link-broken', $this->type()->renderEditor(new FieldContext(name: 'cta', value: ['kind' => 'url', 'target' => 'https://example.org'])));
    }

    public function test_build_takes_the_chosen_kinds_target_and_merges_the_label_per_locale(): void
    {
        $locales = new Locales(['en', 'de'], 'en');
        $posted = ['kind' => 'record', 'type' => 'post', 'record' => ['page' => 'p1', 'post' => 'n1'], 'url' => 'https://ignored.example', 'label' => 'Lesen', 'new_tab' => '1'];
        $existing = (string) json_encode(['kind' => 'url', 'target' => 'https://example.org', 'label' => ['en' => 'Read'], 'new_tab' => false]);

        $this->assertSame(
            ['kind' => 'record', 'target' => ['type' => 'post', 'id' => 'n1'], 'label' => ['en' => 'Read', 'de' => 'Lesen'], 'new_tab' => true],
            $this->type()->buildValue($posted, $existing, [], new BuildContext($locales, 'de', false)),
        );
        $this->assertSame(
            ['kind' => 'phone', 'target' => '+41 44 123 45 67', 'label' => null, 'new_tab' => false],
            $this->type()->buildValue(['kind' => 'phone', 'phone' => ' +41 44 123 45 67 ', 'label' => ''], null, [], new BuildContext(Locales::single('en'), 'en', false)),
        );
        $this->assertSame(['kind' => 'url', 'target' => 'https://example.org', 'label' => 'Example', 'new_tab' => false], $this->type()->buildValue(['kind' => 'url', 'url' => 'https://example.org', 'label' => 'Example'], null, [], new BuildContext(Locales::single('en'), 'en', false)), 'one language: a plain label');
        $this->assertNull($this->type()->buildValue(['kind' => '', 'url' => 'https://example.org'], $existing, [], new BuildContext($locales, 'en', false)), '"No link"');
        $this->assertSame(['kind' => 'url', 'target' => '', 'label' => null, 'new_tab' => false], $this->type()->buildValue(['kind' => 'url'], null, [], new BuildContext(Locales::single('en'), 'en', false)), 'kept, so the check names what is missing');
    }

    public function test_a_new_link_in_another_language_starts_with_the_same_text_in_the_default(): void
    {
        $built = $this->type()->buildValue(['kind' => 'url', 'url' => '/a', 'label' => 'Lesen'], null, [], new BuildContext(new Locales(['en', 'de'], 'en'), 'de', false));

        $this->assertSame(['de' => 'Lesen', 'en' => 'Lesen'], $built['label']);
    }

    public function test_the_label_is_a_translatable_text(): void
    {
        $locales = new Locales(['en', 'de'], 'en');
        $value = ['kind' => 'url', 'target' => '/a', 'label' => ['en' => 'Read'], 'new_tab' => false];

        $texts = $this->type()->translatableTexts($value, [], $locales);
        $this->assertCount(1, $texts);
        $this->assertSame('label', $texts[0]->path);
        $this->assertSame(['en' => 'Read', 'de' => null], $texts[0]->values);
        $this->assertSame([], $this->type()->translatableTexts($value, [], Locales::single('en')));

        $this->assertSame(['en' => 'Read', 'de' => 'Lesen'], $this->type()->withTranslatedTexts($value, ['label' => 'Lesen'], 'de', [], $locales)['label']);
    }

    public function test_frontend_and_admin_output(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest()->in('en'), function (): void {
            $this->frontendAndAdminOutput();
        });
    }

    private function frontendAndAdminOutput(): void
    {
        $type = $this->type();
        $this->assertSame('<a href="https://example.org/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">Example</a>', $type->renderFrontend(['kind' => 'url', 'target' => 'https://example.org/?a=1&b=2', 'label' => 'Example', 'new_tab' => true], []));
        $this->assertSame('<span class="folio-link-text">About</span>', $type->renderFrontend(['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'p1'], 'label' => 'About'], []), 'no RecordAddresses: no href');
        $this->assertSame('', $type->renderFrontend(null, []));
        $this->assertSame('Example → https://example.org', $type->renderAdmin('{"kind":"url","target":"https://example.org","label":"Example"}', []));
        $this->assertSame(['kind' => 'url', 'target' => '/a'], $type->fromStorage('{"kind":"url","target":"/a"}'));
        $this->assertNull($type->toStorage(null));
    }

    public function test_config_mistakes_are_boot_errors_naming_the_type_and_field(): void
    {
        foreach ([
            [['type' => 'link', 'translatable' => true], 'Type "thing": field "cta": A link field cannot be translatable; its label is translated in the link itself (a text per language). Remove "translatable".'],
            [['type' => 'link', 'link' => ['kinds' => ['ftp']]], 'Type "thing": field "cta": "link.kinds" lists "ftp"; use record, url, email, phone or asset.'],
        ] as [$field, $message]) {
            $dir = TempRoots::path('folio_link_boot_');
            mkdir($dir);
            file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => ['cta' => $field]]));
            try {
                (new \Preflow\Folio\Content\TypeCatalog($dir))->all();
                $this->fail('accepted: ' . json_encode($field));
            } catch (\Preflow\Folio\Content\FrontendConfigException $e) {
                $this->assertSame($message, $e->getMessage());
            } finally {
                unlink($dir . '/thing.json');
                rmdir($dir);
            }
        }
    }
}
