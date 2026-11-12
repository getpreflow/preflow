<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\Core\Application;
use Preflow\Rbac\RoleStore;

/** rbac's commands: they run against the app in the working directory (or a test factory) and read --name=value options. */
trait RbacCommand
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    private function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }

        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }

    /**
     * The value of --$name=<value> among $args, $default without it.
     *
     * @param list<string> $args
     */
    private static function option(array $args, string $name, ?string $default = null): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                return substr($arg, strlen($name) + 3);
            }
        }

        return $default;
    }

    /**
     * The arguments that are no options.
     *
     * @param list<string> $args
     * @return list<string>
     */
    private static function arguments(array $args): array
    {
        return array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '-')));
    }

    /**
     * The population of --population (staff without it); null, after saying so, for one rbac has no roles for.
     *
     * @param list<string> $args
     */
    private static function population(array $args): ?string
    {
        $population = (string) self::option($args, 'population', 'staff');
        if (!in_array($population, RoleStore::POPULATIONS, true)) {
            echo "Unknown population \"{$population}\"; roles belong to " . implode(' or ', RoleStore::POPULATIONS) . ".\n";

            return null;
        }

        return $population;
    }
}
