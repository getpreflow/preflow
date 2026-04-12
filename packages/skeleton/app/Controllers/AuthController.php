<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use Nyholm\Psr7\Response;
use Preflow\Auth\AuthManager;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DataManager;
use Preflow\Routing\Attributes\Post;
use Preflow\Routing\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;

#[Route('/')]
final class AuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly PasswordHasherInterface $hasher,
        private readonly DataManager $dataManager,
    ) {}

    #[Post('/login')]
    public function login(ServerRequestInterface $request): Response
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $guard = $this->auth->guard('session');

        if (!$guard->validate(['email' => $email, 'password' => $password])) {
            $session = $request->getAttribute(SessionInterface::class);
            $session?->flash('error', 'Invalid email or password.');
            return new Response(302, ['Location' => '/login']);
        }

        $user = $this->dataManager->query(User::class)
            ->where('email', $email)
            ->first();

        if ($user === null) {
            $session = $request->getAttribute(SessionInterface::class);
            $session?->flash('error', 'Invalid email or password.');
            return new Response(302, ['Location' => '/login']);
        }

        $guard->login($user, $request);

        return new Response(302, ['Location' => '/']);
    }

    #[Post('/register')]
    public function register(ServerRequestInterface $request): Response
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? '');

        $session = $request->getAttribute(SessionInterface::class);

        if ($email === '' || $password === '') {
            $session?->flash('error', 'Email and password are required.');
            return new Response(302, ['Location' => '/register']);
        }

        if (strlen($password) < 8) {
            $session?->flash('error', 'Password must be at least 8 characters.');
            return new Response(302, ['Location' => '/register']);
        }

        if ($password !== $passwordConfirm) {
            $session?->flash('error', 'Passwords do not match.');
            return new Response(302, ['Location' => '/register']);
        }

        $existing = $this->dataManager->query(User::class)
            ->where('email', $email)
            ->first();

        if ($existing !== null) {
            $session?->flash('error', 'An account with that email already exists.');
            return new Response(302, ['Location' => '/register']);
        }

        $user = new User();
        $user->uuid = $this->generateUuid();
        $user->email = $email;
        $user->passwordHash = $this->hasher->hash($password);
        $user->roles = [];
        $user->createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->dataManager->save($user);

        return new Response(302, ['Location' => '/login']);
    }

    #[Post('/logout')]
    public function logout(ServerRequestInterface $request): Response
    {
        $this->auth->guard('session')->logout($request);

        return new Response(302, ['Location' => '/login']);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
