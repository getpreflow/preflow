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
        $html = '<div class="form-group"><label for="f-slug">Slug</label><input type="text" id="f-slug" name="slug" value="a &amp; &quot;b&quot;">'
            . '<select name="kind"><option>x</option></select><textarea name="note">&lt;b&gt;</textarea>'
            . '<button name="go">Go</button><input type="hidden" name="other" form="elsewhere"></div>';

        $out = FormOwner::assign($html, 'folio-record');

        // HTML serialisation: & and " stay escaped in attributes, < and > in text.
        $this->assertStringContainsString('<input type="text" id="f-slug" name="slug" value="a &amp; &quot;b&quot;" form="folio-record">', $out);
        $this->assertStringContainsString('<select name="kind" form="folio-record">', $out);
        $this->assertStringContainsString('<textarea name="note" form="folio-record">&lt;b&gt;</textarea>', $out);
        $this->assertStringContainsString('<button name="go" form="folio-record">', $out, 'a typeless button submits: now the record form');
        $this->assertStringContainsString('<input type="hidden" name="other" form="elsewhere">', $out, 'its own form stays');
        $this->assertStringContainsString('<label for="f-slug">', $out, 'labels and options are no form controls');
        $this->assertStringContainsString('<option>x</option>', $out);
    }

    public function test_only_real_attributes_count(): void
    {
        // A stored value or a placeholder reading "form=…" is text, not the attribute.
        $out = FormOwner::assign('<input type="text" name="note" value="set form=contact"><input name="b" placeholder="the form = here">', 'folio-record');
        $this->assertStringContainsString('<input type="text" name="note" value="set form=contact" form="folio-record">', $out);
        $this->assertStringContainsString('<input name="b" placeholder="the form = here" form="folio-record">', $out);

        // A ">" inside a quoted value does not end the tag: the existing form attribute is seen.
        $out = FormOwner::assign('<input data-x="a>b" name="c" form="other">', 'folio-record');
        $this->assertSame(1, substr_count($out, 'form='));
        $this->assertStringContainsString('form="other"', $out);
    }

    public function test_a_type_button_is_left_alone(): void
    {
        $out = FormOwner::assign('<button type="button" data-copy="x">Copy</button><button type="BUTTON">B</button>', 'folio-record');
        $this->assertStringNotContainsString('form=', $out);
    }

    public function test_utf8_text_survives(): void
    {
        $this->assertSame('<input name="t" value="Über – 💡" form="f">', FormOwner::assign('<input name="t" value="Über – 💡">', 'f'));
    }

    public function test_an_unsafe_form_id_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormOwner::assign('<input name="a">', 'x" onclick="evil');
    }
}
