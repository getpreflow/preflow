<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\SelectOptionsFrom;

/** A select's "options_from" never names a field that holds nested entries (blocks, matrix, an areas field's columns). */
final class SelectOptionsFromTest extends TestCase
{
    public function test_a_structured_source_is_refused(): void
    {
        $messages = [];
        foreach (['blocks' => 'a', 'matrix' => 'a', 'areas' => 'an'] as $kind => $article) {
            try {
                SelectOptionsFrom::check(['options_from' => 'source'], 'choice', ['choice' => 'select', 'source' => $kind], 'block');
                $this->fail("a {$kind} field was taken as a source");
            } catch (\LogicException $e) {
                $messages[$kind] = $e->getMessage();
                $this->assertSame('Select field option "options_from" names "source", ' . $article . ' ' . $kind . ' field; name a field of this block that holds values (such as tags).', $e->getMessage());
            }
        }
        $this->assertCount(3, $messages);
        SelectOptionsFrom::check(['options_from' => 'source'], 'choice', ['choice' => 'select', 'source' => 'tags']);
        $this->addToAssertionCount(1); // a field holding values is a source
    }
}
