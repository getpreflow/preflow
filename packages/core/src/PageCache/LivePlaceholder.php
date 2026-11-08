<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * The signed placeholder a live part leaves in a stored page:
 *
 *     <!--pc:l:{payload}:{sig}-->
 *
 * - {payload} is the part's recipe, JSON in base64url: {"k": kind, ...}. A Twig block is
 *   {"k":"twig","t":template,"b":block,"w":with values}, a Blade block
 *   {"k":"blade","f":source file,"i":index,"h":body hash,"w":with values}, a live component
 *   {"k":"component","c":class,"p":props}.
 * - {sig} is the first 32 hex characters of HMAC-SHA256(app.key, "pc:l:{payload}").
 *
 * Text a visitor wrote can't name a template or component to render: a marker whose signature
 * doesn't verify (no key, another key, an edited payload) is not a placeholder and stays in
 * the page as the HTML comment it is.
 */
final class LivePlaceholder
{
    public const string PREFIX = '<!--pc:l:';

    private const string PATTERN = '/<!--pc:l:([A-Za-z0-9_-]+):([0-9a-f]{32})-->/';

    /** Larger recipes are not read (a payload from user text is not decoded at any size). */
    private const int MAX_PAYLOAD = 65536;

    public function __construct(private readonly string $secret) {}

    /**
     * The placeholder of the part $kind with $recipe (scalars and arrays only).
     *
     * @param array<string, mixed> $recipe
     * @throws \InvalidArgumentException when a value is not a scalar, null or array
     */
    public function make(string $kind, array $recipe): string
    {
        self::assertPlain($recipe);
        $json = json_encode(['k' => $kind] + $recipe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return self::PREFIX . $payload . ':' . $this->sign($payload) . '-->';
    }

    /** Whether $body may hold a placeholder (a cheap check before find()). */
    public static function in(string $body): bool
    {
        return str_contains($body, self::PREFIX);
    }

    /**
     * The placeholders in $body whose signature verifies, each once.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}> placeholder => [kind, recipe]
     */
    public function find(string $body): array
    {
        if (!self::in($body) || preg_match_all(self::PATTERN, $body, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }
        $found = [];
        foreach ($matches as [$marker, $payload, $sig]) {
            if (isset($found[$marker]) || strlen($payload) > self::MAX_PAYLOAD || !hash_equals($this->sign($payload), $sig)) {
                continue;
            }
            $json = base64_decode(strtr($payload, '-_', '+/'), true);
            $recipe = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($recipe) || !isset($recipe['k']) || !is_string($recipe['k'])) {
                continue;
            }
            $kind = $recipe['k'];
            unset($recipe['k']);
            $found[$marker] = [$kind, $recipe];
        }

        return $found;
    }

    /**
     * Throws unless $values holds only scalars, null and arrays of them: a live part's inputs
     * go into the stored page as JSON and come back as the same values on every visit.
     *
     * @param array<mixed> $values
     * @throws \InvalidArgumentException
     */
    public static function assertPlain(array $values, string $path = ''): void
    {
        foreach ($values as $key => $value) {
            $at = $path === '' ? (string) $key : $path . '.' . $key;
            if (is_array($value)) {
                self::assertPlain($value, $at);
            } elseif ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException(sprintf(
                    "A live part's values are scalars and arrays only: '%s' is %s. Pass what identifies it (an id, a number) and look the rest up inside.",
                    $at,
                    get_debug_type($value),
                ));
            }
        }
    }

    private function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', 'pc:l:' . $payload, $this->secret), 0, 32);
    }
}
