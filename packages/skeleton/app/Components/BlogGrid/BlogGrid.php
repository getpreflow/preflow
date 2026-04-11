<?php

declare(strict_types=1);

namespace App\Components\BlogGrid;

use Preflow\Components\Component;
use Preflow\Data\DataManager;

final class BlogGrid extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $posts = [];
    public int $total = 0;
    public string $filter = '';

    public function __construct(
        private DataManager $data,
    ) {}

    public function resolveState(): void
    {
        $this->filter = $this->props['filter'] ?? '';

        $query = $this->data->query(\App\Models\Post::class)
            ->orderBy('uuid', \Preflow\Data\SortDirection::Desc);

        if ($this->filter !== '' && $this->filter !== 'all') {
            $query->where('status', $this->filter);
        }

        $result = $query->get();
        $this->posts = array_map(fn ($p) => $p->toArray(), $result->items());
        $this->total = $result->total();
    }

    public function actions(): array
    {
        return ['filter'];
    }

    public function actionFilter(array $params = []): void
    {
        // Filter value comes from props (encoded in the token by the button)
        $this->resolveState();
    }
}
