<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sends a strict Content-Security-Policy carrying the request's asset nonce,
 * plus a few standard security headers. Configured by config/security.php,
 * merged per CSP directive and per header over defaults().
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @var array{csp: array<string, list<string>>, report_only: bool, headers: array<string, string|null>, hsts: bool} */
    private readonly array $config;

    /**
     * @param \Closure(): ?string  $nonce      the request nonce (AssetCollector::getNonce)
     * @param array<string, mixed> $config     contents of config/security.php
     * @param bool                 $reportOnly force Content-Security-Policy-Report-Only (debug mode)
     */
    public function __construct(
        private readonly \Closure $nonce,
        array $config = [],
        private readonly bool $reportOnly = false,
    ) {
        $defaults = self::defaults();
        $this->config = [
            'csp' => array_merge($defaults['csp'], (array) ($config['csp'] ?? [])),
            'report_only' => (bool) ($config['report_only'] ?? $defaults['report_only']),
            'headers' => array_merge($defaults['headers'], (array) ($config['headers'] ?? [])),
            'hsts' => (bool) ($config['hsts'] ?? $defaults['hsts']),
        ];
    }

    /** @return array{csp: array<string, list<string>>, report_only: bool, headers: array<string, string>, hsts: bool} */
    public static function defaults(): array
    {
        return [
            'csp' => [
                'default-src' => ["'self'"],
                'script-src' => ["'self'"],
                'style-src' => ["'self'"],
                'img-src' => ["'self'", 'data:'],
                'font-src' => ["'self'"],
                'media-src' => ["'self'"],
                'connect-src' => ["'self'"],
                'frame-src' => ["'self'"],
                'frame-ancestors' => ["'self'"],
                'form-action' => ["'self'"],
                'base-uri' => ["'self'"],
                'object-src' => ["'none'"],
            ],
            'report_only' => false,
            'headers' => [
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
                'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            ],
            'hsts' => true,
        ];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $cspHeader = ($this->reportOnly || $this->config['report_only'])
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        if (!$response->hasHeader('Content-Security-Policy') && !$response->hasHeader('Content-Security-Policy-Report-Only')) {
            $response = $response->withHeader($cspHeader, $this->policy());
        }

        foreach ($this->config['headers'] as $name => $value) {
            if ($value !== null && !$response->hasHeader($name)) {
                $response = $response->withHeader($name, (string) $value);
            }
        }

        if ($this->config['hsts'] && $this->isHttps($request) && !$response->hasHeader('Strict-Transport-Security')) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(): string
    {
        $nonce = ($this->nonce)();
        $parts = [];

        foreach ($this->config['csp'] as $directive => $sources) {
            $sources = array_values((array) $sources);
            if ($nonce !== null && $nonce !== '' && in_array($directive, ['script-src', 'style-src'], true)) {
                $sources[] = "'nonce-{$nonce}'";
            }
            $parts[] = trim($directive . ' ' . implode(' ', $sources));
        }

        return implode('; ', $parts);
    }

    private function isHttps(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getScheme() === 'https'
            || strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }
}
