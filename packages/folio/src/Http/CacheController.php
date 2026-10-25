<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Cache\Bytes;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\OpcacheStatus;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * {prefix}/cache (Settings → Cache): the caches in the CacheRegistry with their size, cleared
 * one by one or all at once, and the OPcache of the web server's PHP (which the CLI's
 * cache:clear can't reach). Every cache rebuilds itself on the next request.
 */
final class CacheController
{
    public function __construct(
        private readonly CacheRegistry $caches,
        private readonly OpcacheStatus $opcache,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $caches = [];
        foreach ($this->caches->all() as $source) {
            $stats = $source->stats();
            $caches[] = [
                'key' => $source->key(),
                'label' => $source->label(),
                'entries' => $stats->entries,
                'size' => Bytes::human($stats->bytes),
                'note' => $stats->note,
            ];
        }
        $opcache = $this->opcache->stats();

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/cache.twig', [
            'prefix' => $this->prefix,
            'caches' => $caches,
            'opcache' => $opcache === null ? null : [
                'memory_used' => Bytes::human($opcache['memory_used']),
                'memory_free' => Bytes::human($opcache['memory_free']),
                'scripts' => $opcache['scripts'],
                'hit_rate' => $opcache['hit_rate'],
            ],
            'csrf' => $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '',
        ]));
    }

    /** POST {prefix}/cache/clear: key=<key> for one cache, all=1 for every registered one. */
    public function clear(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (($body['all'] ?? '') === '1') {
            $entries = 0;
            $bytes = 0;
            foreach ($this->caches->clearAll() as $cleared) {
                $entries += $cleared->entries;
                $bytes += $cleared->bytes;
            }
            self::flash($request, 'success', "Cleared all caches: {$entries} file(s), " . Bytes::human($bytes) . '.');

            return $this->back();
        }

        $source = is_string($body['key'] ?? null) ? $this->caches->get($body['key']) : null;
        if ($source === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8'], 'Unknown cache');
        }
        $cleared = $source->clear();
        self::flash($request, 'success', "{$source->label()}: {$cleared->entries} file(s), " . Bytes::human($cleared->bytes) . ' cleared.');

        return $this->back();
    }

    /** POST {prefix}/cache/opcache-reset */
    public function opcacheReset(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->opcache->enabled() && $this->opcache->reset()) {
            self::flash($request, 'success', 'OPcache reset.');
        } else {
            self::flash($request, 'error', 'OPcache is off.');
        }

        return $this->back();
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
