<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\View;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\View\FormOwner;

/** The side panel's record fields name the record form (form="…") they are not inside. */
final class FormOwnerTest extends TestCase
{
    public function test_every_control_gets_the_form_and_one_naming_another_keeps_it(): void
    {
        $html = '<div class="form-group"><label for="f-slug">Slug</label><input type="text" id="f-slug" name="slug" value="a&gt;b">'
            . '<select name="kind"><option>x</option></select><textarea name="note"></textarea>'
            . '<button type="button" data-copy="x">Copy</button><input type="hidden" name="other" form="elsewhere"></div>';

        $out = FormOwner::assign($html, 'folio-record');

        $this->assertStringContainsString('<input form="folio-record" type="text" id="f-slug" name="slug" value="a&gt;b">', $out);
        $this->assertStringContainsString('<select form="folio-record" name="kind">', $out);
        $this->assertStringContainsString('<textarea form="folio-record" name="note">', $out);
        $this->assertStringContainsString('<button form="folio-record" type="button" data-copy="x">', $out);
        $this->assertStringContainsString('<input type="hidden" name="other" form="elsewhere">', $out, 'its own form stays');
        $this->assertStringContainsString('<label for="f-slug">', $out, 'labels and options are no form controls');
        $this->assertStringContainsString('<option>x</option>', $out);
    }

    public function test_an_unsafe_form_id_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormOwner::assign('<input name="a">', 'x" onclick="evil');
    }
}
