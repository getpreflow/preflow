<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

/**
 * What a composed mail says: its subject, the plain text (paragraphs separated by a blank line, the
 * text part as is and the HTML part's paragraphs), the language for the HTML's lang attribute, an
 * optional primary action, extra variables for a site's own layout template, and which URLs the HTML
 * part links.
 */
final readonly class MailContent
{
    /**
     * @param array<string, mixed> $vars  extra template variables (TemplateMailLayout)
     * @param list<string>|null    $links the URLs the HTML part may link (exact text); null links every
     *                                    http(s) URL, [] none — so URLs in someone's free text can stay plain
     */
    public function __construct(
        public string $subject,
        public string $text,
        public string $lang = 'en',
        public ?MailAction $action = null,
        public array $vars = [],
        public ?array $links = null,
    ) {}
}
