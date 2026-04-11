<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Psr\Http\Message\ServerRequestInterface;

interface GuardInterface
{
    public function user(ServerRequestInterface $request): ?Authenticatable;
    public function validate(array $credentials): bool;
    public function login(Authenticatable $user, ServerRequestInterface $request): void;
    public function logout(ServerRequestInterface $request): void;
}
