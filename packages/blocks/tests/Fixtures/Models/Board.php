<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Models;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type with an areas field of its own; its admin file (the test writes it) opts into page layouts. */
#[Entity(table: 'board', storage: 'json', type: 'board', titleField: 'title')]
final class Board extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field]
    public string $title = '';

    #[Field]
    public string $slug = '';

    #[Field(type: 'areas', config: ['layouts' => 'grid'])]
    public array $columns = [];
}
