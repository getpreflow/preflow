<?php

declare(strict_types=1);

namespace Preflow\Members;

/**
 * Members::register() for an address that already has an account (compared normalised): one
 * account per address, as sign-in and the record user provider expect. A caller checks first
 * with Members::findByEmail() or catches this.
 */
final class EmailTakenException extends \DomainException
{
    public function __construct(
        public readonly string $email,
    ) {
        parent::__construct('Members::register(): the address already has an account.');
    }
}
