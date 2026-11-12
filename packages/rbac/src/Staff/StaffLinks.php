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
     * The absolute URL of the page that sets the password with $token.
     *
     * @throws StaffRefused when no link can be built (StaffRefused::NO_APP_URL)
     */
    public function setPasswordUrl(string $token): string;
}
