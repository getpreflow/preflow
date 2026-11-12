<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

/**
 * The neutral HTML layout in plain PHP, for apps without a template engine: a white page, 560px
 * wide, a 3px accent bar, the paragraphs, and the action as a button with its URL below.
 * The same markup as @notify/mail/layout.twig. It has no templates: $template is ignored.
 */
final class BasicMailLayout implements MailLayout
{
    public function html(MailContent $content, string $accent, ?string $template = null): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $accentAttr = $e($accent);

        $body = '';
        foreach (MailText::paragraphs($content->text, $accent, $content->links) as $p) {
            $body .= '<p style="margin:0 0 16px;color:#1a1a1a">' . $p . "</p>\n";
        }
        if ($content->action !== null) {
            $url = $e($content->action->url);
            $body .= '<p style="margin:24px 0 8px"><a data-mail-action href="' . $url . '" style="display:inline-block;background:' . $accentAttr
                . ';color:#ffffff;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:4px">' . $e($content->action->label) . "</a></p>\n"
                . '<p data-mail-action-url style="margin:0 0 16px;font-size:13px;color:#666666;word-break:break-all">' . $url . "</p>\n";
        }

        return "<!doctype html>\n"
            . '<html lang="' . $e($content->lang) . "\">\n"
            . '<head><meta charset="utf-8"><title>' . $e(MailText::oneLine($content->subject)) . "</title></head>\n"
            . "<body style=\"margin:0;padding:0;background:#ffffff;color:#1a1a1a;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:16px;line-height:1.55\">\n"
            . "<div style=\"max-width:560px;margin:0 auto;padding:32px 24px\">\n"
            . '<div style="height:3px;background:' . $accentAttr . ";margin-bottom:24px\"></div>\n"
            . $body
            . "</div>\n"
            . "</body>\n"
            . "</html>\n";
    }
}
