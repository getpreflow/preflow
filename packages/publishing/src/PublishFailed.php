<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Validation\ValidationException;

/**
 * A RecordWriter save stored the working copy, but its publish failed: the checks refused the
 * record (a ValidationException) or a save landed between the draft and the publish (a
 * VersionConflict), which getPrevious() holds. $outcome is the stored draft; SaveHooks::after()
 * has run for it.
 */
final class PublishFailed extends \RuntimeException
{
    public function __construct(
        public readonly SaveOutcome $outcome,
        ValidationException|VersionConflict $cause,
    ) {
        parent::__construct('The draft was saved, but it could not be published: ' . $cause->getMessage(), 0, $cause);
    }

    public function cause(): ValidationException|VersionConflict
    {
        $cause = $this->getPrevious();
        \assert($cause instanceof ValidationException || $cause instanceof VersionConflict);

        return $cause;
    }
}
