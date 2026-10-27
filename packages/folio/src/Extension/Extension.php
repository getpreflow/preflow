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
     * One of the extension's model schemas (models/*.json) as Folio takes it, for the type and
     * its label keys alike: the file's, unless the extension completes it from code (the members
     * admin takes the member type the members core defines and adds what the admin needs).
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function model(array $schema): array
    {
        return $schema;
    }

    /**
     * One of the extension's model schemas (models/*.json) at boot, before a site's "extends"
     * file is merged: an extension adjusts what JSON can't know, such as a storage alias with
     * a fallback or select options from the site's locales.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function prepareModel(array $schema, Container $c): array
    {
        return $schema;
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
