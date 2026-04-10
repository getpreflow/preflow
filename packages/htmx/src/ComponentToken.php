<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Core\Exceptions\SecurityException;

class ComponentToken
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $algorithm = 'sha256',
    ) {}

    /**
     * @param array<string, mixed> $props
     */
    public function encode(
        string $componentClass,
        array $props = [],
        string $action = 'render',
    ): string {
        $payload = json_encode([
            'c' => $componentClass,
            'p' => $props,
            'a' => $action,
            't' => $this->currentTime(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $signature = hash_hmac($this->algorithm, $payload, $this->secretKey);

        return sodium_bin2base64(
            $payload . '.' . $signature,
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
        );
    }

    public function decode(string $token, ?int $maxAge = null): TokenPayload
    {
        try {
            $decoded = sodium_base642bin($token, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw new SecurityException('Invalid component token format.');
        }

        $lastDot = strrpos($decoded, '.');
        if ($lastDot === false) {
            throw new SecurityException('Invalid component token structure.');
        }

        $payload = substr($decoded, 0, $lastDot);
        $signature = substr($decoded, $lastDot + 1);

        $expected = hash_hmac($this->algorithm, $payload, $this->secretKey);

        if (!hash_equals($expected, $signature)) {
            throw new SecurityException('Invalid component token signature.');
        }

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if ($maxAge !== null && (time() - ($data['t'] ?? 0)) > $maxAge) {
            throw new SecurityException('Component token expired.');
        }

        return new TokenPayload(
            componentClass: $data['c'],
            props: $data['p'] ?? [],
            action: $data['a'] ?? 'render',
            timestamp: $data['t'] ?? 0,
        );
    }

    protected function currentTime(): int
    {
        return time();
    }
}
