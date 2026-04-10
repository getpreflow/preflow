<?php

declare(strict_types=1);

namespace App\Components;

use Preflow\Components\Component;

final class ExampleCard extends Component
{
    public string $title = '';
    public string $message = '';
    public int $count = 0;

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Welcome to Preflow';
        $this->message = $this->props['message'] ?? 'Your first component.';
    }

    public function actions(): array
    {
        return ['increment'];
    }

    public function actionIncrement(array $params = []): void
    {
        $this->count++;
    }
}
