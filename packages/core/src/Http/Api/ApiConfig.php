<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Api;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What config/api.php says: which paths are the API, and the CORS policy for them.
 * CORS is off while no origin is listed.
 */
final readonly class ApiConfig
{
    /** @var list<string> */
    public array $prefixes;

    /**
     * @param list<string> $prefixes     path prefixes of the API; a prefix matches whole segments
     * @param list<string> $corsOrigins  allowed origins, or ['*']; empty turns CORS off
     * @param list<string> $corsMethods
     * @param list<string> $corsHeaders  request headers a browser may send
     * @param list<string> $corsExpose   response headers a browser may read
     */
    public function __construct(
        array $prefixes = ['/api'],
        public array $corsOrigins = [],
        public array $corsMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        public array $corsHeaders = ['Authorization', 'Content-Type', 'Accept'],
        public array $corsExpose = [],
        public int $corsMaxAge = 600,
        public bool $corsCredentials = false,
    ) {
        $clean = [];
        foreach ($prefixes as $prefix) {
            $prefix = rtrim((string) $prefix, '/');
            // '' and '/' would make the whole site an API.
            if ($prefix !== '') {
                $clean[] = $prefix;
            }
        }
        $this->prefixes = $clean;
    }

    /** @param ?array<string, mixed> $config the shape of config/api.php; null gives the defaults */
    public static function fromArray(?array $config): self
    {
        $config ??= [];
        $cors = (array) ($config['cors'] ?? []);
        $defaults = new self();

        $origins = array_values(array_map('strval', (array) ($cors['origins'] ?? [])));
        $credentials = (bool) ($cors['credentials'] ?? false);

        // Browsers refuse a wildcard origin together with credentials, so this can never work.
        if ($credentials && in_array('*', $origins, true)) {
            throw new \InvalidArgumentException(
                'config/api.php: cors.credentials cannot be true with cors.origins ["*"]; list the origins instead.',
            );
        }

        return new self(
            prefixes: array_values(array_map('strval', (array) ($config['prefixes'] ?? $defaults->prefixes))),
            corsOrigins: $origins,
            corsMethods: array_values(array_map('strval', (array) ($cors['methods'] ?? $defaults->corsMethods))),
            corsHeaders: array_values(array_map('strval', (array) ($cors['headers'] ?? $defaults->corsHeaders))),
            corsExpose: array_values(array_map('strval', (array) ($cors['expose'] ?? $defaults->corsExpose))),
            corsMaxAge: (int) ($cors['max_age'] ?? $defaults->corsMaxAge),
            corsCredentials: $credentials,
        );
    }

    public function isApi(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        foreach ($this->prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * An API path, or a client that asks for JSON. Any mention of text/html means a browser,
     * so `application/json, text/html` and the usual browser Accept stay HTML.
     */
    public function wantsJson(ServerRequestInterface $request): bool
    {
        if ($this->isApi($request)) {
            return true;
        }

        $accept = strtolower($request->getHeaderLine('Accept'));
        if ($accept === '' || str_contains($accept, 'text/html')) {
            return false;
        }

        return (bool) preg_match('#(?:^|[\s,])application/(?:[\w.+-]+\+)?json(?:\s*[;,]|\s*$)#', $accept);
    }
}
