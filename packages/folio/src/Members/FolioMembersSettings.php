<?php

declare(strict_types=1);

namespace Preflow\Folio\Members;

use Preflow\Folio\Extension\Settings\ExtensionSettings;
use Preflow\Members\MembersSettings;

/**
 * The members settings from Settings → Members (the "members" extension's settings). With the
 * extension or its settings part switched off, registration is open and there is no notice
 * address and no own message.
 */
final class FolioMembersSettings implements MembersSettings
{
    public function __construct(
        private readonly ExtensionSettings $settings,
    ) {}

    public function registrationOpen(): bool
    {
        return $this->settings->get('members', 'registration_open') !== false;
    }

    public function noticeEmail(): ?string
    {
        $to = trim((string) $this->settings->get('members', 'notice_email'));

        return $to === '' ? null : $to;
    }

    public function acceptMessage(string $locale): ?string
    {
        return $this->message('accept_message', $locale);
    }

    public function declineMessage(string $locale): ?string
    {
        return $this->message('decline_message', $locale);
    }

    /** Exact: a text stored for another locale only would put the wrong language into the mail. */
    private function message(string $key, string $locale): ?string
    {
        $text = $this->settings->get('members', $key, $locale, exact: true);

        return is_string($text) && trim($text) !== '' ? $text : null;
    }
}
