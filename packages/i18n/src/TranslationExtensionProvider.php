<?php

declare(strict_types=1);

namespace Preflow\I18n;

use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateExtensionProvider;

final class TranslationExtensionProvider implements TemplateExtensionProvider
{
    public function __construct(
        private readonly Translator $translator,
    ) {}

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 't',
                callable: fn (string $key, array $params = [], ?int $count = null) =>
                    $count !== null ? $this->translator->choice($key, $count, $params) : $this->translator->get($key, $params),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'tc',
                callable: fn (string $key, string $componentName, array $params = []) =>
                    $this->translator->get($this->componentKey($componentName, $key), $params),
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }

    private function componentKey(string $componentName, string $key): string
    {
        $group = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $componentName));
        return $group . '.' . $key;
    }
}
