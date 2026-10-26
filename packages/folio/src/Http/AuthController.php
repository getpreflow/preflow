<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\PasswordRehash;
use Preflow\Auth\SessionGuard;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Throttle\Throttle;
use Preflow\Auth\Record\RecordUser;
use Preflow\Folio\Users\StaffPasswords;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Folio admin login/logout through the default session guard (post-Redirect-Get flow).
 *
 * With record users behind the guard ($passwords enabled) the login is throttled per client and
 * per e-mail (purpose folio.login), an unknown address or an account without a password costs a
 * hash like a wrong password, a record's sign-in is stamped (last_login_at) and the page links to
 * "forgot password". Every
 * refusal answers alike. The page offers remember-me whenever the guard has it configured. A site
 * with config users only and no remember-me keeps the plain login, page and all.
 */
final class AuthController
{
    public const string THROTTLE_LOGIN = 'folio.login';

    /** [attempts, seconds], per client and per e-mail */
    private const array LOGIN_LIMIT = [10, 900];

    private const string INVALID = 'folio.auth.invalid';
    private const string THROTTLED = 'folio.auth.throttled';

    public function __construct(
        private readonly AuthManager $auth,
        private readonly UserProviderInterface $users,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly ?StaffPasswords $passwords = null,
        private readonly ?Throttle $throttle = null,
        private readonly ?PasswordHasherInterface $hasher = null,
    ) {}

    public function loginForm(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->guard()->user($request) !== null) {
            return new Response(302, ['Location' => $this->prefix]);
        }

        $session = $request->getAttribute(SessionInterface::class);
        $error = $session instanceof SessionInterface ? $session->getFlash('error') : null;

        return $this->page(is_string($error) ? $error : '');
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $remember = ($body['remember'] ?? '') === '1';

        if ($this->staff()) {
            return $this->staffLogin($request, $email, $password, $remember);
        }

        $guard = $this->auth->guard();
        if (!$guard->validate(['email' => $email, 'password' => $password])) {
            return $this->refuse($request);
        }

        $user = $this->users->findByCredentials(['email' => $email]);
        if ($user === null) {
            return $this->refuse($request);
        }

        $this->signIn($user, $request, $remember);
        return new Response(302, ['Location' => $this->prefix]);
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->guard()->logout($request);
        return new Response(302, ['Location' => $this->prefix . '/login']);
    }

    private function staffLogin(ServerRequestInterface $request, string $email, string $password, bool $remember): ResponseInterface
    {
        \assert($this->throttle !== null && $this->hasher !== null && $this->passwords !== null);
        [$max, $window] = self::LOGIN_LIMIT;
        $client = $this->throttle->client($request);
        $key = hash('sha256', mb_strtolower($email));
        if ($this->throttle->tooMany(self::THROTTLE_LOGIN, $client, $max, $window) || $this->throttle->tooMany(self::THROTTLE_LOGIN, $key, $max, $window)) {
            return $this->page(\Preflow\Folio\FolioText::get(self::THROTTLED), 429);
        }

        $user = $email === '' ? null : $this->users->findByCredentials(['email' => $email]);
        if ($user === null || $user->getPasswordHash() === '') {
            // An unknown address (or an invited account without a password yet) costs a hash too,
            // so the answer takes as long as a wrong password.
            $this->hasher->hash($password);
            $ok = false;
        } else {
            $ok = $this->hasher->verify($password, $user->getPasswordHash());
        }
        if (!$ok || $user === null) {
            $this->throttle->hit(self::THROTTLE_LOGIN, $client, $window);
            $this->throttle->hit(self::THROTTLE_LOGIN, $key, $window);

            return $this->refuse($request);
        }

        // An outdated hash (an imported $1$, an older cost) is upgraded while the password is at hand.
        PasswordRehash::afterVerify($this->users, $user, $password, $this->hasher);
        $this->signIn($user, $request, $remember);
        // Only the address's count: a client that guesses many addresses keeps its count after one success.
        $this->throttle->clear(self::THROTTLE_LOGIN, $key);
        if ($user instanceof RecordUser) {
            $this->passwords->stampLogin($user);
        }

        return new Response(302, ['Location' => $this->prefix]);
    }

    /** Signs in through the default guard, with its remember-me cookie when asked for and configured. */
    private function signIn(Authenticatable $user, ServerRequestInterface $request, bool $remember): void
    {
        $guard = $this->auth->guard();
        if ($guard instanceof SessionGuard) {
            $guard->login($user, $request, $remember && $guard->remembers());
        } else {
            $guard->login($user, $request);
        }
    }

    /** The one answer to every refused sign-in. */
    private function refuse(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if ($session instanceof SessionInterface) {
            $session->flash('error', \Preflow\Folio\FolioText::get(self::INVALID));
        }

        return new Response(302, ['Location' => $this->prefix . '/login']);
    }

    private function page(string $error, int $status = 200): ResponseInterface
    {
        $context = [
            'login_action' => $this->prefix . '/login',
            'login_error' => $error,
        ];
        $guard = $this->auth->guard();
        if ($guard instanceof SessionGuard && $guard->remembers()) {
            $context['remember_offered'] = true;
        }
        if ($this->staff()) {
            $context['forgot_url'] = $this->prefix . '/forgot';
        }

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/login.twig', $context));
    }

    /** Record users behind the guard: the throttled login with its password pages. */
    private function staff(): bool
    {
        return $this->passwords !== null && $this->passwords->enabled() && $this->throttle !== null && $this->hasher !== null;
    }
}
