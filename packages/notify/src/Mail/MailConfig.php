<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

/**
 * The mail settings, config/notify.php 'mail': accent (a hex colour, default the neutral
 * #1a1a1a), layout (the layout template, default the layout's own), from ("Name <address>",
 * default the email channel's own).
 */
final class MailConfig
{
    /** @internal what an accent must look like: a hex colour */
    public const ACCENT = '/^#[0-9a-fA-F]{3,8}$/';

    public function __construct(
        public readonly string $accent = '#1a1a1a',
        public readonly ?string $layout = null,
        public readonly ?string $from = null,
    ) {}

    /** @param array<string, mixed> $notifyMail config/notify.php 'mail'; an invalid or empty value keeps its default */
    public static function fromArray(array $notifyMail): self
    {
        $accent = $notifyMail['accent'] ?? null;
        $layout = $notifyMail['layout'] ?? null;
        $from = $notifyMail['from'] ?? null;

        return new self(
            accent: is_string($accent) && preg_match(self::ACCENT, $accent) === 1 ? $accent : '#1a1a1a',
            layout: is_string($layout) && trim($layout) !== '' ? trim($layout) : null,
            from: is_string($from) && MailText::oneLine($from) !== '' ? MailText::oneLine($from) : null,
        );
    }
}
