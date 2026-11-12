<?php

declare(strict_types=1);

namespace Preflow\Rbac\Staff;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\Record\AccountLinks;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Rbac\ConfigUsers;
use Preflow\Rbac\Messages;
use Preflow\Rbac\PermissionCoverage;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\StaffManagers;

/**
 * Staff accounts (the type "staff") and their rules, for every way in: the console (staff:*
 * commands), an admin (Folio's staff screens call it) and code. A container service.
 *
 * The rules (checkSave(), checkDelete()) run on every write: the e-mail is stored lowercased and
 * unique; a new account starts active, with epoch "0" and created_at; a role is added only by the
 * name of a defined staff role; no write may take the last active manager (StaffManagers) away.
 * With an $actor (someone signed in), delegation applies too (PermissionCoverage): an account
 * whose stored roles the actor doesn't fully cover is not theirs to change or delete, each role
 * added or removed must be covered, and a new or changed address may not be a config user's of
 * the staff guard. A null $actor is the console: no delegation limit.
 *
 * Passwords and links go through auth's AccountLinks for the staff guard's record accounts
 * (config/rbac.php 'guard', else the guard an admin binds as StaffPopulation, else auth's
 * default guard). Invite and reset links (purposes folio.invite, 7 days, and folio.reset, one
 * hour; the names stay as stored) point at the page a StaffLinks binding names, and go out as
 * rbac's mails (StaffMail). A new password, disabling an account and ending its access void its
 * links. Every refusal is a StaffRefused, and nothing changed.
 */
final class StaffAccounts
{
    /** Token purposes of the invite and reset links (stored names, shared with Folio's set-password page). */
    public const string INVITE = 'folio.invite';
    public const string RESET = 'folio.reset';
    public const int INVITE_TTL = 7 * 86400;
    public const int RESET_TTL = 3600;

    /** The shortest password setPassword() takes, unless config/rbac.php 'password_min' says otherwise. */
    public const int PASSWORD_MIN = 10;

    /** The fields update() changes. */
    private const array FIELDS = ['email', 'name', 'roles', 'active'];

    private ?AccountLinks $builtLinks = null;

    private bool $linksBuilt = false;

    private ?StaffMail $builtMail = null;

    /**
     * @param string $guard                                 the staff guard's name
     * @param \Closure(): AccountLinks $links               the staff guard's password links (may throw: no auth)
     * @param \Closure(): ?UserProviderInterface $users     the staff guard's user provider, config users included
     * @param \Closure(): ?StaffLinks $pages                where a password is set (null: no such page)
     * @param \Closure(): StaffMail $mail                   built on the first mail
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly PermissionCoverage $coverage,
        private readonly Messages $messages,
        private readonly string $guard,
        private readonly \Closure $links,
        private readonly \Closure $users,
        private readonly \Closure $pages,
        private readonly \Closure $mail,
        private readonly int $passwordMin = self::PASSWORD_MIN,
    ) {}

    /** The guard staff sign in through. */
    public function guard(): string
    {
        return $this->guard;
    }

    /** Whether the site has the staff type. */
    public function enabled(): bool
    {
        return (new StaffManagers($this->dm))->enabled();
    }

    /** The staff guard's user provider (config users included); null without auth. */
    public function users(): ?UserProviderInterface
    {
        try {
            return ($this->users)();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every staff account, active or not, by e-mail.
     *
     * @return list<DynamicRecord>
     */
    public function all(): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $accounts = array_values(array_filter($this->dm->queryType(StaffManagers::TYPE)->get()->items(), static fn (mixed $r): bool => $r instanceof DynamicRecord));
        usort($accounts, static fn (DynamicRecord $a, DynamicRecord $b): int => strcmp((string) $a->get('email'), (string) $b->get('email')));

        return $accounts;
    }

    /** The staff account with this e-mail (compared trimmed and lowercased), active or not. */
    public function find(string $email): ?DynamicRecord
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !$this->enabled()) {
            return null;
        }
        $record = $this->dm->queryType(StaffManagers::TYPE)->where('email', $email)->first();

