<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\Record\RecordUserProvider;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

/**
 * Who a stored "created by" value is: the id of a record user of the staff guard shows that
 * record's name (else its e-mail), as does the id of another user its provider finds (a model's
 * public name or email, or getName()/getEmail()); an e-mail (config era) or an id nobody has any more shows as
 * stored by label(), while person() (the activity log, since 0.77) names such an id "Former user". A disabled account still resolves, since it did the work. A marker registered with
 * core's ActivityActors ("member-deletion") is no person: it shows its label, translated
 * (since 0.77).
 */
final class AuthorNames
{
    /** @var array<string, ?string> a person's label by id, null for an id no provider finds */
    private array $known = [];

    /** @var array<string, list<array<int, mixed>>> the reads behind each known label (DataManager::captureReads()) */
    private array $reads = [];

    /** The label of an id no provider finds, in person(). @since 0.77 */
    public const string FORMER_USER = 'folio.activity.former_user';

    /**
     * @param \Closure(): list<RecordUserProvider>              $providers the guard's record providers, asked on first use
     * @param (\Closure(): ?UserProviderInterface)|null        $users     the guard's provider, for staff that are no records
     *                                                                     (a model behind DataManagerUserProvider): asked by id
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly \Closure $providers,
        private readonly ?\Closure $users = null,
        private readonly ?\Preflow\Core\Activity\ActivityActors $actors = null,
    ) {}

    /** The stored value's label: a name, a marker's label, else the value as stored (older rows hold names). */
    public function label(?string $by): ?string
    {
        if ($by === null || $by === '') {
            return $by;
        }

        return $this->resolved($by) ?? $by;
    }

    /**
     * Who an activity log row is by (its $by: a user id or a marker, since 0.77): as label(), but
     * an id no provider finds is a neutral "Former user" (a deleted account), never the raw id.
     * Null for none (null, '': an erased person).
     *
     * @since 0.77
     */
    public function person(?string $by): ?string
    {
        if ($by === null || $by === '') {
            return null;
        }

        return $this->resolved($by) ?? \Preflow\Folio\FolioText::get(self::FORMER_USER);
    }

    /** An e-mail as it is, a marker's label, a found user's name; null when no provider finds the id. */
    private function resolved(string $by): ?string
    {
        if (str_contains($by, '@')) {
            return $by;
        }
        $marker = $this->actors?->label($by);
        if ($marker !== null) {
            return \Preflow\Folio\FolioText::label($marker);
        }
        if (array_key_exists($by, $this->known)) {
            $this->dm->replayReads($this->reads[$by] ?? []); // still a read (page cache)

            return $this->known[$by];
        }
        [$label, $this->reads[$by]] = $this->dm->captureReads(fn (): ?string => $this->lookup($by));

        return $this->known[$by] = $label;
    }

    /** The user's name (else e-mail; a user found without either: the id), null when no provider has the id. */
    private function lookup(string $by): ?string
    {
        $found = false;
        foreach (($this->providers)() as $provider) {
            $record = $this->dm->findType($provider->type(), $by);
            if (!$record instanceof DynamicRecord) {
                continue;
            }
            $found = true;
            foreach (['name', $provider->fields()['email']] as $field) {
                $value = $record->get($field);
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }
        $user = $this->users === null ? null : ($this->users)()?->findById($by);
        if ($user instanceof Authenticatable) {
            return self::named($user) ?? $by;
        }

        return $found ? $by : null; // a user without a name or e-mail shows its id
    }

    /** A user's name, else its e-mail: a getName()/getEmail() method or a public property (a model's). */
    private static function named(Authenticatable $user): ?string
    {
        $public = get_object_vars($user);
        foreach (['name', 'email'] as $field) {
            $getter = 'get' . ucfirst($field);
            $value = method_exists($user, $getter) ? $user->{$getter}() : ($public[$field] ?? null);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
