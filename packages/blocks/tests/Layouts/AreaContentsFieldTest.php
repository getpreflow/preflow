<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\Layouts\AreaDefinition;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\Rules\AreaContents;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;

/** AreaContents::forField(): a {layout, areas} value checked against the layout its set resolves. */
final class AreaContentsFieldTest extends TestCase
{
    public function test_for_field_checks_the_areas_of_the_layout_its_set_resolves(): void
    {
        $set = new AreaLayoutSet('columns', [
            new AreaLayout('1-1', 'Two columns', [
                'c1' => new AreaDefinition('c1', 'Column 1', width: 1),
                'c2' => new AreaDefinition('c2', 'Column 2', except: ['columns'], width: 1),
            ]),
        ], fallback: '1-1');
        $rule = AreaContents::forField($set);
        $context = new ValidationContext('columns', []);

        $refused = $rule->validate(['layout' => '1-1', 'areas' => ['c1' => [], 'c2' => [['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'columns']]]], $context);
        $this->assertInstanceOf(Message::class, $refused);
        $this->assertSame('The area "c2" does not take "columns" blocks. Move it to another area or remove it.', $refused->english());

        $this->assertTrue($rule->validate(['layout' => '1-1', 'areas' => ['c1' => [['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'columns']], 'c2' => [['_uid' => 'cccccccccccccccc', '_block' => 'text']]]], $context));
        $this->assertTrue($rule->validate((string) json_encode(['layout' => 'gone', 'areas' => ['c1' => [], 'c2' => []]]), $context), 'a key the set lacks resolves (by count), JSON text too');
    }
}
