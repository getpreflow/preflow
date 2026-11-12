<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

/**
 * Turns a mail's content into its HTML part. Core binds TemplateMailLayout when a template engine
 * (preflow/view) is bound, else BasicMailLayout; a site may bind its own.
 */
interface MailLayout
{
    /**
     * @param string      $accent   the colour of the bar, the links and the button
     * @param string|null $template the layout template to render; null for the layout's own design
     */
    public function html(MailContent $content, string $accent, ?string $template = null): string;
}
