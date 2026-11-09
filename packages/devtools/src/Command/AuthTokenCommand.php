<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;

/**
 * auth:token <email-or-id> [--name=<label>]: issues a bearer token for a user and prints it once
 * (only its hash is stored). auth:token --list <email-or-id> shows a user's tokens;
 * auth:token --revoke=<uuid> deletes one. The user comes from the token guard's provider.
 *
 * Needs preflow/auth; every reference to its classes is guarded so devtools runs without it.
 */
final class AuthTokenCommand implements CommandInterface
{
    private const string AUTH_MANAGER = 'Preflow\\Auth\\AuthManager';
    private const string TOKEN_GUARD = 'Preflow\\Auth\\TokenGuard';
    private const string TOKEN_MODEL = 'Preflow\\Auth\\PersonalAccessToken';

    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'auth:token'; }

    public function getDescription(): string { return 'Issue, list or revoke API tokens (auth:token <email-or-id> [--name=<label>] | --list <email-or-id> | --revoke=<uuid>)'; }

    public function execute(array $args): int
    {
        $name = 'cli';
        $list = false;
        $revoke = null;
        $subject = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--name=')) {
                $name = substr($arg, 7);
            } elseif ($arg === '--list') {
                $list = true;
            } elseif (str_starts_with($arg, '--revoke=')) {
                $revoke = substr($arg, 9);
            } elseif (str_starts_with($arg, '--')) {
                return $this->fail("Unknown option: {$arg} (auth:token <email-or-id> [--name=<label>] | --list <email-or-id> | --revoke=<uuid>)");
            } else {
                $subject = $arg;
            }
        }
        if ($name === '') {
            $name = 'cli';
        }
        if ($revoke === null && $subject === null) {
            return $this->fail('Usage: auth:token <email-or-id> [--name=<label>] | --list <email-or-id> | --revoke=<uuid>');
        }
        if (!class_exists(self::TOKEN_GUARD)) {
            return $this->fail('preflow/auth is not installed.');
        }

        try {
            $app = $this->app();
            $container = $app->container();
            $auth = $container->has(self::AUTH_MANAGER) ? $container->get(self::AUTH_MANAGER) : null;
            $config = $app->configFile('auth');
            $preferred = is_array($config) && isset($config['api_guard']) ? (string) $config['api_guard'] : null;
            $guardName = $auth?->tokenGuardName($preferred);
            $guard = $guardName === null ? null : $auth->guard($guardName);
            if (!$guard instanceof \Preflow\Auth\TokenGuard || !$container->has(\Preflow\Data\DataManager::class)) {
                return $this->fail('No token guard is configured: add a guard with class TokenGuard to config/auth.php.');
            }
            $dm = $container->get(\Preflow\Data\DataManager::class);

            try {
                if ($revoke !== null) {
                    return $this->revoke($dm, $revoke);
                }

                $user = str_contains((string) $subject, '@')
                    ? $guard->provider()->findByCredentials(['email' => $subject])
                    : $guard->provider()->findById((string) $subject);
                if ($user === null) {
                    return $this->fail("No user {$subject}.");
                }

                return $list ? $this->list($dm, $user->getAuthId()) : $this->issue($dm, $user->getAuthId(), (string) $subject, $name);
            } catch (\Throwable $e) {
                // Only a missing user_tokens table: SQLite "no such table: user_tokens", MySQL "user_tokens' doesn't exist",
                // PostgreSQL 'relation "user_tokens" does not exist'.
                if (preg_match('/no such table: (?:\w+\.)?user_tokens|user_tokens[`\'"]? doesn.t exist|relation "(?:\w+\.)?user_tokens" does not exist/i', $e->getMessage()) === 1) {
                    return $this->fail('Run the user_tokens migration first.');
                }
                throw $e;
            }
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function issue(\Preflow\Data\DataManager $dm, string $userId, string $subject, string $name): int
    {
        $plain = \Preflow\Auth\PersonalAccessToken::generatePlainToken();
        $token = new \Preflow\Auth\PersonalAccessToken();
        $token->uuid = self::uuid();
        $token->tokenHash = \Preflow\Auth\PersonalAccessToken::hashToken($plain);
        $token->userId = $userId;
        $token->name = $name;
        $token->createdAt = date('c');
        $dm->save($token);

        echo "Token for {$subject} ({$name}), shown once:\n{$plain}\n";

        return 0;
    }

    private function list(\Preflow\Data\DataManager $dm, string $userId): int
    {
        $tokens = $dm->query(self::TOKEN_MODEL)->where('userId', $userId)->get()->items();
        if ($tokens === []) {
            echo "No tokens.\n";

            return 0;
        }
        foreach ($tokens as $token) {
            echo "{$token->uuid}  {$token->name}  {$token->createdAt}\n";
        }

        return 0;
    }

    private function revoke(\Preflow\Data\DataManager $dm, string $uuid): int
    {
        $token = $dm->find(self::TOKEN_MODEL, $uuid);
        if ($token === null) {
            return $this->fail("No token {$uuid}.");
        }
        $dm->delete($token);
        echo "Revoked.\n";

        return 0;
    }

    private function fail(string $message): int
    {
        echo "Error: {$message}\n";

        return 1;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }
        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }
}
