<?php

declare(strict_types=1);

namespace Preflow\Folio\Rbac;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Users\UserPreferences;
use Preflow\Rbac\Staff\StaffLinks;
use Preflow\Rbac\Staff\StaffLocales;
use Preflow\Rbac\Staff\StaffRefused;

/**
 * preflow/rbac's staff links and languages in Folio: an invite or reset link opens Folio's
 * set-password page ({app.url}{prefix}/password/{token}); a staff member's mails are in their
 * interface language (UserPreferences).
 */
final class FolioStaffLinks implements StaffLinks, StaffLocales
{
    /** @param string $passwordUrl absolute URL of the set-password page ('' when app.url is unset) */
    public function __construct(
        private readonly string $passwordUrl,
        private readonly UserPreferences $preferences,
        private readonly string $message,
    ) {}

    public function setPasswordUrl(string $token): string
    {
        if ($this->passwordUrl === '') {
            throw new StaffRefused(StaffRefused::NO_APP_URL, $this->message);
        }

        return $this->passwordUrl . '/' . $token;
    }

    public function locale(DynamicRecord $staff): ?string
    {
        return $this->preferences->locale((string) $staff->getId()); // the auth id of a record user
    }
}
