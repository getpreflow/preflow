<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\UserProviderInterface;
use Psr\Http\Message\ServerRequestInterface;

/** A custom guard for boot tests: the user whose id is in the X-User header. */
final class HeaderGuard implements GuardInterface
{
    public function __construct(private readonly UserProviderInterface $provider) {}

    public function user(ServerRequestInterface $request): ?Authenticatable
    {
        $id = $request->getHeaderLine('X-User');

        return $id === '' ? null : $this->provider->findById($id);
    }

    public function validate(array $credentials): bool
    {
        return false;
    }

    public function login(Authenticatable $user, ServerRequestInterface $request): void {}

    public function logout(ServerRequestInterface $request): void {}
}
