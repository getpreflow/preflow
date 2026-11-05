<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\RunCheck;
use Preflow\View\Audit\Severity;

/**
 * `link.broken`: links, images and other files an audited page points to on this
 * site, each status-checked once per run by rendering it (the render happens
 * while the page is collected, so finish() only reports). Critical when the
 * target answers 404, 410 or 5xx, or keeps redirecting; a notice when it gets
 * there through two or more redirects. One finding per target, on the first page
 * that links it, with the number of pages that do.
 *
 * Not checked: other hosts, URLs with a query (search, filters, pages of a
 * list), paths robots.txt keeps crawlers from, and files (or directories with an
 * index.html) the web server serves from public/ itself, also where a redirect
 * ends. A media variant that is allowed but not made yet (a 404 marked pending)
 * counts as fine.
 *
 * Nor are the run's own public pages (queued()): they are audited themselves,
 * so each page is rendered once per run. A link to one that answers 404 or 5xx
 * or redirects shows as that page's `status.error` or `status.redirect`, not as
 * `link.broken` on the pages linking it.
 *
 * @since 0.54
 */
final class BrokenLinks implements RunCheck
{
    /** The header and value a marked render's not-yet-made media variant answers with (media's VariantController::PENDING_HEADER); duplicated so view needn't depend on media. */
    private const PENDING_HEADER = 'X-Preflow-Variant';
    private const PENDING = 'pending';

    /** More redirects than this count as a loop. */
    private const MAX_REDIRECTS = 5;

    /** Elements and the attributes that name a target. */
    private const ATTRIBUTES = [
        'a' => ['href'], 'area' => ['href'], 'img' => ['src', 'srcset'], 'source' => ['src', 'srcset'],
        'video' => ['src', 'poster'], 'audio' => ['src'], 'track' => ['src'], 'iframe' => ['src'], 'script' => ['src'],
    ];

    /** The <link> rels whose href is a file the page loads. */
    private const LINK_RELS = ['stylesheet', 'icon', 'apple-touch-icon', 'manifest', 'preload', 'modulepreload', 'mask-icon'];

    private readonly string $host;

    private ?RobotsRules $robots = null;

    /** @var array<string, true> decoded paths of the pages the run audits itself */
    private array $queued = [];

    /**
     * Each target seen this run, by decoded path: how it answered (k: ok, chain, broken or loop; s: final status;
     * h: redirects; f: where it ended) and, for one to report, the first page linking it (u, l) and how many do (n).
     *
     * @var array<string, array{k: string, s: int, h: int, f: string, p?: string, u?: string, l?: string, n?: int}>
     */
    private array $targets = [];

    /** @param ?string $publicDir the web server's document root (public/); its files aren't the app's to answer */
    public function __construct(
        private readonly AuditRegistry $registry,
        private readonly PageRenderer $renderer,
        private readonly ?string $publicDir = null,
    ) {
        $this->host = Urls::host($renderer->origin());
    }

    public function id(): string
    {
        return 'link.broken';
    }

    public function collect(RenderedPage $page): void
    {
        $dom = $page->dom();
        if ($dom === null) {
            return;
        }
        $this->robots ??= $this->readRobots();
        // The page itself answered 2xx: a link to it needs no render.
        $this->targets[rawurldecode(Urls::path($page->url))] ??= ['k' => 'ok', 's' => $page->status, 'h' => 0, 'f' => Urls::path($page->url)];

        $onPage = [];
        foreach ($this->hrefs($dom) as $href) {
            $url = Urls::resolve($href, $page->url);
            if ($url === null || Urls::host($url) !== $this->host || str_contains($url, '?')) {
                continue;
            }
            $path = Urls::path($url);
            $key = rawurldecode($path);
            if (isset($onPage[$key]) || isset($this->queued[$key]) || $this->robots->disallows($path) || $this->isPublicFile($key)) {
                continue;
            }
            $onPage[$key] = true;

            $this->targets[$key] ??= $this->follow($path);
            if ($this->targets[$key]['k'] === 'ok') {
                continue;
            }
            if (!isset($this->targets[$key]['u'])) {
                $this->targets[$key] += ['p' => $path, 'u' => $page->url, 'l' => $page->locale, 'n' => 0];
            }
            $this->targets[$key]['n']++;
        }
    }

