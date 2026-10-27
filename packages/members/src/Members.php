<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Data\DataManager;
use Preflow\Data\DeletedBy;
use Preflow\Data\DeletePlan;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Member records: registration, confirmation, the owner's decision, deletion. Raises the
 * MemberEvents REGISTERED, DECIDED and DELETING / DELETED / DELETION_REFUSED (with the services
 * from the container; a bare Members raises none and tells no one).
 */
final class Members
{
    private const string TYPE = MembersConfig::TYPE;

    /** Fields register() fills itself; $extra must not set them. */
    private const array OWN_FIELDS = ['uuid', 'email', 'name', 'password_hash', 'language', 'roles', 'registered_at', 'email_confirmed_at', 'decided_at', 'locked_at', 'last_login_at', 'auth_epoch', 'next'];

    private readonly \Closure $now;

    /**
     * @param ?\Closure(): \DateTimeImmutable $now UTC clock, injectable for tests
     * @param ?\Closure(): Confirmation $confirmation what follows a proven address (resolved when first needed: the admission may need Members)
     * @param ?\Closure(DynamicRecord, DeletePlan, string): bool $refusalNotice tells the owner of a refused own deletion
     *        (the member, the plan, the reason); returns whether a notice went out
     * @param ?MemberDeleteCopies $copies copies of records (Folio's published snapshots) the plan counts for restrict
     * @param ?RememberMe $remember removes a member's remember rows when sessions end (without it only the epoch moves)
     * @param ?\Closure(): ConfirmationMail $confirmationMail resends a confirmation link (resolved when first needed)
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly PasswordHasherInterface $hasher,
        ?\Closure $now = null,
        private readonly ?\Closure $confirmation = null,
        private readonly ?MemberEvents $events = null,
        private readonly ?\Closure $refusalNotice = null,
        private readonly ?MemberDeleteCopies $copies = null,
        private readonly ?RememberMe $remember = null,
        private readonly ?\Closure $confirmationMail = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function findByEmail(string $email): ?DynamicRecord
    {
        $record = $this->dm->queryType(self::TYPE)->where('email', self::normalizeEmail($email))->first();

        return $record instanceof DynamicRecord ? $record : null;
    }

    /** Whether a member other than $exceptId has this address (compared normalised). */
    public function emailTaken(string $email, string $exceptId): bool
    {
        foreach ($this->dm->queryType(self::TYPE)->where('email', self::normalizeEmail($email))->get()->items() as $other) {
            if ((string) $other->getId() !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    public function find(string $id): ?DynamicRecord
    {
        return $this->dm->findType(self::TYPE, $id);
    }

    /**
     * A new, unconfirmed member without roles. $extra (from Admission::recordValues()) sets
     * further fields of the member type; a key the type lacks is a programming error.
     *
     * $emailVerified: the calling project has proven the address itself (an invitation mailed to
     * it, a sign-in elsewhere); Members never assumes so. The member is confirmed at once and the
     * admission decides as after a confirmation ({@see Confirmation}: a member, or pending with
     * the owner's notice); no confirmation mail is sent. Sign the member in with {@see SignIn}.
     * The admission's afterConfirm() gets $request, else the current request as PHP sees it ($_SERVER).
     *
     * Raises MemberEvents::REGISTERED (with $request, null when none was given), then for a
     * verified address CONFIRMED and DECIDED.
     *
     * @param array<string, mixed> $extra
     * @throws EmailTakenException when the address already has an account (check with findByEmail())
     */
    public function register(string $email, string $name, string $password, string $language, array $extra = [], bool $emailVerified = false, ?ServerRequestInterface $request = null): DynamicRecord
    {
        if ($emailVerified && $this->confirmation === null) {
            throw new \LogicException('Members::register(emailVerified: true) needs the members services (Members from the container).');
        }
        $def = ($this->dm->getTypeRegistry() ?? throw new \LogicException('No type registry.'))->get(self::TYPE);
        $email = self::normalizeEmail($email);
        if ($this->findByEmail($email) !== null) {
            throw new EmailTakenException($email);
        }
        foreach (array_keys($extra) as $key) {
            if (in_array($key, self::OWN_FIELDS, true)) {
                throw new \LogicException("Members::register(): \"{$key}\" is set by Members itself, not by the admission's recordValues().");
            }
            if (!isset($def->fields[$key])) {
                throw new \LogicException("Members::register(): \"{$key}\" is not a field of the member type; add it with a site model file member.json that says \"extends\": true.");
            }
        }
        $record = DynamicRecord::fromArray($def, [
            'uuid' => self::uuid(),
            'email' => $email,
            'name' => trim($name),
            'password_hash' => $this->hasher->hash($password),
            'language' => $language,
            'roles' => [],
            'registered_at' => $this->stamp(),
            'email_confirmed_at' => '',
            'decided_at' => '',
            'locked_at' => '',
            'last_login_at' => '',
            'auth_epoch' => '0',
        ] + $extra);
        $this->dm->saveType($record, false);
        $this->events?->dispatch(MemberEvents::REGISTERED, ['member' => $record, 'request' => $request]);
        if ($emailVerified) {
            ($this->confirmation)()->confirm($record, $request ?? self::currentRequest());
        }

        return $record;
    }

    /** The request PHP is serving, for the admission's afterConfirm() outside a controller. */
    private static function currentRequest(): ServerRequest
    {
        $server = $_SERVER;

        return new ServerRequest(
            is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET',
            is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/',
            [],
            null,
            '1.1',
            $server,
        );
    }

    public function confirm(DynamicRecord $m): void
    {
        $m->set('email_confirmed_at', $this->stamp());
        $this->save($m);
    }

    /**
     * Where to go once the address is confirmed (a checked "next", {@see Http\SafeNext}); ''
     * clears it. Stored on the member (a hidden field) until the confirmation uses it.
     */
    public function setNext(DynamicRecord $m, string $next): void
    {
        $m->set('next', $next);
        $this->save($m);
    }

    /**
     * The admission's or the owner's decision; raises MemberEvents::DECIDED.
     *
     * @param string $role member, pending or rejected
     */
    public function decide(DynamicRecord $m, string $role): void
    {
        $m->set('roles', [$role]);
        if ($role === 'member' || $role === 'rejected') {
            $m->set('decided_at', $this->stamp());
        }
        $this->save($m);
        $this->events?->dispatch(MemberEvents::DECIDED, ['member' => $m, 'role' => $role]);
    }

    /**
     * A new password ends the member's other sessions: the session epoch (auth_epoch, which the
     * members guard compares at every request) moves on. Sign the current request in again
     * afterwards, and revoke the remember rows (RememberMe::revokeAll()).
     */
    public function setPassword(DynamicRecord $m, string $password): void
    {
        $m->set('password_hash', $this->hasher->hash($password));
        $m->set('auth_epoch', (string) ((int) $m->get('auth_epoch') + 1));
        $this->save($m);
    }

    /**
     * Locks the account: it stays what it is (roles, password, state) but cannot sign in, and its
     * sessions end. A second lock keeps the first time and ends sessions again.
     */
    public function lock(DynamicRecord $m): void
    {
        if (!$this->isLocked($m)) {
            $m->set('locked_at', $this->stamp());
        }
        $this->endSessions($m);
    }

    /** Lifts the lock; ended sessions stay ended (the epoch is not rolled back). */
    public function unlock(DynamicRecord $m): void
    {
        $m->set('locked_at', '');
        $this->save($m);
    }

    public function isLocked(DynamicRecord $m): bool
    {
        return self::locked($m);
    }

    /** The one definition of "locked", for code without a Members (SignIn). */
    public static function locked(DynamicRecord $m): bool
    {
        return (string) $m->get('locked_at') !== '';
    }

    /**
     * Mails an unconfirmed member a fresh confirmation link (the earlier ones stop working), at once.
     *
     * @throws \LogicException when the member is not unconfirmed, or this Members has no ConfirmationMail
     * @throws \Preflow\Notify\TransportException when the mail cannot be sent
     */
    public function resendConfirmation(DynamicRecord $m): void
    {
        if ($this->confirmationMail === null) {
            throw new \LogicException('Members::resendConfirmation() needs the confirmation mail service; take Members from the container.');
        }
        if (MemberState::fromRecord($m) !== MemberState::Unconfirmed) {
            throw new \LogicException('Members::resendConfirmation(): only an unconfirmed member has a link to resend.');
        }
        ($this->confirmationMail)()->send($m);
    }

    /** Ends every session of the member: the epoch moves on and the remember rows go. Saves the member. */
    public function endSessions(DynamicRecord $m): void
    {
        $m->set('auth_epoch', (string) ((int) $m->get('auth_epoch') + 1));
        $this->save($m);
        $this->remember?->revokeAll(MembersConfig::GUARD, (string) $m->getId());
    }

    /** Notes a sign-in as the member's last one. */
    public function noteSignIn(DynamicRecord $m): void
    {
        $m->set('last_login_at', $this->stamp());
        $this->save($m);
    }

    /**
     * The member's own deletion: through the data delete plan with the self rules (each relation's
     * onSelfDelete; without one, its onDelete). Pointing records are detached or deleted with the
     * member (cascade), in one transaction per SQL connection (JSON-file writes are not undone).
     *
     * Refused, with nothing changed, when a self rule says restrict or a MemberEvents::DELETING
     * listener returns false. Then, when $byMember, the owner gets a notice at
     * MembersSettings::noticeEmail() (if set) and DELETION_REFUSED is raised; the result's "told"
     * says whether the site knows (the notice went out, or a listener returned true). Without
     * $byMember (members:prune, a confirm mail that failed: no one asked) a refusal tells no one.
     *
     * Raises DELETING before and DELETED after a deletion (the record as it was, the plan applied),
     * both with by_member. Copies of pointing records (MemberDeleteCopies, Folio's published
     * snapshots) count for restrict; carrying the deletion into them is their package's DELETED listener.
     */
    public function delete(DynamicRecord $m, bool $byMember = true): DeletionResult
    {
        $id = (string) $m->getId();
        $copies = $this->copies;
        $plan = $this->dm->deletePlan(self::TYPE, [$id], $copies === null ? null : static fn (TypeDefinition $def): array => $copies->of($def), DeletedBy::Self);
        if ($plan->restricted()) {
            return $this->refused($m, $plan, 'restricted', $byMember);
        }
        if ($this->events !== null && !$this->events->dispatch(MemberEvents::DELETING, ['member' => $m, 'plan' => $plan, 'by_member' => $byMember])) {
            return $this->refused($m, $plan, 'listener', $byMember);
        }
        $this->dm->executeDeletePlan($plan);
        $this->events?->dispatch(MemberEvents::DELETED, ['id' => $id, 'member' => $m, 'plan' => $plan, 'by_member' => $byMember]);

        return new DeletionResult(deleted: true, refused: false, told: false, plan: $plan);
    }

    private function refused(DynamicRecord $m, DeletePlan $plan, string $reason, bool $byMember): DeletionResult
    {
        if (!$byMember) {
            return new DeletionResult(deleted: false, refused: true, told: false, plan: $plan, reason: $reason);
        }
        $sent = $this->refusalNotice !== null && ($this->refusalNotice)($m, $plan, $reason);
        $handled = $this->events?->handled(MemberEvents::DELETION_REFUSED, [
            'member' => $m, 'plan' => $plan, 'reason' => $reason, 'notice_sent' => $sent,
        ]) ?? false;

        return new DeletionResult(deleted: false, refused: true, told: $sent || $handled, plan: $plan, reason: $reason);
    }

    /**
     * Removes a member whose confirm mail could not be sent, read afresh: one who confirmed
     * meanwhile (with the link of an earlier mail) is kept. Returns whether it was removed.
     */
    public function removeIfUnconfirmed(string $id): bool
    {
        $m = $this->find($id);
        if ($m === null || MemberState::fromRecord($m) !== MemberState::Unconfirmed) {
            return false;
        }
        return $this->delete($m, byMember: false)->deleted;
    }

    /** Confirmed accounts the owner has not decided yet. */
    public function pendingCount(): int
    {
        $count = 0;
        foreach ($this->dm->queryType(self::TYPE)->get() as $m) {
            if ($m instanceof DynamicRecord && MemberState::fromRecord($m) === MemberState::Waiting) {
                $count++;
            }
        }

        return $count;
    }

    private function save(DynamicRecord $m): void
    {
        $this->dm->saveType($m, false);
    }

    private function stamp(): string
    {
        return ($this->now)()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
