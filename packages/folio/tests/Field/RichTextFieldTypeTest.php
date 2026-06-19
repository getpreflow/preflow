<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\RichTextFieldType;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class RichTextFieldTypeTest extends TestCase
{
    private function type(): RichTextFieldType
    {
        $sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig())->allowSafeElements()->allowRelativeLinks()->allowRelativeMedias(),
        );
        return new RichTextFieldType($sanitizer);
    }

    public function test_key_and_assets(): void
    {
        $t = $this->type();
        $this->assertSame('richtext', $t->key());
        $this->assertSame(['trix.css', 'trix.js'], $t->assets());
    }

    public function test_render_editor_emits_trix_and_hidden_input(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'body', label: 'Body', value: '<p>hi</p>',
        ));
        $this->assertStringContainsString('<trix-editor input="folio-rt-body"', $html);
        $this->assertStringContainsString('<input type="hidden" id="folio-rt-body" name="body"', $html);
        $this->assertStringContainsString('&lt;p&gt;hi&lt;/p&gt;', $html); // value escaped into the attribute
        $this->assertStringContainsString('Body', $html);
    }

    public function test_normalize_strips_scripts_keeps_safe_markup(): void
    {
        $clean = (string) $this->type()->normalizeInput('<p><strong>ok</strong></p><script>alert(1)</script>', []);
        $this->assertStringContainsString('<strong>ok</strong>', $clean);
        $this->assertStringNotContainsString('<script', $clean);
    }

    public function test_render_frontend_is_sanitized(): void
    {
        $out = $this->type()->renderFrontend('<p>hi</p><script>alert(1)</script>', []);
        $this->assertStringContainsString('<p>hi</p>', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function test_storage_roundtrip_passthrough(): void
    {
        $t = $this->type();
        $this->assertSame('<p>x</p>', $t->toStorage('<p>x</p>'));
        $this->assertSame('<p>x</p>', $t->fromStorage('<p>x</p>'));
    }
}
