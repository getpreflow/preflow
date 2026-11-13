<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Models\User;
use App\Seeds\DemoSeeder;
use Nyholm\Psr7\Response;
use Preflow\Auth\AuthManager;
use Preflow\Core\Container\Attributes\Config;
use Preflow\Core\Http\SafeReturnPath;
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
        #[Config('app.debug', 0)] private readonly int|string|bool $debug = 0,
    ) {}

    #[Get('/login')]
    public function form(ServerRequestInterface $request): ResponseInterface
    {
        return $this->pages->render('_views/employer/login.twig', [
            'next' => SafeReturnPath::check(self::text($request->getQueryParams()['next'] ?? null), ['/employer']),
            'email' => '',
            'failed' => false,
            'demo' => $this->demo(),
        ]);
    }

    #[Post('/login')]
    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        // Stored in lower case (employer:create), and SQLite compares text case-sensitively.
        $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $next = SafeReturnPath::check(self::text($body['next'] ?? null), ['/employer']);

        $guard = $this->auth->guard(self::GUARD);
        $user = $email === '' || $password === '' || !$guard->validate(['email' => $email, 'password' => $password])
            ? null
            : $this->data->query(User::class)->where('email', $email)->first();

        // Employers only: an account without a company has nothing to do here.
        if (!$user instanceof User || $user->company === null) {
            return $this->pages->render('_views/employer/login.twig', ['next' => $next, 'email' => $email, 'failed' => true, 'demo' => $this->demo()], 422);
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

    /**
     * The demo account to show on the sign-in page: only while developing (APP_DEBUG), and only when
     * the demo seed made it. A live site never shows it (the seed refuses to run there anyway).
     *
     * @return array{email: string, password: string}|null
     */
    private function demo(): ?array
    {
        if ((int) $this->debug === 0) {
            return null;
        }
        $user = $this->data->query(User::class)->where('email', DemoSeeder::EMPLOYER_EMAIL)->first();

        return $user instanceof User ? ['email' => DemoSeeder::EMPLOYER_EMAIL, 'password' => DemoSeeder::EMPLOYER_PASSWORD] : null;
    }

    /** A request value that may be an array or missing: only a string goes on to the path check. */
    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
