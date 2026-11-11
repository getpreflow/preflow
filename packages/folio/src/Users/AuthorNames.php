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
 * stored. A disabled account still resolves, since it did the work.
 */
final class AuthorNames
{
    /** @var array<string, string> */
    private array $known = [];

    /** @var array<string, list<array<int, mixed>>> the reads behind each known label (DataManager::captureReads()) */
    private array $reads = [];

    /**
     * @param \Closure(): list<RecordUserProvider>              $providers the guard's record providers, asked on first use
     * @param (\Closure(): ?UserProviderInterface)|null        $users     the guard's provider, for staff that are no records
     *                                                                     (a model behind DataManagerUserProvider): asked by id
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly \Closure $providers,
        private readonly ?\Closure $users = null,
    ) {}

    public function label(?string $by): ?string
    {
        if ($by === null || $by === '' || str_contains($by, '@')) {
            return $by;
        }
        if (isset($this->known[$by])) {
            $this->dm->replayReads($this->reads[$by] ?? []); // still a read (page cache)

            return $this->known[$by];
        }
        [$label, $this->reads[$by]] = $this->dm->captureReads(fn (): string => $this->lookup($by));

        return $this->known[$by] = $label;
    }

    private function lookup(string $by): string
    {
        foreach (($this->providers)() as $provider) {
            $record = $this->dm->findType($provider->type(), $by);
            if (!$record instanceof DynamicRecord) {
                continue;
            }
            foreach (['name', $provider->fields()['email']] as $field) {
                $value = $record->get($field);
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }
        $user = $this->users === null ? null : ($this->users)()?->findById($by);

        return $user instanceof Authenticatable ? (self::named($user) ?? $by) : $by;
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
