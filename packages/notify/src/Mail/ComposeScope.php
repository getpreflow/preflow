<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

/**
 * Where the Mailer composes a mail's HTML part: around the layout's render, for a reader of
 * $lang. notify's own DirectCompose just runs it (the layout gets the language as its lang
 * variable); an app passes one that renders in the recipient's context (texts, locale), so a mail
 * to a French reader sent from a German admin screen is French.
 *
 * @since 0.81.0
 */
interface ComposeScope
{
    /**
     * Runs $compose for a reader of $lang and returns what it returns.
     *
     * @template T
     * @param \Closure(): T $compose
     * @return T
     */
    public function run(string $lang, \Closure $compose): mixed;
}
