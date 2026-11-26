<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

/**
 * What a link points to: a record of the site (its type and id), a web address, an email
 * address, a phone number, or a file of the media library (its asset id).
 *
 * @since 0.91.0
 */
enum LinkKind: string
{
    case Record = 'record';
    case Url = 'url';
    case Email = 'email';
    case Phone = 'phone';
    case Asset = 'asset';

    /** @return list<string> every kind's stored name, in this order */
    public static function names(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
