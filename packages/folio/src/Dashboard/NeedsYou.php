<?php

declare(strict_types=1);

namespace Preflow\Folio\Dashboard;

/**
 * A card in the dashboard's "Needs you" section: open work and where to act on it. Folio's own
 * items (new permissions, changes not live, not-found addresses, translations to review) and what
 * a #[DashboardItem] method returns. The title says what waits ("3 changes are not live"), the
 * optional text how to go about it; the card links to $url: a path relative to the admin prefix
 * ('rbac_role'), or a site path ('/folio/redirects'), as #[ListColumn]'s Link takes it (anything
 * else, such as another host, shows no card). The tone colours its edge and icon: warning,
 * info or danger; any other tone is an \InvalidArgumentException here. $icon is a sprite id of
 * Folio's icons.svg ('ui-shield'); null takes the tone's (ui-warn, ui-info).
 *
 * @since 0.76
 */
final readonly class NeedsYou
{
    public const array TONES = ['warning', 'info', 'danger'];

    public function __construct(
        public string $title,
        public ?string $text = null,
        public string $url = '',
        public string $tone = 'warning',
        public ?string $icon = null,
    ) {
        if (!in_array($tone, self::TONES, true)) {
            throw new \InvalidArgumentException('NeedsYou tone must be "warning", "info" or "danger", not "' . $tone . '".');
        }
        if ($icon !== null && preg_match('/^[a-z][a-z0-9-]*$/', $icon) !== 1) {
            throw new \InvalidArgumentException('NeedsYou icon must be a sprite id such as "ui-shield", not "' . $icon . '".');
        }
    }

    /** The icon the card shows: its own, else the tone's. */
    public function glyph(): string
    {
        return $this->icon ?? ($this->tone === 'info' ? 'ui-info' : 'ui-warn');
    }
}
