<?php

declare(strict_types=1);

namespace App\Components\ExampleCard;

use Preflow\Components\Component;
use Preflow\Core\Http\Session\SessionInterface;

final class ExampleCard extends Component
{
    private const SESSION_KEY = 'example_counter';

    protected string $cssClass = 'example-card';
    protected bool $scopeCss = true;

    public string $title = '';
    public string $message = '';
    public int $count = 0;

    public function __construct(
        private readonly SessionInterface $session,
    ) {}

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Welcome to Preflow';
        $this->message = $this->props['message'] ?? 'Your first component.';
        $this->count = (int) $this->session->get(self::SESSION_KEY, 0);
    }

    public function actions(): array
    {
        return ['increment'];
    }

    public function actionIncrement(array $params = []): void
    {
        $this->count = (int) $this->session->get(self::SESSION_KEY, 0) + 1;
        $this->session->set(self::SESSION_KEY, $this->count);
    }
}
