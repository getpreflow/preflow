<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\EntityLayouts\Valid;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type with page layouts declared in code, as the JSON option of PageLayoutsTest's "page". */
#[Entity(table: 'el_page', type: 'el_page', storage: 'json', layouts: [
    'allowed' => ['promo', 'standard', 'gone'],
    'default' => 'standard',
    'pinned' => ['home' => 'home', 'legacy' => 'missing'],
])]
final class LayoutPage extends Model
{
    #[Id] public string $uuid = '';
    #[Field] public string $title = '';
    #[Field(type: 'string', translatable: true)] public array|string|null $slug = null;
}
