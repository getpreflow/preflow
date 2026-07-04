<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\AuthManager;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Folio admin login/logout. Validates config credentials through the session
 * guard (post-Redirect-Get flow), rendering the orphaned login.twig.
 */
final class AuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly UserProviderInterface $users,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
    ) {}

    public function loginForm(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->guard()->user($request) !== null) {
            return new Response(302, ['Location' => $this->prefix]);
        }

        $session = $request->getAttribute(SessionInterface::class);
        $error = $session instanceof SessionInterface ? $session->getFlash('error') : null;

        $html = $this->engine->render('@folio/admin/login.twig', [
            'login_action' => $this->prefix . '/login',
            'login_error' => is_string($error) ? $error : '',
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $session = $request->getAttribute(SessionInterface::class);
        $guard = $this->auth->guard();

        if (!$guard->validate(['email' => $email, 'password' => $password])) {
            if ($session instanceof SessionInterface) {
                $session->flash('error', 'Invalid email or password.');
            }
            return new Response(302, ['Location' => $this->prefix . '/login']);
        }

        $user = $this->users->findByCredentials(['email' => $email]);
        if ($user === null) {
            if ($session instanceof SessionInterface) {
                $session->flash('error', 'Invalid email or password.');
            }
            return new Response(302, ['Location' => $this->prefix . '/login']);
        }

        $guard->login($user, $request);
        return new Response(302, ['Location' => $this->prefix]);
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->guard()->logout($request);
        return new Response(302, ['Location' => $this->prefix . '/login']);
    }
}
