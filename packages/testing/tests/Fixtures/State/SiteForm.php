<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\State;

use Preflow\Components\Component;

/** A form that is a call element and carries a site's own form token named `_token`. */
final class SiteForm extends Component
{
    public string $line = '';

    public function actions(): array
    {
        return ['send'];
    }

    /** @param array<string, mixed> $params */
    public function actionSend(array $params = []): void
    {
        $token = $params['_token'] ?? 'none';
        $this->line = sprintf('Sent with form token %s', is_string($token) ? $token : 'none');
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return __DIR__ . '/SiteForm.' . $extension;
    }
}
