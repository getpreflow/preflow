<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\State;

use Preflow\Components\Component;

/** A component without state: its action greets the posted "name" with the "greeting" prop. */
final class Greeter extends Component
{
    public string $line = '';

    public function actions(): array
    {
        return ['greet'];
    }

    /** @param array<string, mixed> $params */
    public function actionGreet(array $params = []): void
    {
        $this->line = ($this->props['greeting'] ?? 'Hey') . ', ' . ($params['name'] ?? '');
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return __DIR__ . '/Greeter.' . $extension;
    }
}
