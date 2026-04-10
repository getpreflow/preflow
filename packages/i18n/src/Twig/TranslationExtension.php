<?php

declare(strict_types=1);

namespace Preflow\I18n\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Preflow\I18n\Translator;

final class TranslationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Translator $translator,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translate(...), ['is_safe' => ['html']]),
            new TwigFunction('tc', $this->translateComponent(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Global translation function.
     *
     * {{ t('blog.title') }}
     * {{ t('blog.published_at', { date: '2026-01-01' }) }}
     * {{ t('blog.post_count', { count: 5 }, 5) }}
     *
     * @param array<string, string|int> $params
     */
    public function translate(string $key, array $params = [], ?int $count = null): string
    {
        if ($count !== null) {
            return $this->translator->choice($key, $count, $params);
        }

        return $this->translator->get($key, $params);
    }

    /**
     * Component-scoped translation function.
     *
     * {{ tc('label', 'MyComponent') }}
     * Resolves: my-component.label (kebab-cased component name as group)
     *
     * @param array<string, string|int> $params
     */
    public function translateComponent(
        string $key,
        string $componentName,
        array $params = [],
        ?int $count = null,
    ): string {
        // Convert PascalCase to kebab-case for the translation group
        $group = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $componentName));
        $fullKey = $group . '.' . $key;

        if ($count !== null) {
            return $this->translator->choice($fullKey, $count, $params);
        }

        return $this->translator->get($fullKey, $params);
    }
}
