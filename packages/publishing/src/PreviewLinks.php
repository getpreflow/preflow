<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Core\Context\Contexts;

/**
 * Signed share-preview tokens: base64url(json [type, id, nonce, expires]) "."
 * base64url(HMAC-SHA256). The nonce is the record's _share_token, so rotating
 * it revokes every link. Disabled without a key that can sign (AppKey::canSign(): at
 * least 32 bytes, not a public example; since 0.81.0): links signed with an empty, short
 * or well-known key could be forged.
 */
final class PreviewLinks
{
    public const string DEFAULT_KEY = 'preflow-default-key-change-me!!';

    /**
     * The time links expire against. By default (since 0.81) the current render's clock
     * (Contexts::clock()), and the system clock outside every render.
     *
     * @var \Closure(): int
     */
    private readonly \Closure $now;

    public function __construct(
        private readonly string $key,
        private readonly int $ttl = 604800,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => Contexts::clock()->now()->getTimestamp();
    }

    public function enabled(): bool
    {
        return \Preflow\Core\Security\AppKey::canSign($this->key);
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function create(string $type, string $id, string $nonce): string
    {
        $payload = self::encode((string) json_encode([$type, $id, $nonce, ($this->now)() + $this->ttl]));

        return $payload . '.' . self::encode($this->sign($payload));
    }

    public function verify(string $token): ?PreviewTarget
    {
        if (!$this->enabled() || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $signature] = explode('.', $token);
        $expected = self::encode($this->sign($payload));
        if ($payload === '' || !hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode((string) self::decode($payload), true);
        if (!is_array($data) || count($data) !== 4 || !is_string($data[0]) || !is_string($data[1]) || !is_string($data[2]) || !is_int($data[3])) {
            return null;
        }
        if ($data[3] < ($this->now)()) {
            return null;
        }

        return new PreviewTarget($data[0], $data[1], $data[2], $data[3]);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', 'folio-preview|' . $payload, $this->key, true);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): ?string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
