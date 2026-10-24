<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Auth\ChainUserProvider;
use Preflow\Auth\LazyUserProvider;
use Preflow\Auth\UserProviderInterface;

/**
 * The record providers (Folio user types) behind a guard's provider: the provider itself, the
 * members of a chain (in lookup order, nested chains too), built lazy providers. One whose user
 * source is unavailable (its type's extension is off) counts as none.
 */
final class RecordProviders
{
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
