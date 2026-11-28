<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\BlockDefinition;
use Preflow\Core\Context\Contexts;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\FolioBlockPreviewMarks;
use Preflow\Folio\Content\FolioRecordPreviewMarks;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Tests\Support\TempRoots;

/**
 * Folio's preview marks with no render frame open (a block rendered directly, a site's test): they never
 * throw and give the label as in 0.93.1. Outside every render there is no preview, so no
 * PreviewLabelLanguage (0.94).
 */
final class PreviewMarksOutsideRenderTest extends TestCase
{
    protected function setUp(): void
    {
        Contexts::reset();
    }

    public function test_block_marks_outside_every_render(): void
    {
        $marks = new FolioBlockPreviewMarks();
        $def = new BlockDefinition('x', 'Teaser', 'g', null, null, 'X', false, []);

        $this->assertSame(
            ['data-folio-block' => 'u1', 'data-folio-label' => 'Teaser'],
            $marks->attributes($def, ['_uid' => 'u1']),
        );
    }

    public function test_record_marks_outside_every_render(): void
    {
        $dir = TempRoots::directory('folio-marks-');
        file_put_contents($dir . '/note.json', (string) json_encode(['key' => 'note', 'label' => 'Notes', 'singular' => 'Note', 'fields' => ['name' => ['type' => 'string']]]));
        $registry = new TypeRegistry($dir);
        $marks = new FolioRecordPreviewMarks(new DisplayLabels(new TypeCatalog($dir, null, $registry), $registry));

        $this->assertSame(
            ['data-folio-record' => 'note', 'data-folio-record-id' => 'n1', 'data-folio-label' => 'Note'],
            $marks->attributes('note', 'n1'),
        );
    }
}
