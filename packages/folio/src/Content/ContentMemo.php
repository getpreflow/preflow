<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

/**
 * What Collections and Singletons read during one render frame, by "{kind}:{type}|{memoKey}"
 * (RenderContext::memoKey()): a stretch of the frame in another context (within() working
 * copies, another locale) reads anew, and every frame (a request, an internal render) starts
 * empty. A frame slot (RenderFrame::slot()).
 *
 * @internal Collections and Singletons
 * @since 0.81.0
 */
final class ContentMemo
{
    /** @var array<string, array{mixed, list<array<int, mixed>>}> key => [value, the reads behind it (DataManager::captureReads())] */
    public array $entries = [];
}
