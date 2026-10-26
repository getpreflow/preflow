<?php

declare(strict_types=1);

namespace Preflow\Members;

/**
 * The owner's members settings: whether registration is open, who hears about a member
 * waiting for a decision, and the owner's own accept and decline messages. Folio's members
 * admin implements it from Settings → Members.
 */
interface MembersSettings
{
    public function registrationOpen(): bool;

    /** The address told about a member waiting for a decision; null for none. */
    public function noticeEmail(): ?string;

    /**
     * The owner's accept message stored for $locale itself (never another locale's); null when
     * there is none, so the caller takes the lang files' (members.mail.accepted.body).
     */
    public function acceptMessage(string $locale): ?string;

    /** As acceptMessage(), for the decline message (members.mail.declined.body). */
    public function declineMessage(string $locale): ?string;
}
