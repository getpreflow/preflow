<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Core\Exceptions\ForbiddenHttpException;

/**
 * An adapter was asked for an action the type does not allow (TypeDefinition::assertAllows()):
 * a 403, so an API answers it as JSON and a page as the error page, without each adapter mapping it.
 *
 * @since 0.78
 */
final class TypeActionRefused extends ForbiddenHttpException
{
    public function __construct(
        public readonly string $type,
        public readonly TypeAction $action,
        bool $readonly,
    ) {
        parent::__construct($readonly
            ? "\"{$type}\" is read-only: its records are not created, changed or deleted here."
            : "Records of \"{$type}\" are not created here.");
    }
}
