<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** A redirect entry the rules refuse to store. */
final class RedirectRejected extends \InvalidArgumentException
{
    public function __construct(public readonly RedirectErrors $errors)
    {
        parent::__construct('Redirect refused: ' . implode(', ', array_map(
            static fn (string $field, string $key): string => "{$field} ({$key})",
            array_keys($errors->all()),
            $errors->all(),
        )));
    }
}
