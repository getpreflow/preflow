<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\SiteCheck;
use Preflow\View\Geo\GeoConfig;

/**
 * `llms.full-too-large` (warning): a locale's llms-full.txt is larger than
 * `full_max_mb` in config/geo.php (default 5 MB), too large for an AI
 * assistant to take in. With GEO or llms-full.txt off it finds nothing.
 *
 * @since 0.55
 */
final class LlmsFullTooLarge implements SiteCheck
{
    public function __construct(
        private readonly GeoConfig $geo,
        private readonly ?string $publicDir = null,
    ) {}

    public function id(): string
    {
        return 'llms.full-too-large';
    }

    public function check(PageRenderer $renderer): array
    {
        if (!$this->geo->enabled() || !$this->geo->full) {
            return [];
        }

        $limit = (int) round($this->geo->fullMaxMb * 1024 * 1024);
        $findings = [];
        foreach ($renderer->locales() as $locale) {
            $path = $locale === $renderer->defaultLocale() ? '/llms-full.txt' : "/{$locale}/llms-full.txt";
            $size = strlen(SiteFiles::read($renderer, $this->publicDir, $path) ?? '');
            if ($size > $limit) {
                $findings[] = new Finding($this->id(), Severity::Warning,
                    "{$path} is " . self::size($size) . ', more than an AI assistant takes in at once (limit ' . self::size($limit)
                    . '). Leave long or minor pages out of it (PublicPages, llmsFull: false), set `full` => false, or raise `full_max_mb` in config/geo.php.',
                    $renderer->origin() . $path, $locale);
            }
        }

        return $findings;
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? round($bytes / 1024 / 1024, 1) . ' MB' : (int) round($bytes / 1024) . ' KB';
    }
}
