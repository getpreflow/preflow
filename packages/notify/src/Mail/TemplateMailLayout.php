<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

use Preflow\View\TemplateEngineInterface;

/**
 * Renders the HTML part with a template (needs preflow/view): the given one, else
 * @notify/mail/layout.twig, the neutral design. The template gets lang, subject, accent,
 * paragraphs (safe HTML: escaped text, URLs linked), action ({label, url}, when the mail has one)
 * and the content's vars.
 */
final class TemplateMailLayout implements MailLayout
{
    public function __construct(
        private readonly TemplateEngineInterface $engine,
        private readonly string $defaultTemplate = '@notify/mail/layout.twig',
    ) {}

    public function html(MailContent $content, string $accent, ?string $template = null): string
    {
        return $this->engine->render($template ?? $this->defaultTemplate, [
            'lang' => $content->lang,
            'subject' => MailText::oneLine($content->subject),
            'accent' => $accent,
            'paragraphs' => MailText::paragraphs($content->text, $accent, $content->links),
        ] + ($content->action === null ? [] : ['action' => ['label' => $content->action->label, 'url' => $content->action->url]])
            + $content->vars);
    }
}
