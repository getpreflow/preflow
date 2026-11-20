<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Preview;

use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;

/**
 * What a verified preview token grants (PreviewToken::verify()): the reading mode of one staff
 * member's preview, for its follow-up requests. apply() turns a follow-up request's context into
 * the preview's.
 *
 * It keeps the token itself only to re-emit it (the PreviewGrant apply() adds); a dump masks it
 * and serialize() leaves it out, so restored claims grant a context that re-emits nothing.
 *
 * @since 0.81.0
 */
final readonly class PreviewClaims
{
    /**
     * @internal built by PreviewToken::verify() from a token it checked
     *
     * @param RoleView|null $role the role viewed as; null: an anonymous visitor
     * @param string $locale the content locale
     * @param bool $workingCopies reads working copies (else the published state)
     * @param string $issuer the auth id of the staff member it was issued to
     * @param list<string> $requires the permissions the issuer was checked for, re-checked at each use
     * @param int $expires Unix time it expires at
     * @param string $jti its id (32 hex characters), for logs only
     * @param string|null $token the token these claims came from, to re-emit
     */
    public function __construct(
        public ?RoleView $role,
        public string $locale,
        public bool $workingCopies,
        public string $issuer,
        public array $requires,
        public int $expires,
        public string $jti,
        #[\SensitiveParameter] private ?string $token = null,
    ) {}

    /**
     * $ctx as the preview: an anonymous visitor (or the role viewed as) on the public surface, in
     * the token's locale and read mode, purpose Fragment, data writes refused, the outbox captured,
     * and a PreviewGrant carrying the token on. A role view starts here, so start the result with
     * Contexts::run() (Application::render()), never narrow().
     */
    public function apply(RenderContext $ctx): RenderContext
    {
        $ctx = $ctx->public();
        $ctx = $this->role !== null ? $ctx->asRole($this->role) : $ctx->asVisitor();
        $ctx = $ctx->in($this->locale);
        $ctx = $this->workingCopies ? $ctx->workingCopies() : $ctx->published();

        return $ctx->because(Purpose::Fragment)->refusingWrites()->capturing()->with(new PreviewGrant($this->token));
    }

    /** @return array<string, mixed> the claims without the token */
    public function __serialize(): array
    {
        return [
            'role' => $this->role, 'locale' => $this->locale, 'workingCopies' => $this->workingCopies, 'issuer' => $this->issuer,
            'requires' => $this->requires, 'expires' => $this->expires, 'jti' => $this->jti,
        ];
    }

    /** @param array<string, mixed> $data what __serialize() gave */
    public function __unserialize(array $data): void
    {
        $this->role = $data['role'];
        $this->locale = $data['locale'];
        $this->workingCopies = $data['workingCopies'];
        $this->issuer = $data['issuer'];
        $this->requires = $data['requires'];
        $this->expires = $data['expires'];
        $this->jti = $data['jti'];
        $this->token = null;
    }

    /** @return array<string, mixed> what var_dump and print_r show: the claims, the token masked */
    public function __debugInfo(): array
    {
        return $this->__serialize() + ['token' => $this->token === null ? null : '[hidden]'];
    }
}
