<?php

declare(strict_types=1);

namespace Preflow\Ai\Draft;

use Preflow\Ai\AiUnavailable;

/**
 * Writes a first draft of a text from a short brief. Callers depend on this, not on a provider, so
 * tests and sites can swap it (config/ai.php `draft.writer`). A writer answers its own availability,
 * so one that is no AI needs no AI key. AiDraftWriter, the default, writes through preflow/ai and
 * whichever provider it is set to.
 *
 * @since 0.77
 */
interface DraftWriter
{
    /**
     * Whether it can write now (configured, its key present). False hides Folio's "Write a draft".
     * Cheap and never throws: it is asked on admin requests.
     */
    public function available(): bool;

    /**
     * The draft as HTML. The HTML is the writer's answer and not trusted: a caller that shows it
     * sanitises it first.
     *
     * @throws AiUnavailable the writer cannot be used at all
     * @throws DraftFailed this draft could not be written (no answer, cut off, refused)
     */
    public function write(DraftRequest $request): Draft;
}
