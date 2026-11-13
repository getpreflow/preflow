<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\AddressAffix;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\StringFieldType;

/** The string field (board LibFields): the address affix with copy, and the open button of a URL. */
final class StringFieldTypeTest extends TestCase
{
    public function test_a_plain_string_has_no_affix(): void
    {
        $html = (new StringFieldType())->renderEditor(new FieldContext(name: 'title', value: 'Hello'));
        $this->assertStringNotContainsString('folio-input-affix', $html);
        $this->assertStringContainsString('<input type="text" name="title" value="Hello" id="title">', $html);
    }

    public function test_an_address_field_gets_the_mono_prefix_and_a_copy_button(): void
    {
        $html = (new StringFieldType())->renderEditor(new FieldContext(
            name: 'slug',
            label: 'Slug',
            value: 'folio',
            address: new AddressAffix('segment', '/jobs/', 'https://example.com/jobs/', '', 'home', 'https://example.com/'),
        ));

        $this->assertMatchesRegularExpression('~<div class="folio-input-affix"[^>]*><span class="folio-input-prefix" aria-hidden="true">/jobs/</span><input type="text" name="slug" value="folio" id="slug">~', $html);
        $this->assertStringContainsString('data-folio-copy-address', $html);
        $this->assertStringContainsString('data-address-before="https://example.com/jobs/"', $html);
        $this->assertStringContainsString('data-address-after=""', $html);
        $this->assertStringContainsString('data-address-home="home" data-address-root="https://example.com/"', $html);
        $this->assertStringContainsString('aria-label="Copy the address"', $html);
        $this->assertStringContainsString('data-text-copied="Copied"', $html);
        $this->assertStringContainsString('<svg class="folio-icon"', $html, 'the copy icon, inline');
        $this->assertStringContainsString('type="button"', $html, 'the copy button submits nothing');
    }

    public function test_an_address_that_needs_other_values_has_no_copy_button(): void
    {
        $html = (new StringFieldType())->renderEditor(new FieldContext(name: 'slug', value: 'x', address: new AddressAffix('segment', '/')));
        $this->assertStringContainsString('<span class="folio-input-prefix" aria-hidden="true">/</span>', $html);
        $this->assertStringNotContainsString('data-folio-copy-address', $html);
    }

    public function test_a_url_field_gets_an_open_button(): void
    {
        foreach ([['format' => 'url'], []] as $i => $config) {
            $html = (new StringFieldType())->renderEditor(new FieldContext(
                name: 'website',
                value: 'https://example.com/a?b=1&c=2',
                config: $config,
                validate: $i === 1 ? ['nullable', 'url'] : [],
            ));
            $this->assertStringContainsString('folio-input-affix', $html);
            $this->assertStringContainsString('<a class="folio-input-action" href="https://example.com/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer" data-folio-open-url aria-label="Open the address in a new tab"', $html);
        }
    }

    public function test_the_open_button_links_only_web_addresses(): void
    {
        foreach (['javascript:alert(1)', '', 'not a url', 'data:text/html,x'] as $value) {
            $html = (new StringFieldType())->renderEditor(new FieldContext(name: 'website', value: $value, config: ['format' => 'url']));
            $this->assertStringContainsString('data-folio-open-url', $html, 'script shows it once the address is one');
            $this->assertStringNotContainsString('href=', $html);
            $this->assertMatchesRegularExpression('~<a class="folio-input-action" target="_blank" rel="noopener noreferrer" data-folio-open-url[^>]* hidden>~', $html, var_export($value, true));
        }
    }
}
