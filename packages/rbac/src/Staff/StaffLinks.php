<?php

declare(strict_types=1);

namespace Preflow\Rbac\Staff;

/**
 * Where a staff member sets their password, for the invite and reset mails. Optional: without a
 * binding StaffAccounts::invite() and resetLink() refuse (StaffRefused::NO_LINK_PAGE) and
 * `php preflow staff:password` sets one on the server. An admin package binds one (Folio: its
 * set-password page).
 */
interface StaffLinks
{
    /**
     * Whether a link can be built now (Folio: app.url is set). When not, invite() and
     * resetLink() refuse (StaffRefused::NO_APP_URL) before any link is issued or revoked.
     */
    public function available(): bool;

    /** The absolute URL of the page that sets the password with $token; asked only when available(). */
    public function setPasswordUrl(string $token): string;
}
