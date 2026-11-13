<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DeletePlan;
use Preflow\Data\DynamicRecord;
use Preflow\Validation\ValidationException;

/**
 * RecordDeleter wrote nothing: records still point at what it would delete through a "restrict"
 * relation ($plan->restricted(), $plan->restrictions()), or the save of a record losing its link
 * was refused by SaveHooks::before() ($record, with the ValidationException as $validation).
 * The message is for developers; an admin words it for editors (Folio: DeleteSummary::refusal()).
 */
final class DeleteRefused extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly DeletePlan $plan,
        public readonly ?DynamicRecord $record = null,
        public readonly ?ValidationException $validation = null,
    ) {
        parent::__construct($message, 0, $validation);
    }

    public static function restricted(DeletePlan $plan): self
    {
        $counts = [];
        foreach ($plan->restrictions() as $type => $n) {
            $counts[] = "{$n} {$type}";
        }

        return new self('Restricted: records still point here (' . implode(', ', $counts) . ').', $plan);
    }

    public static function detachRefused(DeletePlan $plan, DynamicRecord $record, ValidationException $e): self
    {
        return new self("The {$record->getType()->key} {$record->getId()} that loses its link refused the save.", $plan, $record, $e);
    }
}
