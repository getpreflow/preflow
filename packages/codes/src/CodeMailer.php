<?php

declare(strict_types=1);

namespace Preflow\Codes;

use Preflow\Data\Locales;
use Preflow\I18n\Translator;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportException;
use Preflow\View\TemplateEngineInterface;

/**
 * Sends a code as a personal link: a plain text part and an HTML part, the words from the lang files
 * (codes-mail.invite.*) in the recipient's language. The link is made absolute from app.url, never from the request.
 * The sender's name and the message are text: escaped in the HTML, collapsed or stripped of control characters,
 * and never part of a header.
 *
 * config/codes.php mail: layout (the HTML template, default @codes/mail/layout.twig), from ("Name <address>",
 * default the mail channel's own), accent (link colour).
 */
final class CodeMailer
{
    /** @param array<string, mixed> $config config/codes.php */
    public function __construct(
        private readonly Notifier $notifier,
        private readonly TemplateEngineInterface $engine,
        private readonly Translator $translator,
        private readonly Locales $locales,
        private readonly string $appUrl,
        private readonly array $config = [],
    ) {}

    /**
     * @param string $url a path ("/invite/x", made absolute with app.url) or an absolute http(s) URL
     * @param array{subject?: string, message?: string, from_name?: string, language?: string, template?: string, vars?: array<string, mixed>} $options
     * @return bool false when the transport failed (logged without the address); the code is untouched either way
     * @throws \InvalidArgumentException for any other URL
     */
    public function send(CodeRecord $code, string $to, string $url, array $options = []): bool
    {
        $link = $this->absolute($url);
        $lang = isset($options['language']) && $this->locales->has($options['language']) ? $options['language'] : $this->locales->default;
        $fromName = self::oneLine((string) ($options['from_name'] ?? ''));
        $message = self::plain((string) ($options['message'] ?? ''));
        $subject = self::oneLine((string) ($options['subject'] ?? '')) ?: self::oneLine($this->t('subject', $lang));

        $parts = [$fromName === '' ? $this->t('intro_anonymous', $lang) : $this->t('intro', $lang, ['from_name' => $fromName])];
        if (trim($message) !== '') {
            $parts[] = $message;
        }
        $parts[] = $this->t('open', $lang, ['link' => $link]);
        $parts[] = $code->expiresAt === null
            ? $this->t('personal', $lang)
            : $this->t('validity', $lang, ['expires' => $this->date($code->expiresAt, $lang)]);
        $text = implode("\n\n", $parts);

        $layout = $options['template'] ?? $this->mail('layout');
        $email = new Email(
            to: $to,
            subject: $subject,
            text: $text,
            html: $this->engine->render(is_string($layout) && $layout !== '' ? $layout : '@codes/mail/layout.twig', [
                'lang' => $lang,
                'subject' => $subject,
                'accent' => $this->accent(),
                'paragraphs' => $this->paragraphs($text),
                'action' => ['label' => $this->t('action', $lang), 'url' => $link],
                'message' => $message,
                'from_name' => $fromName,
            ] + (array) ($options['vars'] ?? [])),
            from: $this->sender(),
        );

        try {
            $this->notifier->send($email);
        } catch (TransportException $e) {
            error_log('codes: invitation mail failed (' . $e::class . ')');

            return false;
        }

        return true;
    }

    private function absolute(string $url): string
    {
        if (preg_match('/[\p{Cc}\p{Cf}\s]/u', $url) !== 1) {
            if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\')) {
                return rtrim($this->appUrl, '/') . $url;
            }
            $parts = parse_url($url);
            if (is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) && ($parts['host'] ?? '') !== '') {
                return $url;
            }
        }

        throw new \InvalidArgumentException('The link must be a path or an absolute http(s) URL.');
    }

    /** @param array<string, string> $vars */
    private function t(string $key, string $lang, array $vars = []): string
    {
        $map = [];
        foreach ($vars as $name => $value) {
            $map['{' . $name . '}'] = $value;
        }

        return strtr($this->translator->get('codes-mail.invite.' . $key, [], $lang), $map);
    }

    /** The date in the mail's language, MEDIUM, no time, in the site's timezone. */
    private function date(\DateTimeImmutable $at, string $lang): string
    {
        $at = $at->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        if (class_exists(\IntlDateFormatter::class)) {
            $formatted = (new \IntlDateFormatter($lang, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get()))->format($at);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return $at->format('Y-m-d');
    }

    private function mail(string $key): mixed
    {
        $mail = $this->config['mail'] ?? null;

        return is_array($mail) ? ($mail[$key] ?? null) : null;
    }

    private function accent(): string
    {
        $accent = $this->mail('accent');

        return is_string($accent) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent) === 1 ? $accent : '#d42a34';
    }

    /** The sender from config/codes.php, else null: the mail channel's own. */
    private function sender(): ?string
    {
        $from = $this->mail('from');

        return is_string($from) && trim($from) !== '' ? self::oneLine($from) : null;
    }

    /** Control characters (CR/LF included) collapse to one space: a value must not break a header line. */
    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $value));
    }

    /** Free text: line feeds kept, every other control character dropped. */
    private static function plain(string $value): string
    {
        return (string) preg_replace('/(?!\n)[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', '', $value);
    }

    /** @return list<string> safe HTML: escaped paragraphs with URLs linked and single line breaks kept */
    private function paragraphs(string $text): array
    {
        $accent = htmlspecialchars($this->accent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = [];
        foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $p) {
            $html = htmlspecialchars($p, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html = (string) preg_replace('~https?://[^\s<]+~', '<a href="$0" style="color:' . $accent . '">$0</a>', $html);
            $out[] = nl2br($html, false);
        }

        return $out;
    }
}
