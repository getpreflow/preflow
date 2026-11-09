<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelBridge;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type with array fields: multiple assets, blocks and a multiple relation (JSON text on SQL). */
#[Entity(table: 'gallery', type: 'gallery')]
final class Gallery extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field]
    public ?string $title = null;

    #[Field(type: 'asset', config: ['asset' => ['multiple' => true]])]
    public ?array $images = null;

    #[Field(type: 'blocks')]
    public ?array $body = null;

    #[Field(type: 'relation', config: ['multiple' => true, 'relation' => ['to' => 'gallery', 'onDelete' => 'detach']])]
    public ?array $related = null;
}
