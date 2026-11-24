<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\Core\Exceptions\ForbiddenHttpException;

/**
 * Carried state that cannot be used: tampered, of another component, expired, unreadable, or of
 * a shape the component no longer has. Answers 403 like a refused action token.
 *
 * SecurityException is final, so this extends its parent, ForbiddenHttpException.
 *
 * @since 0.86.0
 */
final class StateRefused extends ForbiddenHttpException
{
    public function __construct(string $message = 'Component state refused.', ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }

    /**
     * State of $component's class that no longer fits its #[State] properties. The message names
     * only the class's short name (it may reach the visitor); what didn't fit is in $previous.
     *
     * @since 0.86.0
     */
    public static function noLongerFits(object $component, \Throwable $previous): self
    {
        return new self(sprintf('The state of %s no longer fits; reload the page.', self::shortName($component)), $previous);
    }

    /**
     * State given to $component, which keeps none (no #[State] property).
     *
     * @since 0.86.0
     */
    public static function keepsNone(object $component): self
    {
        return new self(sprintf('%s keeps no state.', self::shortName($component)));
    }

    private static function shortName(object $component): string
    {
        return (new \ReflectionClass($component))->getShortName();
    }
}
