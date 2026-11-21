<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * A frame slot (RenderFrame::slot()) that settles what it holds when its frame closes, by the
 * frame's outbox policy: CookieQueue hands the cookies a delivering frame left to the frame around
 * it, keeps a capturing frame's as captured 'cookies' items and drops a discarding frame's.
 * Contexts calls settle() on a frame that closes normally, before its outbox policy applies (so
 * what it captures there is reported, what it hands on travels on); a frame whose render threw is
 * not settled: it leaves nothing behind. Settlement runs once over the slots the frame holds when
 * it closes: a slot created in the closing frame during settlement is not settled, so a settler
 * writes what it hands on to $parent (its slot or its outbox), never to the closing frame's slots.
 *
 * @since 0.82.0
 */
interface SettlesOnClose
{
    /** $frame is closing (still the current frame); $parent is the frame around it, null for the outermost. */
    public function settle(RenderFrame $frame, ?RenderFrame $parent): void;
}
