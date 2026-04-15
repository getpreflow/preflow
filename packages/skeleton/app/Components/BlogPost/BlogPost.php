<?php

declare(strict_types=1);

namespace App\Components\BlogPost;

use Preflow\Components\Component;

final class BlogPost extends Component
{
    protected string $tag = 'article';
    protected string $cssClass = 'blog-post';
    protected bool $scopeCss = true;

    public string $title = '';
    public string $slug = '';
    public string $body = '';
    public string $status = '';

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? '';
        $this->slug = $this->props['slug'] ?? '';
        $this->body = $this->props['body'] ?? '';
        $this->status = $this->props['status'] ?? '';
    }
}