    /**
     * The paths (as written or decoded) of the pages the run audits itself; links to them aren't rendered. Set by
     * AuditRun after restore(), for each run.
     *
     * @param list<string> $paths
     */
    public function queued(array $paths): void
    {
        $this->queued = [];
        foreach ($paths as $path) {
            $this->queued[rawurldecode($path)] = true;
        }
    }

    public function finish(): array
    {
        $findings = [];
        foreach ($this->targets as $target) {
            if ($target['k'] === 'ok' || !isset($target['u'], $target['p'], $target['n'])) {
                continue;
            }
            $findings[] = Html::finding(
                $this->registry,
                $this->id(),
                $target['k'] === 'chain' ? Severity::Notice : Severity::Critical,
                self::message($target['p'], $target, $target['n'] === 1 ? 'linked from 1 page' : "linked from {$target['n']} pages"),
                $target['u'],
                $target['l'] ?? '',
            );
        }

        return $findings;
    }

    public function state(): array
    {
        return ['robots' => $this->robots?->rules, 'targets' => $this->targets];
    }

    public function restore(array $state): void
    {
        $this->robots = null;
        if (is_array($state['robots'] ?? null)) {
            $rules = [];
            foreach ($state['robots'] as $rule) {
                if (is_array($rule) && isset($rule[0], $rule[1])) {
                    $rules[] = [(bool) $rule[0], (string) $rule[1]];
                }
            }
            $this->robots = new RobotsRules($rules);
        }

        $this->targets = [];
        foreach (is_array($state['targets'] ?? null) ? $state['targets'] : [] as $key => $target) {
            if (!is_array($target) || !is_string($target['k'] ?? null)) {
                continue;
            }
            $entry = ['k' => $target['k'], 's' => (int) ($target['s'] ?? 0), 'h' => (int) ($target['h'] ?? 0), 'f' => (string) ($target['f'] ?? '')];
            if (isset($target['u'])) {
                $entry += ['p' => (string) ($target['p'] ?? $key), 'u' => (string) $target['u'], 'l' => (string) ($target['l'] ?? ''), 'n' => (int) ($target['n'] ?? 0)];
            }
            $this->targets[(string) $key] = $entry;
        }
    }

    /**
     * How the target at $path answers, following redirects on this site (a target seen before is not rendered again).
     *
     * @return array{k: string, s: int, h: int, f: string}
     */
    private function follow(string $path): array
    {
        $redirects = 0;
        $visited = [];
        while (true) {
            $key = rawurldecode($path);
            if ($redirects > 0 && isset($this->targets[$key])) {
                return self::after($redirects, $this->targets[$key]);
            }
            if ($redirects > 0 && (isset($this->queued[$key]) || $this->isPublicFile($key))) {
                // A page the run audits itself, or a file the web server serves: taken as answering 200.
                return self::after($redirects, ['k' => 'ok', 's' => 200, 'h' => 0, 'f' => $path]);
            }
            if (isset($visited[$key]) || $redirects > self::MAX_REDIRECTS) {
                return ['k' => 'loop', 's' => 0, 'h' => $redirects, 'f' => $path];
            }
            $visited[$key] = true;

            $page = $this->renderer->render($path);
            $location = $page->status >= 300 && $page->status <= 399 ? $page->header('Location') : '';
            if ($location === '') {
                return self::answered($page, $redirects, $path);
            }
            $next = Urls::resolve($location, $page->url);
            $redirects++;
            if ($next === null || Urls::host($next) !== $this->host) {
                // Off this site: not checked further.
                return ['k' => $redirects >= 2 ? 'chain' : 'ok', 's' => $page->status, 'h' => $redirects, 'f' => $next ?? $location];
            }
            $path = Urls::path($next);
        }
    }

    /** @return array{k: string, s: int, h: int, f: string} */
    private static function answered(RenderedPage $page, int $redirects, string $path): array
    {
        $pending = $page->status === 404 && strtolower(trim($page->header(self::PENDING_HEADER))) === self::PENDING;
        $broken = !$pending && ($page->status === 404 || $page->status === 410 || $page->status >= 500);
        $kind = $broken ? 'broken' : ($redirects >= 2 ? 'chain' : 'ok');

        return ['k' => $kind, 's' => $page->status, 'h' => $redirects, 'f' => $path];
    }

