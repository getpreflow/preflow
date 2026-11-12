<?php

declare(strict_types=1);

namespace Preflow\Folio\Translations;

use Preflow\Folio\Http\InterfaceLocales;
use Preflow\I18n\OverrideScope;

/** The texts Settings → Translations offers (EditableTexts), as the override rules ask for them. */
final class EditableOverrideScope implements OverrideScope
{
    public function __construct(
        private readonly EditableTexts $texts,
        private readonly InterfaceLocales $locales,
    ) {}

    public function locales(string $group): array
    {
        return $this->texts->locales($group, $this->locales->all());
    }

    public function editable(string $key, string $locale): bool
    {
        return $this->texts->editable($key, $locale);
    }

    public function placeholders(string $key, string $locale): ?array
    {
        $group = (string) strstr($key, '.', true);

        return $this->texts->isEditableGroup($group) ? $this->texts->placeholders($key, $locale) : null;
    }
}
