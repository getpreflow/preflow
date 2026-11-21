<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Preview;

use Preflow\Core\Context\Facet;

/**
 * The facet of a context rendered under a preview token (PreviewToken::grant(), PreviewClaims::apply()):
 * it holds the token only so the render can hand it on to its follow-up requests (component URLs,
 * hx-headers). Never the public default, so the page cache neither stores nor serves such a render.
 *
 * The token never leaves it: a dump shows it masked, json_encode() and memo keys show only
 * 'preview', and serialize() leaves it out (a restored grant has none: token() is null).
 *
 * @since 0.81.0
 */
final readonly class PreviewGrant implements Facet
{
    public function __construct(#[\SensitiveParameter] private ?string $token) {}

    /** The token to re-emit; null for a grant restored from serialize(), which re-emits nothing. */
    public function token(): ?string
    {
        return $this->token;
    }

    public function isPublicDefault(): bool
    {
        return false;
    }

    public function memoKey(): string
    {
        return 'preview';
    }

    /** @return array{} the token is never serialized */
    public function __serialize(): array
    {
        return [];
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->token = null;
    }

    /** @return array{token: ?string} masked */
    public function __debugInfo(): array
    {
        return ['token' => $this->token === null ? null : '[hidden]'];
    }
}