    /**
     * A target reached after $redirects redirects, at one already known.
     *
     * @param array{k: string, s: int, h: int, f: string} $known
     * @return array{k: string, s: int, h: int, f: string}
     */
    private static function after(int $redirects, array $known): array
    {
        $total = $redirects + $known['h'];
        $kind = in_array($known['k'], ['broken', 'loop'], true) ? $known['k'] : ($total >= 2 ? 'chain' : 'ok');

        return ['k' => $kind, 's' => $known['s'], 'h' => $total, 'f' => $known['f']];
    }

    /** @param array{k: string, s: int, h: int, f: string} $target */
    private static function message(string $path, array $target, string $linked): string
    {
        $status = $target['s'] >= 500 ? "{$target['s']} in this environment" : (string) $target['s'];
        $via = $target['h'] === 1 ? 'redirects to' : "goes through {$target['h']} redirects to";

        return match (true) {
            $target['k'] === 'loop' => "The link to {$path} keeps redirecting (a loop, or more than " . self::MAX_REDIRECTS . " redirects; {$linked}); fix the redirects or the link.",
            $target['k'] === 'chain' => "The link to {$path} {$via} {$target['f']} ({$linked}); link to {$target['f']} directly.",
            $target['h'] > 0 => "The link to {$path} {$via} {$target['f']}, which answers {$status} ({$linked}); fix the target or the link.",
            default => "The link to {$path} answers {$status} ({$linked}); fix the target or the link.",
        };
    }

    /** robots.txt's rules for every crawler: the file in public/ if there is one, else as the app answers it. */
    private function readRobots(): RobotsRules
    {
        $file = $this->publicDir !== null ? $this->publicDir . '/robots.txt' : null;
        if ($file !== null && is_file($file)) {
            return RobotsRules::parse((string) file_get_contents($file));
        }
        $robots = $this->renderer->render('/robots.txt');

        return $robots->status === 200 ? RobotsRules::parse($robots->body) : new RobotsRules();
    }

    /** Whether the web server serves $path (decoded) from public/ without asking the app: a file, or a directory's index.html. */
    private function isPublicFile(string $path): bool
    {
        if ($this->publicDir === null || $path === '/' || str_contains($path, '..')) {
            return false;
        }
        $file = $this->publicDir . $path;

        return is_file($file) || (is_dir($file) && (is_file($file . '/index.html') || is_file($file . '/index.htm')));
    }

    /**
     * The URLs of a srcset's candidates, read as the HTML standard does: a URL runs to the next space (so a data:
     * URI's commas stay in it), a comma at its end closes the candidate, else its descriptors run to the next comma.
     *
     * @return list<string>
     */
    private static function srcset(string $srcset): array
    {
        $urls = [];
        $length = strlen($srcset);
        $at = 0;
        while ($at < $length) {
            $at += strspn($srcset, " \t\n\r\f,", $at);
            if ($at >= $length) {
                break;
            }
            $url = substr($srcset, $at, strcspn($srcset, " \t\n\r\f", $at));
            $at += strlen($url);
            if (str_ends_with($url, ',')) {
                $url = rtrim($url, ',');
            } else {
                $comma = strpos($srcset, ',', $at);
                $at = $comma === false ? $length : $comma + 1;
            }
            if ($url !== '' && !str_starts_with(strtolower($url), 'data:')) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * The targets a page names, in document order.
     *
     * @return list<string>
     */
    private function hrefs(\DOMDocument $dom): array
    {
        $hrefs = [];
        foreach ((new \DOMXPath($dom))->query('//*[@href or @src or @srcset or @poster or @content]') ?: [] as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($element->tagName);
            if ($tag === 'link') {
                $rels = preg_split('/\s+/', strtolower(trim($element->getAttribute('rel')))) ?: [];
                if (array_intersect($rels, self::LINK_RELS) !== []) {
                    $hrefs[] = $element->getAttribute('href');
                }
                continue;
            }
            if ($tag === 'meta') {
                $property = strtolower(trim($element->getAttribute('property') ?: $element->getAttribute('name')));
                if (in_array($property, ['og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image'], true)) {
                    $hrefs[] = $element->getAttribute('content');
                }
                continue;
            }
            foreach (self::ATTRIBUTES[$tag] ?? [] as $attribute) {
                if (!$element->hasAttribute($attribute)) {
                    continue;
                }
                $value = $element->getAttribute($attribute);
                if ($attribute !== 'srcset') {
                    $hrefs[] = $value;
                    continue;
                }
                array_push($hrefs, ...self::srcset($value));
            }
        }

        return $hrefs;
    }
}
