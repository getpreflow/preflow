<?php

declare(strict_types=1);

namespace App\Components\PostForm;

use Preflow\Components\Component;

final class PostForm extends Component
{
    public string $uuid = '';
    public string $title = '';
    public string $slug = '';
    public string $body = '';
    public string $status = 'draft';
    public string $action = '/admin/save';
    public bool $isEdit = false;

    public function resolveState(): void
    {
        $this->uuid = (string) ($this->props['uuid'] ?? '');
        $this->title = (string) ($this->props['title'] ?? '');
        $this->slug = (string) ($this->props['slug'] ?? '');
        $this->body = (string) ($this->props['body'] ?? '');
        $this->status = (string) ($this->props['status'] ?? 'draft');
        $this->action = (string) ($this->props['action'] ?? '/admin/save');
        $this->isEdit = $this->uuid !== '';
    }
}
