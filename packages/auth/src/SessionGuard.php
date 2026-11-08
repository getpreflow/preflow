<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Auth\Remember\RememberMe;
use Preflow\Core\Http\CookieQueue;
use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SessionGuard implements GuardInterface
{
    /**
     * Set while someone is signed in (at login, also from a remember-me cookie; cleared at
     * logout), so a page cache can tell signed-in visitors without opening their session. It
     * carries no identity. Preflow\Core\PageCache\PageCacheConfig::SIGNED_IN_MARKER is the same name.
     */
    public const string SIGNED_IN_COOKIE = 'preflow_auth';

    /**
     * @param string       $sessionKey where this guard keeps its user id (one key per guard)
     * @param list<string> $otherKeys  other guards' keys: logging out keeps the session while one is set
     * @param string       $name       this guard's name (the remember rows are per guard)
     * @param array{cookie?: string, days?: int, path?: string, secure?: bool} $remember
     *                                 remember-me cookie; empty (or no $rememberMe) means none
     * @param CookieQueue  $cookies    where its Set-Cookie values wait for the response (shared with the Kernel)
     * @param array{lifetime?: int, domain?: string, secure?: bool} $marker the session cookie's lifetime,
     *                                 domain and Secure flag, which the signed-in marker follows
     */
    public function __construct(
        private readonly UserProviderInterface $provider,
        private readonly PasswordHasherInterface $hasher,
        private readonly string $sessionKey = '_auth_user_id',
        private readonly array $otherKeys = [],
        private readonly ?RememberMe $rememberMe = null,
        private readonly string $name = 'session',
        private readonly array $remember = [],
        private readonly CookieQueue $cookies = new CookieQueue(),
        private readonly array $marker = [],
    ) {}

    public function user(ServerRequestInterface $request): ?Authenticatable
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session === null) {
            return null;
        }

        $userId = $session->get($this->sessionKey);

        if ($userId === null) {
            return null;
        }

        $user = $this->provider->findById($userId);
        // The user's sessions were ended (password changed elsewhere): this one signs out too.
        if ($user instanceof HasSessionEpoch && (string) $session->get($this->epochKey(), '0') !== $user->getSessionEpoch()) {
            $session->remove($this->sessionKey);
            $session->remove($this->epochKey());

            return null;
        }

        // Signed in without the marker (a session from before it existed, one that outlived
        // it): set it again, so a page cache sends this visitor live pages. Once per response.
        if ($user !== null && ($request->getCookieParams()[self::SIGNED_IN_COOKIE] ?? '') === ''
            && !$this->cookies->has(self::SIGNED_IN_COOKIE)) {
            $this->queueMarker(true, $this->hasRememberCookie($request));
        }

        return $user;
    }

    public function validate(array $credentials): bool
    {
        $user = $this->provider->findByCredentials($credentials);

        if ($user === null) {
            return false;
        }

        $password = $credentials['password'] ?? '';

        return $this->hasher->verify($password, $user->getPasswordHash());
    }

    /** $remember also queues a remember-me cookie, when this guard has one configured. */
    public function login(Authenticatable $user, ServerRequestInterface $request, bool $remember = false): void
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session === null) {
            return;
        }

        $session->regenerate();
        $session->set($this->sessionKey, $user->getAuthId());
        if ($user instanceof HasSessionEpoch) {
            $session->set($this->epochKey(), $user->getSessionEpoch());
        } else {
            $session->remove($this->epochKey());
        }

        $remembered = $remember && $this->remembers();
        if ($remembered) {
            $this->queueCookie($this->rememberMe->issue($this->name, (string) $user->getAuthId(), $this->days() * 86400));
        }
        $this->queueMarker(true, $remembered || $this->hasRememberCookie($request));
    }

    /**
     * Signs in the user of the request's remember-me cookie when the session has
     * nobody for this guard, queueing the rotated cookie (or a clearing one when
     * the cookie is no good). Only where a session exists: elsewhere nothing happens.
     */
    public function restore(ServerRequestInterface $request): ?Authenticatable
    {
        $session = $request->getAttribute(SessionInterface::class);
        if (!$this->remembers() || $session === null || $session->get($this->sessionKey) !== null) {
            return null;
        }
        $cookie = $request->getCookieParams()[$this->cookieName()] ?? null;
        if (!is_string($cookie) || $cookie === '') {
            return null;
        }

        $resolved = $this->rememberMe->resolve($this->name, $cookie);
        $user = $resolved === null ? null : $this->provider->findById($resolved['userId']);
        if ($user === null) {
            if ($resolved !== null) {
                $this->rememberMe->forget($this->name, $cookie);
            }
            $this->queueCookie(null);

            return null;
        }

        $this->login($user, $request);
        // No new value (previous validator within the grace, a lost rotation): the browser keeps its cookie.
        if ($resolved['cookie'] !== null) {
            $this->queueCookie($resolved['cookie']);
        }

        return $user;
    }

    /**
     * The queued Set-Cookie values, emptying the queue.
     *
     * @return list<string>
     */
    public function pullCookies(): array
    {
        return $this->cookies->pull();
    }

    public function logout(ServerRequestInterface $request): void
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session === null) {
            return;
        }

        if ($this->remembers()) {
            $cookie = $request->getCookieParams()[$this->cookieName()] ?? null;
            if (is_string($cookie) && $cookie !== '') {
                $this->rememberMe->forget($this->name, $cookie);
            }
            $this->queueCookie(null);
        }

        // Only this guard's user leaves; a new id either way (fixation). The whole
        // session ends when no other guard still has someone signed in.
        $session->remove($this->sessionKey);
        $session->remove($this->epochKey());
        foreach ($this->otherKeys as $key) {
            if ($session->get($key) !== null) {
                $session->regenerate();
                return;
            }
        }
        $session->invalidate();
        $this->queueMarker(false, false);
    }

    /** Where the session keeps the user's epoch at sign-in ({@see HasSessionEpoch}). */
    private function epochKey(): string
    {
        return $this->sessionKey . ':epoch';
    }

    /** Whether this guard has a remember-me cookie configured (a login form offers the checkbox then). */
    public function remembers(): bool
    {
        return $this->rememberMe !== null && $this->remember !== [];
    }

    private function cookieName(): string
    {
        return (string) ($this->remember['cookie'] ?? 'remember_' . $this->name);
    }

    private function days(): int
    {
        return (int) ($this->remember['days'] ?? 30);
    }

    /** Whether the request carries this guard's remember-me cookie (a login from it, restore()). */
    private function hasRememberCookie(ServerRequestInterface $request): bool
    {
        if (!$this->remembers()) {
            return false;
        }
        $cookie = $request->getCookieParams()[$this->cookieName()] ?? null;

        return is_string($cookie) && $cookie !== '';
    }

    /**
     * Queues the signed-in marker (SIGNED_IN_COOKIE): for as long as the remember-me cookie when
     * $lasting, else as long as the session cookie (its lifetime, or the browser session), on the
     * session cookie's domain and with its Secure flag; or a clearing one when $set is false.
     */
    private function queueMarker(bool $set, bool $lasting): void
    {
        $lifetime = (int) ($this->marker['lifetime'] ?? 0);
        $maxAge = match (true) {
            !$set => 0,
            $lasting => max($this->days() * 86400, $lifetime),
            $lifetime > 0 => $lifetime,
            default => null, // the browser session, as the session cookie
        };
        $domain = (string) ($this->marker['domain'] ?? '');
        $this->cookies->queue(self::SIGNED_IN_COOKIE . '=' . ($set ? '1' : '')
            . ($maxAge !== null ? '; Max-Age=' . $maxAge : '')
            . '; Path=/'
            . ($domain !== '' ? '; Domain=' . $domain : '')
            . '; HttpOnly; SameSite=Lax'
            . (($this->marker['secure'] ?? false) ? '; Secure' : ''));
    }

    /** Queues the remember cookie with $value, or a clearing one for null. */
    private function queueCookie(?string $value): void
    {
        $this->cookies->queue($this->cookieName() . '=' . ($value ?? '')
            . '; Max-Age=' . ($value === null ? 0 : $this->days() * 86400)
            . '; Path=' . ($this->remember['path'] ?? '/')
            . '; HttpOnly; SameSite=Lax'
            . (($this->remember['secure'] ?? false) ? '; Secure' : ''));
    }
}
