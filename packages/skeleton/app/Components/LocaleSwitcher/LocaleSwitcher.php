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
        $currentPath = $this->requestContext->path;

        if ($currentPath === '' || $currentPath === '/') {
            $currentPath = '/';
        }

        foreach ($this->props['locales'] ?? [] as $code) {
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
