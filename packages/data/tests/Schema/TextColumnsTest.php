<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Preflow\Data\FieldKinds;
use Preflow\Data\Locales;
use Preflow\Data\Schema\TextColumns;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/** The fields compared by the text rule (ignore case, respect accents): free text, not lists, ids or exact fields. */
final class TextColumnsTest extends TestCase
{
    public function test_text_fields_are_string_and_text_kinds_only(): void
    {
        $kinds = new FieldKinds();
        $kinds->list('tags');
        $def = new TypeDefinition('thing', 'thing', 'default', [
            'uuid' => new TypeFieldDefinition('uuid', 'string'),
            'title' => new TypeFieldDefinition('title', 'string'),
            'body' => new TypeFieldDefinition('body', 'text'),
            'slug' => new TypeFieldDefinition('slug', 'string', unique: true),
            'email' => new TypeFieldDefinition('email', 'string'),
            'tags' => new TypeFieldDefinition('tags', 'tags'),
            'owner' => new TypeFieldDefinition('owner', 'relation'),
            'n' => new TypeFieldDefinition('n', 'number'),
            'd' => new TypeFieldDefinition('d', 'date'),
            'apiKey' => new TypeFieldDefinition('apiKey', 'string', exact: true),
            'name' => new TypeFieldDefinition('name', 'string', translatable: true),
        ], idField: 'uuid', locales: new Locales(['de', 'en'], 'de'));

        $this->assertSame(['title', 'body', 'slug', 'email', 'name'], TextColumns::of($def, $kinds));
    }

    public function test_a_registered_text_kind_is_text_unless_it_is_a_list(): void
    {
        $kinds = new FieldKinds();
        $kinds->text('richtext', 'tags');
        $kinds->list('tags');
        $def = new TypeDefinition('thing', 'thing', 'default', [
            'intro' => new TypeFieldDefinition('intro', 'richtext'),
            'tags' => new TypeFieldDefinition('tags', 'tags'),
        ]);

        $this->assertSame(['intro'], TextColumns::of($def, $kinds));
    }
}
