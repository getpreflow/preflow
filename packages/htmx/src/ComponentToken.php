<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Components\Component;
use Preflow\Components\ParentRef;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Exceptions\SecurityException;

/**
 * Signed component tokens (class, props, action, issue time). One instance issues all its
 * tokens at one time, the time of its first token: a page rendered twice in one request (the
 * page cache's verify) or again after the response (a rebuild) prints the same tokens, so it is
 * the same page for every visitor. The instance lives for one request (or one command), so the
 * age check of decode() is off by at most that long.
 */
class ComponentToken
{
    private ?int $issuedAt = null;

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
        return $this->sign([
            'c' => $componentClass,
            'p' => $props,
            'a' => $action,
            't' => $this->issuedAt ??= $this->currentTime(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function sign(array $data): string
    {
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $signature = hash_hmac($this->algorithm, $payload, $this->secretKey);

        return sodium_bin2base64(
            $payload . '.' . $signature,
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
        );
    }

    /**
     * The token of $action on $component. A component with #[State] carries only its `key` (its
     * identity): the rest of its props were used by mount() and live on as its state, which the
     * page carries beside the token, and the token says so (TokenPayload::$stateCarried): the
     * endpoint then refuses a request without that state instead of mounting from no props. A
     * component without state carries all its props, as encode(). Every token made here is for a
     * 0.86 call (TokenPayload::$neutralCall): only such a request's values and region are read.
     *
     * With $parent (the component whose template rendered $component, or its class and key as a
     * ParentRef, since 0.87), the token also names the parent's class and key
     * (TokenPayload::$parentClass, $parentKey): when the endpoint renders $component alone, its
     * template can still call the parent (`component: 'parent'`).
     *
     * @since 0.86.0
     */
    public function encodeFor(Component $component, string $action = 'render', Component|ParentRef|null $parent = null): string
    {
        $props = $component->getProps();
        $data = ['c' => $component::class, 'p' => $props, 'a' => $action, 't' => $this->issuedAt ??= $this->currentTime()];
        if ($component->hasState()) {
            $data['p'] = self::keyOnly($props);
            $data['s'] = 1;
        }
        $data['n'] = 1;
        if ($parent !== null) {
            $parent = $parent instanceof Component ? ParentRef::of($parent) : $parent;
            $data['pc'] = $parent->class;
            if ($parent->key !== null) {
                $data['pk'] = $parent->key;
            }
        }

        return $this->sign($data);
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed> the `key` of $props alone, or nothing for none
     */
    private static function keyOnly(array $props): array
    {
        $key = $props['key'] ?? null;

        return $key === null || $key === '' ? [] : ['key' => $key];
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

        if ($maxAge !== null && ($this->currentTime() - ($data['t'] ?? 0)) > $maxAge) {
            throw new SecurityException('Component token expired.');
        }

        return new TokenPayload(
            componentClass: $data['c'],
            props: $data['p'] ?? [],
            action: $data['a'] ?? 'render',
            timestamp: $data['t'] ?? 0,
            stateCarried: ($data['s'] ?? 0) === 1,
            neutralCall: ($data['n'] ?? 0) === 1,
            parentClass: isset($data['pc']) && is_string($data['pc']) ? $data['pc'] : null,
            parentKey: isset($data['pk']) && (is_string($data['pk']) || is_int($data['pk'])) ? $data['pk'] : null,
        );
    }

    /** "Now" for issuing and for the age check: the current render's clock, the system clock outside every render. */
    protected function currentTime(): int
    {
        return Contexts::clock()->now()->getTimestamp();
    }
}
