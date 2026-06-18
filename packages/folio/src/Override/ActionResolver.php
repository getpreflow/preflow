<?php

declare(strict_types=1);

namespace Preflow\Folio\Override;

use Preflow\Core\Container\Container;

final class ActionResolver
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function resolve(string $controller, string $action): ?OverridableAction
    {
        $class = 'App\\Folio\\Overrides\\' . ucfirst($controller) . '\\' . ucfirst($action);

        if (!class_exists($class)) {
            return null;
        }
        if (!is_subclass_of($class, OverridableAction::class)) {
            return null;
        }

        $instance = $this->container->get($class);

        return $instance instanceof OverridableAction ? $instance : null;
    }
}
