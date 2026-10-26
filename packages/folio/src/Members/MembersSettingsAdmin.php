<?php

declare(strict_types=1);

namespace Preflow\Folio\Members;

use Preflow\Folio\Extension\Attributes\FolioType;
use Preflow\Folio\Extension\FieldPlaceholder;
use Preflow\I18n\Translator;

/**
 * Settings → Members settings: an empty accept or decline message means the lang files'
 * standard text (the site's lang/{locale}/members.php over the package's), so the form shows
 * that text, in the locale being edited, as the field's placeholder.
 */
#[FolioType('members_settings')]
final class MembersSettingsAdmin
{
    public function __construct(
        private readonly Translator $translator,
    ) {}

    #[FieldPlaceholder('accept_message')]
    public function acceptStandard(string $locale): string
    {
        return $this->translator->get('members.mail.accepted.body', [], $locale);
    }

    #[FieldPlaceholder('decline_message')]
    public function declineStandard(string $locale): string
    {
        return $this->translator->get('members.mail.declined.body', [], $locale);
    }
}
