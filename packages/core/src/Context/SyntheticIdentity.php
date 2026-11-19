<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

use Preflow\Core\Identity;

/**
 * The identity of someone viewing as a role: no stored account. Its id,
 * 'preflow:synthetic:{population}:{sorted roles joined by ","}', is reserved: auth's guards never
 * resolve an id that starts with PREFIX, so it cannot stand for a real account.
 *
 * @since 0.81.0
 */
final readonly class SyntheticIdentity implements Identity
{
    public const PREFIX = 'preflow:synthetic:';

    public function __construct(public RoleView $view) {}

    public function getAuthId(): string
    {
        return self::PREFIX . $this->view->population . ':' . implode(',', $this->view->roles);
    }
}
