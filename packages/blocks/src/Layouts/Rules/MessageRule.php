<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A rule that always fails with a message found elsewhere, so a validator words it in its
 * language: how the layout checks hand a problem they found to the validator.
 *
 * @internal
 * @since 0.92.0
 */
final readonly class MessageRule implements ValidationRule
{
    public function __construct(private Message $message) {}

    public function validate(mixed $value, ValidationContext $context): Message
    {
        return $this->message;
    }
}
