<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\SiteCheck;
use Preflow\View\Geo\GeoConfig;

/**
 * `llms.missing` (warning): GEO is on, but a locale's llms.txt (`/llms.txt`
 * for the default locale, `/{locale}/llms.txt` for the others) answers
 * neither from the app nor from public/. With GEO off it finds nothing.
 *
 * @since 0.54
 */
final class SiteLlms implements SiteCheck
{
    public function __construct(
        private readonly GeoConfig $geo,
        private readonly ?string $publicDir = null,
    ) {}

    public function id(): string
    {
        return 'llms.missing';
    }

    public function check(PageRenderer $renderer): array
    {
        if (!$this->geo->enabled()) {
            return [];
        }

        $findings = [];
        foreach ($renderer->locales() as $locale) {
            $path = $locale === $renderer->defaultLocale() ? '/llms.txt' : "/{$locale}/llms.txt";
            if (SiteFiles::read($renderer, $this->publicDir, $path) === null) {
                $findings[] = new Finding($this->id(), Severity::Warning,
                    "{$path} doesn't answer, so AI assistants get no overview of the site in this language. Check config/geo.php and the pages llms.txt lists.",
                    $renderer->origin() . $path, $locale);
            }
        }

        return $findings;
    }
}
