<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\EntityLayouts\Broken;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type whose "layouts.default" is not one of "layouts.allowed": the boot stops. */
#[Entity(table: 'x', type: 'x', storage: 'json', layouts: ['allowed' => ['landing'], 'default' => 'standard'])]
final class LayoutBroken extends Model
{
    #[Id] public string $uuid = '';
    #[Field] public string $title = '';
}