        return $record instanceof DynamicRecord ? $record : null;
    }

    /**
     * A new account, active and without a password (it can't sign in until one is set: invite(),
     * or setPassword() on the server).
     *
     * @param list<string> $roles staff role names
     * @throws StaffRefused
     */
    public function create(string $email, string $name, array $roles, ?Authenticatable $actor = null): DynamicRecord
    {
        $this->requireType();
        $type = $this->dm->getTypeRegistry()->get(StaffManagers::TYPE);
        $record = DynamicRecord::fromArray($type, [
            $type->idField => bin2hex(random_bytes(16)),
            'email' => $this->email($email),
            'name' => trim($name),
            'password_hash' => '',
            'roles' => RoleList::normalize($roles),
            'active' => true,
            'auth_epoch' => '0',
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
        $this->checkSave($record, $actor);
        $this->dm->saveType($record, false);

        return $record;
    }

    /**
     * Changes the account as stored now: any of email, name, roles (names), active. Turning
     * active off disables it as disable() does.
     *
     * @param array<string, mixed> $changes
     * @throws StaffRefused
     */
    public function update(DynamicRecord $staff, array $changes, ?Authenticatable $actor = null): void
    {
        $unknown = array_diff(array_keys($changes), self::FIELDS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Staff accounts change only ' . implode(', ', self::FIELDS) . '; not ' . implode(', ', $unknown) . '.');
        }
        $record = $this->fresh($staff);
        $was = StaffManagers::active($record->get('active'));
        foreach ($changes as $field => $value) {
            $record->set($field, match ($field) {
                'email' => $this->email((string) $value),
                'name' => trim((string) $value),
                'roles' => RoleList::normalize($value),
                'active' => StaffManagers::active($value),
            });
        }
        $this->checkSave($record, $actor); // a save that disables gets a new epoch there
        $this->dm->saveType($record, false);
        if ($was && !StaffManagers::active($record->get('active'))) {
            $this->endAccess($record);
        }
    }

    /**
     * Mails a set-password link valid for 7 days; earlier invite links stop working. Only for an
     * active account without a password.
     *
     * @throws StaffRefused
     */
    public function invite(DynamicRecord $staff): void
    {
        if ((string) $staff->get('password_hash') !== '') {
            throw $this->refused(StaffRefused::HAS_PASSWORD, 'staff.has_password');
        }
        if (!StaffManagers::active($staff->get('active'))) {
            throw $this->refused(StaffRefused::INACTIVE, 'staff.inactive');
        }
        $links = $this->requireLinks($staff);
        $page = $this->page();
        $links->revoke($staff, self::INVITE);
        $this->send('invite', $staff, $this->link($links, $page, $staff, self::INVITE, self::INVITE_TTL));
    }

    /**
     * Mails a set-password link valid for one hour (earlier reset links stay valid until then).
     *
     * @throws StaffRefused
     */
    public function resetLink(DynamicRecord $staff): void
    {
        if (!StaffManagers::active($staff->get('active'))) {
            throw $this->refused(StaffRefused::INACTIVE, 'staff.inactive');
        }
        $links = $this->requireLinks($staff);
        $this->send('reset', $staff, $this->link($links, $this->page(), $staff, self::RESET, self::RESET_TTL));
    }

    /**
     * Sets the password (AccountLinks: the epoch goes up, so other sessions end, and remember-me
     * logins end) and voids the account's invite and reset links.
     *
     * @throws StaffRefused
     */
    public function setPassword(DynamicRecord $staff, string $password): void
    {
        if (mb_strlen($password) < $this->passwordMin) {
            throw $this->refused(StaffRefused::PASSWORD_SHORT, 'staff.password_short', ['min' => $this->passwordMin], 'password');
        }
        $links = $this->requireLinks($staff);
        if (!$links->setPassword($staff, $password)) {
            throw $this->refused(StaffRefused::INACTIVE, 'staff.inactive');
        }
        $links->revoke($staff, self::INVITE, self::RESET);
    }

    /**
     * Disables the account as stored now: it can't sign in, a new epoch ends its live sessions
     * (for good: enabling it again doesn't bring them back), its remember-me logins end and its
     * links are void.
     *
     * @throws StaffRefused
     */
    public function disable(DynamicRecord $staff, ?Authenticatable $actor = null): void
    {
        $record = $this->fresh($staff);
        $record->set('active', false);
        $this->checkSave($record, $actor); // and a new epoch
        $this->dm->saveType($record, false);
        $this->endAccess($record);
    }

    /**
     * Enables the account as stored now; nothing else changes.
     *
     * @throws StaffRefused
     */
    public function enable(DynamicRecord $staff, ?Authenticatable $actor = null): void
    {
        $record = $this->fresh($staff);
        $record->set('active', true);
        $this->checkSave($record, $actor);
        $this->dm->saveType($record, false);
    }

    /** Ends the account's remember-me logins and voids its invite and reset links (what disabling ends besides the sessions). */
    public function endAccess(DynamicRecord $staff): void
    {
        $links = $this->links();
        if ($links?->provider($staff) === null) {
            return;
        }
        $links->forgetDevices($staff);
        $links->revoke($staff, self::INVITE, self::RESET);
    }

    /**
     * The rules of a save, on the record as it would be saved: fills in what a new account starts
     * with, lowercases the e-mail and, when the save disables a stored active account, gives it
     * the next epoch (its live sessions end once saved; endAccess() ends the rest), then refuses
     * what the rules don't allow. Every write path runs it: this class's, an admin's form save.
     *
     * @throws StaffRefused
     */
    public function checkSave(DynamicRecord $record, ?Authenticatable $actor = null): void
    {
        $id = (string) $record->getId();
        $stored = $id !== '' ? $this->dm->findType(StaffManagers::TYPE, $id) : null;

        $email = mb_strtolower(trim((string) $record->get('email')));
        $record->set('email', $email);
        if ($email !== '' && $this->emailTaken($email, $id)) {
            throw $this->refused(StaffRefused::EMAIL_TAKEN, 'staff.email_taken', field: 'email');
        }
        // Someone signed in may not give an account a config user's address: the record would then
        // decide that sign-in (a disabled one locks the config login out). The console (the
        // import) moves exactly those addresses.
        if ($email !== '' && $email !== ($stored === null ? null : mb_strtolower(trim((string) $stored->get('email'))))
            && $actor !== null && ConfigUsers::hasEmail($this->users(), $email)) {
            throw $this->refused(StaffRefused::CONFIG_EMAIL, 'staff.config_email', field: 'email');
        }
        if ($stored === null) {
            // A new account can sign in (the field default; code may leave it out), from epoch "0".
            if ($record->get('active') === null) {
                $record->set('active', true);
            }
            if (in_array($record->get('auth_epoch'), [null, ''], true)) {
                $record->set('auth_epoch', '0');
            }
            if (in_array($record->get('created_at'), [null, ''], true)) {
                $record->set('created_at', (new \DateTimeImmutable())->format(DATE_ATOM));
            }
        } elseif (StaffManagers::active($stored->get('active')) && !StaffManagers::active($record->get('active'))) {
            // The stored epoch's next, so a second check of the same save doesn't count twice.
            $record->set('auth_epoch', (string) ((int) $stored->get('auth_epoch') + 1));
        }

        $roles = (new RoleStore($this->dm))->rolesFor(StaffManagers::TYPE);
        $before = $stored !== null ? StaffManagers::account($stored) : ['active' => false, 'roles' => []];
        $after = StaffManagers::account($record);
        // A role is added only by a name a staff role has now: an undefined name would grant
        // whatever a role created under it later gets, past every delegation check. Names
        // already stored may stay.
        $unknown = array_values(array_filter(array_diff($after['roles'], $before['roles']), static fn (string $name): bool => !isset($roles[$name])));
        if ($unknown !== []) {
            throw $this->refused(StaffRefused::UNKNOWN_ROLE, 'delegation.unknown', ['names' => implode(', ', $unknown)], 'roles');
        }
        $this->delegation($actor, $before['roles'], $after['roles'], $roles);

        $accounts = (new StaffManagers($this->dm))->accounts();
        $before = $accounts[$id] ?? $before;
        $managers = StaffManagers::count($accounts, $roles);
        $accounts[$id] = $after;
        if ($managers > 0 && StaffManagers::count($accounts, $roles) === 0) {
            throw $this->refused(StaffRefused::LAST_MANAGER, 'delegation.last', field: $before['active'] && !$after['active'] ? 'active' : 'roles');
        }
    }

    /**
     * The rules of a delete: not an account above the actor, not the last manager.
     *
     * @throws StaffRefused
     */
    public function checkDelete(DynamicRecord $record, ?Authenticatable $actor = null): void
    {
        $id = (string) $record->getId();
        $roles = (new RoleStore($this->dm))->rolesFor(StaffManagers::TYPE);
        $accounts = (new StaffManagers($this->dm))->accounts();
        $stored = $accounts[$id] ?? StaffManagers::account($record);
        $held = $actor === null ? null : $this->coverage->heldBy($actor);
        if ($held !== null && !PermissionCoverage::coversAll($held, StaffManagers::patterns($stored['roles'], $roles))) {
            throw $this->refused(StaffRefused::ACCOUNT_ABOVE, 'delegation.account');
        }

        $managers = StaffManagers::count($accounts, $roles);
        unset($accounts[$id]);
        if ($managers > 0 && StaffManagers::count($accounts, $roles) === 0) {
            throw $this->refused(StaffRefused::LAST_MANAGER, 'delegation.last');
        }
    }

    /**
     * Refuses when the actor doesn't cover the account's stored roles, or a role added or removed.
     *
     * @param list<string> $before the stored role names ([] for a new account)
     * @param list<string> $after
     * @param array<string, list<string>> $roles staff role name => patterns
     */
    private function delegation(?Authenticatable $actor, array $before, array $after, array $roles): void
    {
        if ($actor === null) {
            return;
        }
        $held = $this->coverage->heldBy($actor);
        if (!PermissionCoverage::coversAll($held, StaffManagers::patterns($before, $roles))) {
            throw $this->refused(StaffRefused::ACCOUNT_ABOVE, 'delegation.account', field: 'roles');
        }
        $changed = [...array_diff($after, $before), ...array_diff($before, $after)];
        $uncovered = PermissionCoverage::uncovered($held, StaffManagers::patterns(array_values($changed), $roles));
        if ($uncovered !== []) {
            throw $this->refused(StaffRefused::GRANT, 'delegation.grant', ['patterns' => implode(', ', $uncovered)], 'roles');
        }
    }

    /** The link with a new token; when no link can be built, the token is voided again (with the account's others of its purpose). */
    private function link(AccountLinks $links, StaffLinks $page, DynamicRecord $staff, string $purpose, int $ttl): string
    {
        $token = $links->issue($staff, $purpose, $ttl);
        try {
            return $page->setPasswordUrl($token);
        } catch (\Throwable $e) {
            $links->revoke($staff, $purpose);

            throw $e;
        }
    }

    /** @param 'invite'|'reset' $mail */
    private function send(string $mail, DynamicRecord $staff, string $link): void
    {
        ($this->builtMail ??= ($this->mail)())->sendLater($mail, $staff, $link);
    }

    /** The page links point at; refused when there is none, or it can't build links now (before anything is issued or revoked). */
    private function page(): StaffLinks
    {
        $page = ($this->pages)() ?? throw $this->refused(StaffRefused::NO_LINK_PAGE, 'staff.no_link_page');
        if (!$page->available()) {
            throw $this->refused(StaffRefused::NO_APP_URL, 'staff.no_app_url');
        }

        return $page;
    }

    /** The staff guard's links when it signs this account in (its type is a record provider's of the guard). */
    private function requireLinks(DynamicRecord $staff): AccountLinks
    {
        $links = $this->links();
        if ($links === null || $links->provider($staff) === null) {
            throw $this->refused(StaffRefused::NO_SIGN_IN, 'staff.no_sign_in');
        }

        return $links;
    }

    /** The staff guard's password links; null without auth (no config/auth.php, no tokens). */
    private function links(): ?AccountLinks
    {
        if (!$this->linksBuilt) {
            $this->linksBuilt = true;
            try {
                $this->builtLinks = ($this->links)();
            } catch (\Throwable) {
                $this->builtLinks = null;
            }
        }

        return $this->builtLinks;
    }

    /** The account as stored now, so a copy read earlier doesn't write back a change made meanwhile. */
    private function fresh(DynamicRecord $staff): DynamicRecord
    {
        $record = $this->dm->findType(StaffManagers::TYPE, (string) $staff->getId());

        return $record instanceof DynamicRecord ? $record : throw $this->refused(StaffRefused::GONE, 'staff.gone');
    }

    private function requireType(): void
    {
        if (!$this->enabled()) {
            throw $this->refused(StaffRefused::NO_STAFF_TYPE, 'staff.no_type');
        }
    }

    /** The address as stored: trimmed, lowercased; refused when it isn't one. */
    private function email(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->refused(StaffRefused::EMAIL_INVALID, 'staff.email_invalid', field: 'email');
        }

        return $email;
    }

    private function emailTaken(string $email, string $exceptId): bool
    {
        foreach ($this->dm->queryType(StaffManagers::TYPE)->where('email', $email)->get()->items() as $other) {
            if ((string) $other->getId() !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string|int> $params */
    private function refused(string $reason, string $key, array $params = [], ?string $field = null): StaffRefused
    {
        return new StaffRefused($reason, $this->messages->get($key, $params), $field);
    }
}
