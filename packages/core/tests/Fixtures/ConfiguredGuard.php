<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Container\Attributes\Config;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Psr\Http\Message\ServerRequestInterface;

/** A service only an app's provider binds: a guard that takes it must be built after the providers. */
final class GuardAudit
{
    /** @var list<string> */
    public array $seen = [];
}

/** Binds GuardAudit and, when asked, a password hasher of the app's own. */
final class GuardTestProvider extends ServiceProvider
{
    public static bool $bindHasher = false;

    public function register(Container $container): void
    {
        $container->singleton(GuardAudit::class, static fn (): GuardAudit => new GuardAudit());
        if (self::$bindHasher) {
            $container->singleton(PasswordHasherInterface::class, static fn (): PasswordHasherInterface => new PlainPrefixHasher('app'));
        }
    }
}

/** A guard reading the user id from the header its config entry names; takes a provider's service and a #[Config] value. */
final class ConfiguredGuard implements GuardInterface
{
    public static int $built = 0;

    /** @param array<string, mixed> $config its config/auth.php entry */
    public function __construct(
        private readonly UserProviderInterface $provider,
        public readonly array $config,
        public readonly GuardAudit $audit,
        #[Config('app.name')] public readonly string $appName,
    ) {
        self::$built++;
    }

    public function user(ServerRequestInterface $request): ?Authenticatable
    {
        $id = $request->getHeaderLine((string) $this->config['header']);
        $this->audit->seen[] = $id;

        return $id === '' ? null : $this->provider->findById($id);
    }

    public function validate(array $credentials): bool
    {
        return false;
    }

    public function login(Authenticatable $user, ServerRequestInterface $request): void {}

    public function logout(ServerRequestInterface $request): void {}
}

/** A hasher of the app's own: "<prefix>:<password>", so a test can tell it is the one used. */
final class PlainPrefixHasher implements PasswordHasherInterface
{
    public function __construct(private readonly string $prefix = 'cfg') {}

    public function hash(string $password): string
    {
        return $this->prefix . ':' . $password;
    }

    public function verify(string $password, string $hash): bool
    {
        return hash_equals($hash, $this->prefix . ':' . $password);
    }

    public function needsRehash(string $hash): bool
    {
        return false;
    }
}

/** A configured hasher class that takes a service: config/auth.php password_hasher is built by the container. */
final class AuditedHasher implements PasswordHasherInterface
{
    public function __construct(public readonly GuardAudit $audit) {}

    public function hash(string $password): string
    {
        return 'audited:' . $password;
    }

    public function verify(string $password, string $hash): bool
    {
        $this->audit->seen[] = 'verify';

        return hash_equals($hash, 'audited:' . $password);
    }

    public function needsRehash(string $hash): bool
    {
        return false;
    }
}
