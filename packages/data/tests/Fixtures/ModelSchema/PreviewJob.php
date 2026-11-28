<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelSchema;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type declaring its preview views in code (#[Entity(preview:)]). */
#[Entity(table: 'preview_jobs', type: 'preview_job', preview: ['views' => [
    'card' => ['label' => 'Card', 'component' => 'JobCard'],
    'wide' => ['component' => 'JobWide'],
]])]
final class PreviewJob extends Model
{
    #[Id] public string $uuid = '';
    #[Field] public string $title = '';
}
