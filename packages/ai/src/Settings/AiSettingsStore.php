<?php

declare(strict_types=1);

namespace Preflow\Ai\Settings;

/**
 * Edits made in the admin (sections "tuning" and "glossary"), kept apart from config/ai.php so
 * a deploy of the config doesn't overwrite them and an editor needs no file access.
 */
interface AiSettingsStore
{
    /** @return array<mixed> the section, or [] when nothing was stored */
    public function get(string $section): array;

    /** @param array<mixed> $data */
    public function put(string $section, array $data): void;
}
