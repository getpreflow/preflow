<?php

declare(strict_types=1);

namespace Preflow\I18n;

use Preflow\Core\Http\LocaleUrls;
use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateExtensionProvider;

final class TranslationExtensionProvider implements TemplateExtensionProvider
{
    /**
     * @param list<string> $available configured locales (for locale_url / available_locales)
     * @param string|null  $default   the default locale (served without a URL prefix)
     * @param LocaleUrls|null $urls   the locale URL rule (core 0.78, Http\LocaleUrls); null keeps the plain rule
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly array $available = [],
        private readonly ?string $default = null,
        private readonly ?LocaleUrls $urls = null,
    ) {}

    public function getTemplateFunctions(): array
    {
        return [
            // Output is trusted translation text; :params are escaped, since they
            // usually carry user data.
            new TemplateFunctionDefinition(
                name: 't',
                callable: fn (string $key, array $params = [], ?int $count = null) => $count !== null
                    ? $this->translator->choice($key, $count, self::escape($params))
                    : $this->translator->get($key, self::escape($params)),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'tc',
                callable: fn (string $key, string $componentName, array $params = []) =>
                    $this->translator->get($this->componentKey($componentName, $key), self::escape($params)),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'current_locale',
                callable: fn (): string => $this->translator->getLocale(),
            ),
            new TemplateFunctionDefinition(
                name: 'available_locales',
                callable: fn (): array => $this->available,
            ),
            new TemplateFunctionDefinition(
                name: 'locale_url',
                callable: fn (string $path, ?string $locale = null): string => $this->localeUrl($path, $locale),
            ),
            new TemplateFunctionDefinition(
                name: 'locale_switch_url',
                callable: fn (string $path, ?string $locale = null): string => $this->localeSwitchUrl($path, $locale),
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }

    /** "/about" for the default locale, "/de/about" otherwise ("/" and "/de" for the root). */
    public function localeUrl(string $path, ?string $locale = null): string
    {
        $locale ??= $this->translator->getLocale();

        // A known locale on a prefixed site follows LocaleUrls (prefix_default). Anything
        // else (unknown locale, url_strategy "none") keeps today's output.
        if ($this->urls !== null && $this->urls->prefixed && in_array($locale, $this->urls->locales, true)) {
            return $this->urls->path($locale, '/' . trim($path, '/'));
        }

        $path = trim($path, '/');

        if ($locale === ($this->default ?? $locale)) {
            return '/' . $path;
        }

        return '/' . $locale . ($path !== '' ? '/' . $path : '');
    }

    /**
     * The URL a language switcher links to: through the switch endpoint (which remembers the
     * pick) when bare paths redirect, otherwise the locale URL itself.
     */
    public function localeSwitchUrl(string $path, ?string $locale = null): string
    {
        $locale ??= $this->translator->getLocale();
        $url = $this->localeUrl($path, $locale);

        return $this->urls?->redirectBare === true
            ? '/--locale/' . $locale . '?to=' . rawurlencode($url)
            : $url;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string|int>
     */
    private static function escape(array $params): array
    {
        return array_map(
            static fn ($value) => is_int($value) ? $value : htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $params,
        );
    }

    private function componentKey(string $componentName, string $key): string
    {
        $group = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $componentName));
        return $group . '.' . $key;
    }
}
