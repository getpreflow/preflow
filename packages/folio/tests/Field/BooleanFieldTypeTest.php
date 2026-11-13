<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\BooleanFieldType;

/** The boolean field (board LibFields): the checkbox, and the switch for settings that act at once. */
final class BooleanFieldTypeTest extends TestCase
{
    public function test_the_checkbox_stays_the_default(): void
    {
        $html = (new BooleanFieldType())->renderEditor(new FieldContext(name: 'searchable', label: 'Searchable', value: true));
        $this->assertStringContainsString('<input type="checkbox" name="searchable" id="searchable" value="1" checked>', $html);
        $this->assertStringNotContainsString('role="switch"', $html);
    }

    public function test_display_switch_renders_a_switch(): void
    {
        $html = (new BooleanFieldType())->renderEditor(new FieldContext(name: 'home', label: 'Show on home page', help: 'Acts at once.', value: false, config: ['display' => 'switch']));
        $this->assertStringContainsString('<input type="hidden" name="home" value="0">', $html, 'unchecked still saves false');
        $this->assertStringContainsString('<div class="form-group folio-boolean folio-boolean-switch">', $html);
        $this->assertStringContainsString('<input type="checkbox" role="switch" class="folio-switch" name="home" id="home" value="1">', $html);
        $this->assertStringContainsString('<span class="folio-boolean-label">Show on home page</span>', $html);
    }

    public function test_an_unknown_display_is_a_boot_error(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Boolean field option "display" must be "checkbox" or "switch".');
        BooleanFieldType::display(['display' => 'toggle']);
    }

    public function test_the_boot_check_names_the_type_and_field(): void
    {
        $dir = sys_get_temp_dir() . '/folio_bool_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => ['on' => ['type' => 'boolean', 'display' => 'radio']]]));
        try {
            (new TypeCatalog($dir))->all();
            $this->fail('accepted');
        } catch (FrontendConfigException $e) {
            $this->assertSame('Type "thing": field "on": Boolean field option "display" must be "checkbox" or "switch".', $e->getMessage());
        } finally {
            unlink($dir . '/thing.json');
            rmdir($dir);
        }
    }
}
