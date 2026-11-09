<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Models\User;
use Nyholm\Psr7\Response;
use Preflow\Auth\AuthManager;
use Preflow\Data\DataManager;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Attributes\Route;
use Preflow\View\PageResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Route('/employer')]
final class EmployerAuthController
{
    private const string GUARD = 'employers';

    public function __construct(
        private readonly AuthManager $auth,
        private readonly DataManager $data,
        private readonly PageResponder $pages,
    ) {}

    #[Get('/login')]
    public function form(ServerRequestInterface $request): ResponseInterface
    {
        return $this->pages->render('_views/employer/login.twig', [
            'next' => self::safeNext($request->getQueryParams()['next'] ?? null),
            'email' => '',
            'failed' => false,
        ]);
    }

    #[Post('/login')]
    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $next = self::safeNext($body['next'] ?? null);

        $guard = $this->auth->guard(self::GUARD);
        $user = $email === '' || $password === '' || !$guard->validate(['email' => $email, 'password' => $password])
            ? null
            : $this->data->query(User::class)->where('email', $email)->first();

        // Employers only: an account without a company has nothing to do here.
        if (!$user instanceof User || $user->company === null) {
            return $this->pages->render('_views/employer/login.twig', ['next' => $next, 'email' => $email, 'failed' => true], 422);
        }

        $guard->login($user, $request);

        return new Response(302, ['Location' => $next ?? '/employer']);
    }

    #[Post('/logout')]
    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->guard(self::GUARD)->logout($request);

        return new Response(302, ['Location' => '/']);
    }

    /** Only a path inside the employer area: never another site, never a protocol-relative URL. */
    private static function safeNext(mixed $next): ?string
    {
        if (!is_string($next) || preg_match('#^/employer(?:[/?\#][^\\\\\x00-\x1f]*)?\z#', $next) !== 1) {
            return null;
        }

        return $next;
    }
}
