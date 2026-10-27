<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Data\DeletePlan;

/**
 * What Members::delete() did: deleted (the plan was carried out), or refused (a self rule says
 * restrict, or a DELETING listener said no; nothing changed). told: on a refusal, the site knows —
 * the owner's notice went out, or a DELETION_REFUSED listener said it told the site.
 */
final readonly class DeletionResult
{
    public function __construct(
        public bool $deleted,
        public bool $refused,
        public bool $told,
        public DeletePlan $plan,
        /** On a refusal: 'restricted' or 'listener'; '' when deleted. */
        public string $reason = '',
    ) {}
}
