<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * Values that are different on every request but sit in pages that are otherwise the same for
 * everyone: the CSP nonce, the CSRF token, a site's own form token. A page is stored with each
 * of them replaced by a signed placeholder, and every hit fills in the values of its own
 * request, so a cached page never hands one visitor's nonce or token to another.
 *
 * Built in (Application): `nonce`, the request's CSP nonce, and `csrf`, the CSRF token pages
 * print without a session (a page that started a session is never stored). A site registers
 * its own in a provider's boot():
 *
 *     $container->get(PerRequestValues::class)->register('form_token', fn (): string => FormToken::value());
 *
 * A value closure returns the value of the current request: a string, a list of strings (all
 * are replaced when a page is stored; the first one is filled in), or null when the request
 * has none. Values shorter than MIN_LENGTH are never replaced (a short value would match
 * ordinary text). A value is replaced as it is and in its JSON-escaped form ("/" as "\/").
 *
 * The placeholder is `<!--pc:v:{name}:{sig}-->` (`<!--pc:v:{name}.j:{sig}-->` for the JSON
 * form), signed with the app key, so text a visitor wrote can't name a value it should not get.
 */
final class PerRequestValues
{
    public const int MIN_LENGTH = 8;

    /** @var array<string, \Closure(): (string|list<string>|null)> */
    private array $values = [];

    /** @var array<string, string> name => placeholder, memoised */
    private array $placeholders = [];

    public function __construct(private readonly string $secret) {}

    /**
     * Whether the placeholders are signed with a secret: not with an empty app.key or the public
     * default one, with which anyone could compute a signature (and get the live nonce filled
     * into text they put in a page). Pages are not stored then.
     */
    public function signed(): bool
    {
        return $this->secret !== '' && $this->secret !== \Preflow\Core\Http\Csrf\StatelessCsrfToken::DEFAULT_KEY;
    }

    /**
     * Registers (or replaces) a value. $name: letters, digits, "_" and "-".
     *
     * @param \Closure(): (string|list<string>|null) $value
     */
    public function register(string $name, \Closure $value): void
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException("A per-request value name is letters, digits, _ and - only: '{$name}'.");
        }
        $this->values[$name] = $value;
    }

    /** @return list<string> the registered names */
    public function names(): array
    {
        return array_keys($this->values);
    }

    /** $body with this request's values replaced by their placeholders (what is stored). */
    public function toPlaceholders(string $body): string
    {
        $map = [];
        foreach ($this->values as $name => $value) {
            foreach ($this->current($value, all: true) as $actual) {
                if (strlen($actual) < self::MIN_LENGTH) {
                    continue;
                }
                $map[$actual] = $this->placeholder($name);
                $json = str_replace('/', '\/', $actual);
                if ($json !== $actual) {
                    $map[$json] = $this->placeholder($name . '.j');
                }
            }
        }

        // strtr replaces the longest match first and never replaces inside a replacement.
        return $map === [] ? $body : strtr($body, $map);
    }

    /** A stored $body with the placeholders filled in with this request's values. */
    public function fill(string $body): string
    {
        if (!str_contains($body, '<!--pc:v:')) {
            return $body;
        }
        $map = [];
        foreach ($this->values as $name => $value) {
            $raw = $this->placeholder($name);
            $json = $this->placeholder($name . '.j');
            $hasRaw = str_contains($body, $raw);
            $hasJson = str_contains($body, $json);
            if (!$hasRaw && !$hasJson) {
                continue;
            }
            $actual = $this->current($value, all: false)[0] ?? '';
            $map[$raw] = $actual;
            $map[$json] = str_replace('/', '\/', $actual);
        }

        return $map === [] ? $body : strtr($body, $map);
    }

    private function placeholder(string $name): string
    {
        return $this->placeholders[$name] ??= '<!--pc:v:' . $name . ':'
            . substr(hash_hmac('sha256', 'pc:v:' . $name, $this->secret), 0, 16) . '-->';
    }

    /**
     * @param \Closure(): (string|list<string>|null) $value
     * @return list<string>
     */
    private function current(\Closure $value, bool $all): array
    {
        $result = $value();
        if ($result === null || $result === '') {
            return [];
        }
        if (is_string($result)) {
            return [$result];
        }
        $list = [];
        foreach ((array) $result as $item) {
            if (is_string($item) && $item !== '') {
                $list[] = $item;
                if (!$all) {
                    break;
                }
            }
        }

        return $list;
    }
}
