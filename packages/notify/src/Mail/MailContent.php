<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

/**
 * What a composed mail says: its subject, the plain text (paragraphs separated by a blank line, the
 * text part as is and the HTML part's paragraphs), the language for the HTML's lang attribute, an
 * optional primary action, and extra variables for a site's own layout template.
 */
final readonly class MailContent
{
    /** @param array<string, mixed> $vars extra template variables (TemplateMailLayout) */
    public function __construct(
        public string $subject,
        public string $text,
        public string $lang = 'en',
        public ?MailAction $action = null,
        public array $vars = [],
    ) {}
}
