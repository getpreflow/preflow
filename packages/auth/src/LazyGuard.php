<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * A guard of a class of the app's own, built on first use. The application builds such guards
 * through the container with their config/auth.php entry (`provider` the built user provider,
 * `config` the whole entry), after the service providers ran, so a guard may take the services
 * they bind and #[Config] values. AuthManager and ResolveUserMiddleware hold this stand-in and
 * hand out the built guard itself, so `instanceof` checks on it work.
 *
 * @since 0.66
 */
final class LazyGuard
{
    private ?GuardInterface $guard = null;

    /** @param \Closure(): object $factory builds the guard; the result is checked */
    public function __construct(
        public readonly string $name,
        public readonly string $class,
        private readonly \Closure $factory,
    ) {}

    /** The guard, built now if need be (a class that is no guard throws here). */
    public function resolve(): GuardInterface
    {
        if ($this->guard === null) {
            $built = ($this->factory)();
            if (!$built instanceof GuardInterface) {
                throw new \RuntimeException("Guard \"{$this->name}\" ({$this->class}) does not implement " . GuardInterface::class . '.');
            }
            $this->guard = $built;
        }

        return $this->guard;
    }

    /** The guard if it was built already, else null (nothing is built). */
    public function built(): ?GuardInterface
    {
        return $this->guard;
    }

    /** Whether the guard is (or will be) an instance of $class, without building it. */
    public function is(string $class): bool
    {
        return is_a($this->guard ?? $this->class, $class, true);
    }

    /** A guard as config gives it: built, or this stand-in built now. */
    public static function of(GuardInterface|self $guard): GuardInterface
    {
        return $guard instanceof self ? $guard->resolve() : $guard;
    }
}
