<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

/**
 * Turns a mail's content into its HTML part. Core binds TemplateMailLayout with a Twig engine, or with
 * any engine when `mail.layout` is set, else BasicMailLayout; a site may bind its own.
 *
 * The Mailer calls html() inside its ComposeScope (since 0.81.0). With preflow/core that is a render
 * of its own in the recipient's context: the locale (current_locale() in a template), the texts and
 * the signed-in user are the reader's (nobody), not those of the request that sends the mail. A layout
 * of your own reads the language from $content->lang or the current render, never from a service it
 * kept from elsewhere, and what it collects or queues stays out of the request.
 */
interface MailLayout
{
    /**
     * @param string      $accent   the colour of the bar, the links and the button
     * @param string|null $template the layout template to render; null for the layout's own design
     */
    public function html(MailContent $content, string $accent, ?string $template = null): string;
}
