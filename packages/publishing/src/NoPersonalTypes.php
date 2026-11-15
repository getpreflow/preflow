<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/** No type holds people: the default PersonalTypes. @since 0.77 */
final class NoPersonalTypes implements PersonalTypes
{
    public function personal(string $type): bool
    {
        return false;
    }
}
