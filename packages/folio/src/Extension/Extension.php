<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

use Preflow\Core\Container\Container;

/**
 * Base class of a Folio extension (carrying #[FolioExtension]). Its folder holds the
 * extension's parts: screens, type customizations, models/*.json and templates/.
 */
abstract class Extension
{
    /** Runs once per version, from folio:extensions:apply. */
    public function install(Container $c): void
    {
    }

    public function uninstall(Container $c): void
    {
    }

    /**
     * The extension's settings form, in the model JSON "fields" format; a field may carry a
     * "default", and an "env" field names an environment variable. Non-empty: Folio edits it
     * under Settings as the singleton type "{name}_settings" (read with ExtensionSettings).
     *
     * @return array<string, array<string, mixed>>
     */
    public function settings(): array
    {
        return [];
    }
}
