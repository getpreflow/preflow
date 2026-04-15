<?php

declare(strict_types=1);

namespace App\Components\Navigation;

use Preflow\Components\Component;
use Preflow\Core\Http\RequestContext;

final class Navigation extends Component
{
    protected string $tag = 'nav';
    protected string $cssClass = 'main-nav';
    protected bool $scopeCss = true;

    /** @var array<int, array{path: string, label: string, active: bool}> */
    public array $items = [];

    public string $brand = '';

    public function __construct(
        private readonly RequestContext $requestContext,
    ) {}

    public function resolveState(): void
    {
        $currentPath = $this->requestContext->path;
        $this->brand = $this->props['brand'] ?? '';

        foreach ($this->props['items'] ?? [] as $item) {
            $path = $item['path'];
            if ($path === '/') {
                $active = $currentPath === '/';
            } else {
                $active = $currentPath === $path
                    || str_starts_with($currentPath, rtrim($path, '/') . '/');
            }
            $this->items[] = [...$item, 'active' => $active];
        }
    }
}
