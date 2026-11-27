<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use Preflow\Blocks\RecordPreviewMarks;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\View\TemplateEngineInterface;

/**
 * preview_mark(type, record): a template marks the element it shows a linked record in, and the
 * marks (RecordPreviewMarks; without an editor DefaultRecordPreviewMarks) are printed only in a
 * preview render, in Twig and Blade alike.
 */
final class RecordPreviewMarksTest extends BlocksAppTestCase
{
    private const TEMPLATE_TWIG = '<a href="/team/ann"{{ preview_mark(\'member\', member) }}>Ann</a><a{{ preview_mark(\'member\', \'m2\') }}>Bo</a>';
    // Blade compiles a directive only after a space (an "@" right after a word is text, as in an address).
    private const TEMPLATE_BLADE = '<a href="/team/ann" @preview_mark(\'member\', $member)>Ann</a><a @preview_mark(\'member\', \'m2\')>Bo</a>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        // An id field of its own: the array form reads the type's, not "id".
        $this->write('config/models/member.json', (string) json_encode([
            'key' => 'member', 'table' => 'member', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['name' => ['type' => 'string']],
        ]));
    }

    public function test_preview_mark_prints_nothing_outside_a_preview(): void
    {
        $member = ['uuid' => 'm1', 'id' => 'wrong', 'name' => 'Ann'];
        $plain = '<a href="/team/ann">Ann</a><a>Bo</a>';

        $this->assertSame($plain, $this->render(self::TEMPLATE_TWIG, ['member' => $member]), 'a visit');
        // A share-link preview: a visit that reads working copies and skips writes, not an editor's preview.
        $this->assertSame($plain, $this->renderIn($this->visit()->workingCopies()->skippingWrites(), self::TEMPLATE_TWIG, ['member' => $member]), 'a share-link preview');
        $this->assertSame($plain, $this->renderIn($this->visit()->because(Purpose::Fragment), self::TEMPLATE_TWIG, ['member' => $member]), 'a fragment');
    }

    public function test_preview_mark_prints_the_marks_in_a_preview(): void
    {
        $member = ['uuid' => 'm1', 'id' => 'wrong', 'name' => 'Ann'];

        $this->assertSame(
            '<a href="/team/ann" data-record-type="member" data-record-id="m1">Ann</a><a data-record-type="member" data-record-id="m2">Bo</a>',
            $this->renderIn($this->preview(), self::TEMPLATE_TWIG, ['member' => $member]),
            'Twig: the array form by the type\'s id field, the id form as given',
        );
    }

    public function test_preview_mark_prints_the_marks_in_a_blade_preview(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Blocks', 'debug' => 1, 'engine' => 'blade', 'key' => 'blocks-app-test-key-0123456789abcdef'];\n");
        $member = ['uuid' => 'm1', 'name' => 'Ann'];
        $this->write('page.blade.php', self::TEMPLATE_BLADE);
        $render = fn (RenderContext $ctx): string => Contexts::run($ctx, fn (): string => $this->app()->container()->get(TemplateEngineInterface::class)->render('page.blade.php', ['member' => $member]));

        $this->assertSame('<a href="/team/ann"  data-record-type="member" data-record-id="m1">Ann</a><a  data-record-type="member" data-record-id="m2">Bo</a>', $render($this->preview()));
        $this->assertSame('<a href="/team/ann" >Ann</a><a >Bo</a>', $render($this->visit()), 'nothing on a visit');
    }

    public function test_preview_mark_takes_a_record(): void
    {
        $def = $this->app()->container()->get(\Preflow\Data\DataManager::class)->getTypeRegistry()->get('member');
        $record = \Preflow\Data\DynamicRecord::fromArray($def, ['uuid' => 'm3', 'name' => 'Cy']);

        $this->assertSame('<a data-record-type="member" data-record-id="m3"></a>', $this->renderIn($this->preview(), '<a{{ preview_mark(\'member\', member) }}></a>', ['member' => $record]));
        $this->assertSame('<a></a>', $this->render('<a{{ preview_mark(\'member\', member) }}></a>', ['member' => $record]), 'not on a visit');
    }

    public function test_preview_mark_outside_every_render_prints_nothing(): void
    {
        $this->write('page.twig', '<a{{ preview_mark(\'member\', \'m1\') }}></a>');

        $this->assertSame('<a></a>', $this->app()->container()->get(TemplateEngineInterface::class)->render('page.twig'));
    }

    public function test_preview_mark_without_an_id_prints_nothing(): void
    {
        $html = $this->renderIn($this->preview(), '<a{{ preview_mark(\'member\', {name: \'Ann\'}) }}></a><a{{ preview_mark(\'member\', \'\') }}></a><a{{ preview_mark(\'nope\', {uuid: \'x\'}) }}></a><a{{ preview_mark(\'member\', 7) }}></a>');

        $this->assertSame('<a></a><a></a><a></a><a data-record-type="member" data-record-id="7"></a>', $html, 'no id field, an empty id, an unknown type\'s array; a number is an id');
    }

    public function test_preview_mark_escapes_and_uses_the_bound_marks(): void
    {
        $this->app()->container()->instance(RecordPreviewMarks::class, new class implements RecordPreviewMarks {
            public function attributes(string $type, string $id): array
            {
                return ['data-x-record' => $type . ':' . $id, 'data-x-label' => '"Team" <b>'];
            }
        });

        $html = $this->renderIn($this->preview(), '<a{{ preview_mark(\'member\', \'a"b\') }}></a>');

        $this->assertSame('<a data-x-record="member:a&quot;b" data-x-label="&quot;Team&quot; &lt;b&gt;"></a>', $html);
    }

    public function test_preview_mark_refuses_an_attribute_that_is_no_data_attribute(): void
    {
        $this->app()->container()->instance(RecordPreviewMarks::class, new class implements RecordPreviewMarks {
            public function attributes(string $type, string $id): array
            {
                return ['onclick' => 'x'];
            }
        });

        $this->expectException(\Twig\Error\RuntimeError::class);
        $this->expectExceptionMessageMatches('/attribute name "onclick"; preview marks are data-/');
        $this->renderIn($this->preview(), '<a{{ preview_mark(\'member\', \'m1\') }}></a>');
    }

    private function preview(): RenderContext
    {
        return $this->visit()->asVisitor()->public()->workingCopies()->capturing()->skippingWrites()->because(Purpose::Preview);
    }

    /** @param array<string, mixed> $context */
    private function renderIn(RenderContext $ctx, string $template, array $context = []): string
    {
        $this->write('page.twig', $template);

        return Contexts::run($ctx, fn (): string => $this->app()->container()->get(TemplateEngineInterface::class)->render('page.twig', $context));
    }
}
