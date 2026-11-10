<?php

declare(strict_types=1);

namespace App\Components\LocaleSwitcher;

use Preflow\Components\Component;
use Preflow\Core\Http\RequestContext;
use Preflow\I18n\Translator;

final class LocaleSwitcher extends Component
{
    /** @var array<int, array{code: string, label: string, url: string, active: bool}> */
    public array $locales = [];

    public function __construct(
        private readonly Translator $translator,
        private readonly RequestContext $requestContext,
    ) {}

    public function resolveState(): void
    {
        $currentLocale = $this->translator->getLocale();
        $availableLocales = $this->props['locales'] ?? [];
        $currentPath = $this->requestContext->path;

        // RequestContext's path has no locale prefix since 0.63.2; strip one anyway,
        // for a context bound before the locale middleware ran (/de/blog → /blog)
        foreach ($availableLocales as $code) {
            if ($currentPath === '/' . $code) {
                $currentPath = '/';
                break;
            }
            if (str_starts_with($currentPath, '/' . $code . '/')) {
                $currentPath = substr($currentPath, strlen($code) + 1);
                break;
            }
        }

        foreach ($availableLocales as $code) {
            $url = '/' . $code . ($currentPath === '/' ? '' : $currentPath);

            $this->locales[] = [
                'code' => $code,
                'label' => strtoupper($code),
                'url' => $url,
                'active' => $code === $currentLocale,
            ];
        }
    }
}
