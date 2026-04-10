<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Timestamps;

#[Entity(table: 'posts', storage: 'sqlite')]
final class Post extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field(searchable: true)]
    public string $title = '';

    #[Field]
    public string $slug = '';

    #[Field]
    public string $body = '';

    #[Field]
    public string $status = 'draft';

    #[Timestamps]
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;
}
