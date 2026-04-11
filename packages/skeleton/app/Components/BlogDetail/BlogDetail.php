<?php

declare(strict_types=1);

namespace App\Components\BlogDetail;

use Preflow\Components\Component;
use Preflow\Data\DataManager;

final class BlogDetail extends Component
{
    public ?array $post = null;

    public function __construct(
        private DataManager $data,
    ) {}

    public function resolveState(): void
    {
        $slug = $this->props['slug'] ?? '';
        if ($slug === '') return;

        $result = $this->data->query(\App\Models\Post::class)
            ->where('slug', $slug)
            ->first();

        if ($result !== null) {
            $this->post = $result->toArray();
        }
    }
}
