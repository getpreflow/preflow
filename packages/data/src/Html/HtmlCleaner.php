<?php

declare(strict_types=1);

namespace Preflow\Data\Html;

/**
 * Cleans HTML a client wrote (rich text) to safe HTML: no scripts, no event handlers, no unsafe
 * links. Every typed write runs it on rich text before the driver (DataManager::storageValues()),
 * and the read side runs it again. The default is SymfonyHtmlCleaner::standard(); a site binds its
 * own in the container (Application hands the binding to DataManager::htmlCleanerFrom()).
 *
 * clean() must be idempotent: clean(clean($html)) === clean($html), so a record saved again keeps
 * its bytes and its change date.
 *
 * @since 0.93.0
 */
interface HtmlCleaner
{
    public function clean(string $html): string;
}
