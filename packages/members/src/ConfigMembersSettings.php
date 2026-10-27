<?php

declare(strict_types=1);

namespace Preflow\Members;

/**
 * The owner's settings from config/members.php: registration_open (default true), notice_email,
 * accept_message and decline_message (one text for every locale, or locale => text). The
 * provider binds it unless something else (Folio's members admin) is bound.
 */
final class ConfigMembersSettings implements MembersSettings
{
    public function __construct(
        private readonly MembersConfig $config,
    ) {}

    public function registrationOpen(): bool
    {
        return $this->config->value('registration_open') !== false;
    }

    public function noticeEmail(): ?string
    {
        $to = $this->config->value('notice_email');

        return is_string($to) && trim($to) !== '' ? trim($to) : null;
    }

    public function acceptMessage(string $locale): ?string
    {
        return $this->message('accept_message', $locale);
    }

    public function declineMessage(string $locale): ?string
    {
        return $this->message('decline_message', $locale);
    }

    private function message(string $key, string $locale): ?string
    {
        $text = $this->config->value($key);
        if (is_array($text)) {
            $text = $text[$locale] ?? null;
        }

        return is_string($text) && trim($text) !== '' ? $text : null;
    }
}
