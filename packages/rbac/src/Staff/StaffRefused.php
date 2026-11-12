<?php

declare(strict_types=1);

namespace Preflow\Rbac\Staff;

/**
 * A staff account change StaffAccounts refused; nothing changed. $reason is one of the constants,
 * the message says why in words (rbac's strings, in the translator's language), $field names the
 * account field it is about (for a form), or is null.
 */
final class StaffRefused extends \RuntimeException
{
    /** No staff type here: config/auth.php names no record provider of type "staff". */
    public const string NO_STAFF_TYPE = 'no_staff_type';
    public const string EMAIL_INVALID = 'email_invalid';
    /** Another staff account has the address. */
    public const string EMAIL_TAKEN = 'email_taken';
    /** A config user of the staff guard has the address (refused when someone signed in changes it). */
    public const string CONFIG_EMAIL = 'config_email';
    /** A role name no staff role has. */
    public const string UNKNOWN_ROLE = 'unknown_role';
    /** The account holds roles the actor can't grant. */
    public const string ACCOUNT_ABOVE = 'account_above';
    /** A role added or removed grants what the actor doesn't hold. */
    public const string GRANT = 'grant';
    /** No active account would be left that may manage staff and roles. */
    public const string LAST_MANAGER = 'last_manager';
    public const string HAS_PASSWORD = 'has_password';
    public const string INACTIVE = 'inactive';
    /** The staff guard has no record provider for the account: it can't sign in, so no link or password. */
    public const string NO_SIGN_IN = 'no_sign_in';
    /** No StaffLinks binding: no page to set a password at. */
    public const string NO_LINK_PAGE = 'no_link_page';
    /** The link page can't build a link now (StaffLinks::available() is false; Folio: app.url is not set). */
    public const string NO_APP_URL = 'no_app_url';
    public const string PASSWORD_SHORT = 'password_short';
    /** The account was deleted meanwhile. */
    public const string GONE = 'gone';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }
}
