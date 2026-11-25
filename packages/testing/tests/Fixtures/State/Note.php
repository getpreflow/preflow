<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\State;

use Preflow\Components\Component;

/** A component without state with a long "text" prop: its token carries all of it. */
final class Note extends Component
{
    public string $line = '';

    public function actions(): array
    {
        return ['save'];
    }

    /** @param array<string, mixed> $params */
    public function actionSave(array $params = []): void
    {
        $text = $this->props['text'] ?? '';
        $this->line = sprintf('Saved %d characters, params: %s', is_string($text) ? strlen($text) : 0, implode(',', array_keys($params)));
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return __DIR__ . '/Note.' . $extension;
    }
}
