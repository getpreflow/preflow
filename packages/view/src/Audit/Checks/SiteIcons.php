<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\SiteCheck;

/**
 * The site's icons, as the home page links them:
 *
 * - `favicon.raster-missing` (warning): no raster icon of at least 48 px is linked (by its `sizes`, else by
 *   measuring the file in public/ or the app's answer) and /favicon.ico doesn't answer; search results show
 *   such an icon next to the site;
 * - `apple-touch.missing` (notice): no apple-touch-icon is linked and /apple-touch-icon.png doesn't answer;
 * - `manifest.missing` (notice): no web app manifest is linked.
 *
 * A home page that isn't HTML answering 200 is left to its own status finding.
 *
 * @since 0.54
 */
final class SiteIcons implements SiteCheck
{
    private const MIN_SIZE = 48;

    public function __construct(private readonly ?string $publicDir = null) {}

    public function id(): string
    {
        return 'icons';
    }

    public function check(PageRenderer $renderer): array
    {
        $home = $renderer->render('/');
        $dom = $home->status === 200 ? $home->dom() : null;
        if ($dom === null) {
            return [];
        }

        $findings = [];
        if (!$this->hasRasterIcon($dom, $home->url, $renderer) && !$this->answers($renderer, '/favicon.ico')) {
            $findings[] = new Finding('favicon.raster-missing', Severity::Warning,
                'The home page links no raster icon of at least 48×48 px (PNG or ICO) and /favicon.ico doesn\'t answer, so search results show a generic icon. Link one with <link rel="icon" sizes="48x48">.');
        }
        if (!self::linked($dom, 'apple-touch-icon') && !self::linked($dom, 'apple-touch-icon-precomposed')
            && !$this->answers($renderer, '/apple-touch-icon.png')) {
            $findings[] = new Finding('apple-touch.missing', Severity::Notice,
                'No apple-touch-icon (180×180 px PNG) is linked, so phones show a screenshot when the site is added to the home screen.');
        }
        if (!self::linked($dom, 'manifest')) {
            $findings[] = new Finding('manifest.missing', Severity::Notice,
                'No web app manifest is linked (<link rel="manifest">), so browsers have no name, icons or colours for the installed site.');
        }

        return $findings;
    }

    private function hasRasterIcon(\DOMDocument $dom, string $base, PageRenderer $renderer): bool
    {
        foreach (Html::links($dom, 'icon') as $link) {
            $href = Urls::resolve($link->getAttribute('href'), $base);
            if ($href === null || self::isSvg($link, $href)) {
                continue;
            }
            $declared = self::declaredSize($link->getAttribute('sizes'));
            if ($declared !== null) {
                if ($declared >= self::MIN_SIZE) {
                    return true;
                }
                continue;
            }
            if (Urls::host($href) === Urls::host($renderer->origin()) && $this->measure($renderer, Urls::path($href)) >= self::MIN_SIZE) {
                return true;
            }
        }

        return false;
    }

    private static function isSvg(\DOMElement $link, string $href): bool
    {
        return str_contains(strtolower($link->getAttribute('type')), 'svg')
            || str_ends_with(strtolower(Urls::path($href)), '.svg');
    }

    /** The largest size `sizes` declares (the smaller side of each WxH); null when it declares none ('any', empty). */
    private static function declaredSize(string $sizes): ?int
    {
        $largest = null;
        foreach (preg_split('/\s+/', trim($sizes)) ?: [] as $size) {
            if (preg_match('/^(\d+)x(\d+)$/i', $size, $m) === 1) {
                $largest = max($largest ?? 0, min((int) $m[1], (int) $m[2]));
            }
        }

        return $largest;
    }

    /** The smaller side, in pixels, of the image at $path (public/ file, else the app's answer); 0 when unknown. */
    private function measure(PageRenderer $renderer, string $path): int
    {
        $file = SiteFiles::publicFile($this->publicDir, $path);
        if ($file !== null) {
            $info = @getimagesize($file);
        } else {
            $page = $renderer->render($path);
            $info = $page->status === 200 && $page->body !== '' ? @getimagesizefromstring($page->body) : false;
        }

        return is_array($info) ? min((int) $info[0], (int) $info[1]) : 0;
    }

    /** Whether the site serves $path: a file in public/ or a 200 from the app. */
    private function answers(PageRenderer $renderer, string $path): bool
    {
        return SiteFiles::publicFile($this->publicDir, $path) !== null || $renderer->render($path)->status === 200;
    }

    private static function linked(\DOMDocument $dom, string $rel): bool
    {
        foreach (Html::links($dom, $rel) as $link) {
            if (trim($link->getAttribute('href')) !== '') {
                return true;
            }
        }

        return false;
    }
}
