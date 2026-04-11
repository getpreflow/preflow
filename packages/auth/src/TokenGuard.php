<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Data\DataManager;
use Psr\Http\Message\ServerRequestInterface;

final class TokenGuard implements GuardInterface
{
    public function __construct(
        private readonly UserProviderInterface $provider,
        private readonly DataManager $dataManager,
    ) {}

    public function user(ServerRequestInterface $request): ?Authenticatable
    {
        $plainToken = $this->extractBearerToken($request);

        if ($plainToken === null) {
            return null;
        }

        $tokenHash = PersonalAccessToken::hashToken($plainToken);

        /** @var PersonalAccessToken|null $tokenModel */
        $tokenModel = $this->dataManager
            ->query(PersonalAccessToken::class)
            ->where('tokenHash', $tokenHash)
            ->first();

        if ($tokenModel === null) {
            return null;
        }

        return $this->provider->findById($tokenModel->userId);
    }

    public function validate(array $credentials): bool
    {
        // Token guard does not validate passwords
        return false;
    }

    public function login(Authenticatable $user, ServerRequestInterface $request): void
    {
        // Stateless — no-op
    }

    public function logout(ServerRequestInterface $request): void
    {
        // Stateless — no-op
    }

    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '' || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        return $token !== '' ? $token : null;
    }
}
