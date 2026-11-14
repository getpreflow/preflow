<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Cache\Bytes;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\OpcacheStatus;
use Preflow\Folio\FolioText;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * {prefix}/cache (Settings → Cache): the caches in the CacheRegistry with their size, cleared
 * one by one or all at once, and the OPcache of the web server's PHP (which the CLI's
 * cache:clear can't reach). Every cache rebuilds itself on the next request.
 *
 * With the page cache on (preflow/core's Refresher bound), its two manual actions: refresh one
 * page by its address, and refresh everything. Both forget and queue; the rebuild runs after the
 * response (and by `preflow pages:work`). Folio only adapts the core's calls here.
 */
final class CacheController
{
    /** The caches Folio knows by key, shown under its own name (others keep the name they register with). */
    private const array LABELS = [
        'folio.extensions' => 'folio.cache.extensions',
        'blocks' => 'folio.cache.blocks',
        'data.json' => 'folio.cache.data_json',
        'blade' => 'folio.cache.blade',
        'twig' => 'folio.cache.twig',
        'assets' => 'folio.cache.assets',
        'composer' => 'folio.cache.composer',
        'boot' => 'folio.cache.boot',
        'pages' => 'folio.cache.pages',
    ];

    /** Core's cache key of the boot manifest (`preflow optimize`); clearing it is not undone by a request. */
    private const string BOOT = 'boot';

    public function __construct(
        private readonly CacheRegistry $caches,
        private readonly OpcacheStatus $opcache,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly ?\Preflow\Core\PageCache\Refresher $pages = null,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $usage = CacheUsage::of($this->caches) ?? ['rows' => [], 'entries' => 0, 'bytes' => 0];
        $total = $usage['bytes'];
        $parts = CacheUsage::segments($usage['rows'], $total);

        // Each cache's colour in the bar and in its row: its place among the largest (1 = biggest).
        $place = [];
        foreach ($parts['segments'] as $i => $segment) {
            $place[$segment['source']->key()] = $i + 1;
        }
        $largest = max([0, ...array_column($usage['rows'], 'bytes')]);
        $caches = [];
        foreach ($usage['rows'] as $row) {
            $caches[] = [
                'key' => $row['source']->key(),
                'label' => self::label($row['source']),
                'entries' => $row['entries'],
                'size' => Bytes::human($row['bytes']),
                'empty' => $row['entries'] <= 0 && $row['bytes'] <= 0, // bytes without files (empty directories) can still be cleared
                'note' => $row['note'] !== '' ? $row['note'] : null,
                'width' => $row['bytes'] > 0 ? self::percent(max($row['bytes'] / $largest * 100, 1.5)) : '0.0',
                'stack' => $place[$row['source']->key()] ?? 0,
            ];
        }
        $files = FolioText::choice('folio.cache.in_files', $usage['entries'], ['count' => (new AdminFormat())->number($usage['entries'])]);
        // Bar segments follow each other: x is where one starts, in percent of the bar.
        $stack = [];
        $names = [];
        $x = 0.0;
        foreach ($parts['segments'] as $i => $segment) {
            $label = self::label($segment['source']);
            $stack[] = ['label' => $label, 'size' => Bytes::human($segment['bytes']), 'x' => self::percent($x), 'width' => self::percent($segment['percent']), 'n' => $i + 1];
            $names[] = $label . ' ' . Bytes::human($segment['bytes']);
            $x += $segment['percent'];
        }
        if ($parts['restBytes'] > 0) {
            $stack[] = ['label' => FolioText::get('folio.cache.everything_else'), 'size' => Bytes::human($parts['restBytes']), 'x' => self::percent($x), 'width' => self::percent($parts['rest']), 'n' => 0];
            $names[] = FolioText::get('folio.cache.everything_else') . ' ' . Bytes::human($parts['restBytes']);
        }
        $opcache = $this->opcache->stats();

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/cache.twig', [
            'prefix' => $this->prefix,
            'caches' => $caches,
            'total' => ['size' => Bytes::human($total), 'files' => $files, 'empty' => $total <= 0],
            'stack' => $stack,
            'stack_label' => implode(', ', $names),
            'opcache' => $opcache === null ? null : [
                'memory_used' => Bytes::human($opcache['memory_used']),
                'memory_free' => Bytes::human($opcache['memory_free']),
                'scripts' => $opcache['scripts'],
                'hit_rate' => $opcache['hit_rate'],
            ],
            'csrf' => $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '',
            'pages' => $this->pages === null ? null : ['queued' => $this->queued()],
        ]));
    }

    /** A share in percent with one decimal and a dot, whatever the interface language (an SVG bar's x and width attributes: the CSP refuses style attributes). */
    private static function percent(float $value): string
    {
        return number_format(min(100.0, max(0.0, $value)), 1, '.', '');
    }

    /** POST {prefix}/cache/pages/refresh: url=<a path or an address of the site>. */
    public function refreshPage(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->pages === null) {
            return NotFound::response($request, FolioText::get('folio.cache.unknown'));
        }
        $body = (array) $request->getParsedBody();
        $url = is_string($body['url'] ?? null) ? trim($body['url']) : '';
        // The field shows a "/" as its prefix, so a plain path (about, about/team) arrives without it. Anything
        // else (a host, a query, a scheme, a leading slash) goes to the Refresher as typed, which refuses what isn't a page of the site.
        if (preg_match('#^[A-Za-z0-9][^./:?\\\\\#]*(/.*)?$#', $url) === 1) {
            $url = '/' . $url;
        }
        try {
            $forgotten = $this->pages->refresh($url);
            self::flash($request, 'success', FolioText::get('folio.cache.refreshed', ['url' => $url, 'count' => $forgotten]));
        } catch (\InvalidArgumentException) {
            self::flash($request, 'error', FolioText::get('folio.cache.refresh_bad_url', ['url' => $url]));
        } catch (\Throwable $e) {
            self::flash($request, 'error', FolioText::get('folio.cache.refresh_failed', ['error' => $e->getMessage()]));
        }

        return $this->back();
    }

    /** POST {prefix}/cache/pages/refresh-all */
    public function refreshAll(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->pages === null) {
            return NotFound::response($request, FolioText::get('folio.cache.unknown'));
        }
        try {
            self::flash($request, 'success', FolioText::get('folio.cache.refreshed_all', ['count' => $this->pages->refreshAll()]));
        } catch (\Throwable $e) {
            self::flash($request, 'error', FolioText::get('folio.cache.refresh_failed', ['error' => $e->getMessage()]));
        }

        return $this->back();
    }

    private function queued(): int
    {
        try {
            return $this->pages?->queued() ?? 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** POST {prefix}/cache/clear: key=<key> for one cache, all=1 for every registered one. */
    public function clear(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (($body['all'] ?? '') === '1') {
            $entries = 0;
            $bytes = 0;
            $boot = false;
            foreach ($this->caches->clearAll() as $key => $cleared) {
                $entries += $cleared->entries;
                $bytes += $cleared->bytes;
                $boot = $boot || ($key === self::BOOT && $cleared->entries > 0);
            }
            self::flash($request, 'success', FolioText::get('folio.cache.cleared_all', ['count' => $entries, 'size' => Bytes::human($bytes)]) . self::bootNote($boot));

            return $this->back();
        }

        $source = is_string($body['key'] ?? null) ? $this->caches->get($body['key']) : null;
        if ($source === null) {
            return NotFound::response($request, FolioText::get('folio.cache.unknown'));
        }
        $cleared = $source->clear();
        self::flash($request, 'success', FolioText::get('folio.cache.cleared', ['label' => self::label($source), 'count' => $cleared->entries, 'size' => Bytes::human($cleared->bytes)])
            . self::bootNote($source->key() === self::BOOT && $cleared->entries > 0));

        return $this->back();
    }

    /** POST {prefix}/cache/opcache-reset */
    public function opcacheReset(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->opcache->enabled()) {
            self::flash($request, 'error', FolioText::get('folio.cache.opcache_off'));
        } elseif ($this->opcache->reset()) {
            self::flash($request, 'success', FolioText::get('folio.cache.opcache_reset'));
        } else {
            self::flash($request, 'error', FolioText::get('folio.cache.opcache_reset_failed'));
        }

        return $this->back();
    }

    /** The boot manifest is not rebuilt by a request: how it comes back, when it was removed. */
    private static function bootNote(bool $removed): string
    {
        return $removed ? ' ' . FolioText::get('folio.cache.boot_cleared') : '';
    }

    /** A cache's name in the interface language: Folio's text for the caches it knows, else the name it registers with (also the dashboard's cache line). */
    public static function label(\Preflow\Core\Cache\CacheSource $source): string
    {
        $key = self::LABELS[$source->key()] ?? null;

        return $key !== null ? FolioText::get($key) : $source->label();
    }

    private function back(): ResponseInterface
    {
        return new Response(302, ['Location' => $this->prefix . '/cache']);
    }

    private static function flash(ServerRequestInterface $request, string $type, string $message): void
    {
        $session = $request->getAttribute(\Preflow\Core\Http\Session\SessionInterface::class);
        if ($session instanceof \Preflow\Core\Http\Session\SessionInterface) {
            $session->flash($type, $message);
        }
    }
}
