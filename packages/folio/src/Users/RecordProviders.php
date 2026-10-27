<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Auth\ChainUserProvider;
use Preflow\Auth\LazyUserProvider;
use Preflow\Auth\Record\RecordUserProvider;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TypeCatalog;

/**
 * The record providers (Folio user types) behind a guard's provider: the provider itself, the
 * members of a chain (in lookup order, nested chains too), built lazy providers. One whose user
 * source is unavailable (its type's extension is off) counts as none.
 */
final class RecordProviders
{
    /**
     * The type lookup Folio gives a RecordUserProvider: a type of the catalog (model files and enabled
     * extensions' models), defined by $registry; else a type only $registry defines (a package's own,
     * as preflow/members' "member"); null when neither has it. A catalog type that is not a user
     * type ("users" in its model) throws.
     *
     * @return \Closure(string): ?TypeDefinition
     */
    public static function types(TypeCatalog $catalog, TypeRegistry $registry): \Closure
    {
        return static function (string $type) use ($catalog, $registry): ?TypeDefinition {
            $listing = $catalog->get($type);
            if ($listing === null) {
                // A type Folio does not list but a package defines (preflow/members' "member" while
                // Folio's members extension is off): the data registry's. A disabled extension's own
                // types are not defined there, so they stay unavailable.
                return $registry->has($type) ? $registry->get($type) : null;
            }
            if ($listing->users === null) {
                throw new \RuntimeException("User provider: type \"{$type}\" is not a user type (set \"users\" in its model).");
            }

            return $registry->get($type);
        };
    }

    /** @return list<RecordUserProvider> */
    public static function of(?UserProviderInterface $provider): array
    {
        if ($provider instanceof LazyUserProvider) {
            $provider = $provider->inner();
        }
        if ($provider instanceof RecordUserProvider) {
            return [$provider];
        }
        if ($provider instanceof ChainUserProvider) {
            $found = [];
            foreach ($provider->providers() as $member) {
                array_push($found, ...self::of($member));
            }

            return $found;
        }

        return [];
    }
}
