<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

/**
 * Makes this PHP process behave as if preflow/ai (and the provider SDK it brings) were not
 * installed: every later autoload of a Preflow\Ai\* or Anthropic\* class finds nothing, so
 * class_exists() answers false and a use of such a class fails as it would on a site without the
 * package. Only for a test that runs in its own process (#[RunInSeparateProcess] with
 * #[PreserveGlobalState(false)], so the parent's loaded files are not carried over); it refuses to
 * run once such a class has been loaded.
 *
 * A copy of packages/i18n/tests/Support/HiddenAiPackage.php (each split package runs its own
 * tests): change both.
 */
final class HiddenAiPackage
{
    private const HIDDEN = ['Preflow\\Ai\\', 'Anthropic\\'];

    public static function hide(): void
    {
        foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $class) {
            if (self::hidden($class)) {
                throw new \LogicException("{$class} is already loaded: hide preflow/ai first, in a separate process without the parent's state.");
            }
        }
        $loaders = spl_autoload_functions();
        foreach ($loaders as $loader) {
            spl_autoload_unregister($loader);
        }
        spl_autoload_register(static function (string $class) use ($loaders): void {
            if (self::hidden($class)) {
                return;
            }
            foreach ($loaders as $loader) {
                $loader($class);
                if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false) || enum_exists($class, false)) {
                    return;
                }
            }
        });
    }

    private static function hidden(string $class): bool
    {
        $class = ltrim($class, '\\');
        if (str_starts_with($class, 'Preflow\\Ai\\Tests\\')) {
            return false;
        }
        foreach (self::HIDDEN as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
