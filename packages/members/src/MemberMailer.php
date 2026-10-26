<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\I18n\Translator;
use Preflow\Members\Admission\Admission;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportException;
use Preflow\View\TemplateEngineInterface;

/**
 * Sends the member mails: plain text plus the HTML layout (config/members.php mail.layout),
 * the words from the lang files (members.mail.*) in the member's language.
 *
 * Mails a visitor triggers (confirm, existing, reset, notice) are queued with the *Later methods and go out after
 * the response, so the answer takes equally long whether or not a mail follows. A failure reaches the optional
 * $onFailure callback instead of the visitor. The owner's decision mails (accepted, declined) are sent at once:
 * a TransportException propagates, so a failed mail aborts the decision.
 */
final class MemberMailer
{
    /** @param string|null $from the sender ("Name <address>"), null for the mail channel's own */
    public function __construct(
        private readonly Notifier $notifier,
        private readonly TemplateEngineInterface $engine,
        private readonly Translator $translator,
        private readonly Links $links,
        private readonly Locales $locales,
        private readonly MembersConfig $config,
        private readonly Admission $admission,
        private readonly LinkValidity $validity,
        private readonly ?string $from = null,
        private readonly ?MemberLinks $memberLinks = null,
    ) {}

    /** @param ?\Closure(\Throwable): void $onFailure */
    public function confirmLater(DynamicRecord $m, string $token, ?\Closure $onFailure = null): void
    {
        $lang = $this->lang($m);
        $this->notifier->sendLater($this->build('confirm', $lang, (string) $m->get('email'), [
            'name' => (string) $m->get('name'),
            'link' => $this->links->url($lang, '/confirm/' . $token),
            'validity' => $this->validity->confirm($lang),
        ]), $onFailure);
    }

    /**
     * Someone registered with the address of an account: a mail to that account, with its login and a reset link.
     *
     * @param ?\Closure(\Throwable): void $onFailure
     */
    public function existingLater(DynamicRecord $m, string $resetToken, ?\Closure $onFailure = null): void
    {
        $lang = $this->lang($m);
        $this->notifier->sendLater($this->build('existing', $lang, (string) $m->get('email'), [
            'name' => (string) $m->get('name'),
            'login_url' => $this->links->url($lang, '/login'),
            'reset_url' => $this->links->url($lang, '/reset/' . $resetToken),
            'validity' => $this->validity->reset($lang),
        ]), $onFailure);
    }

    /** @param ?\Closure(\Throwable): void $onFailure */
    public function resetLater(DynamicRecord $m, string $token, ?\Closure $onFailure = null): void
    {
        $lang = $this->lang($m);
        $this->notifier->sendLater($this->build('reset', $lang, (string) $m->get('email'), [
            'name' => (string) $m->get('name'),
            'link' => $this->links->url($lang, '/reset/' . $token),
            'validity' => $this->validity->reset($lang),
        ]), $onFailure);
    }

    /**
     * The owner's mail about a member waiting for approval, in the site's default locale,
     * with the admission's notice details.
     *
     * @param ?\Closure(\Throwable): void $onFailure
     */
    public function noticeLater(DynamicRecord $m, string $to, ?\Closure $onFailure = null): void
    {
        $details = '';
        foreach ($this->admission->details($m, Admission::NOTICE) as $label => $value) {
            $details .= "\n\n" . (is_int($label) ? $value : $label . ': ' . $value);
        }
        // The decision page when a package (Folio's members admin) names one; else the text without the link line.
        $url = $this->memberLinks?->decisionUrl($m);
        $this->notifier->sendLater($this->build('notice', $this->locales->default, $to, [
            'name' => (string) $m->get('name'),
            'email' => (string) $m->get('email'),
            'details' => $details,
        ] + ($url !== null && $url !== '' ? ['folio_url' => $url] : []), $url !== null && $url !== '' ? 'body' : 'body_without_link'), $onFailure);
    }

    /** @throws TransportException */
    public function accepted(DynamicRecord $m, string $message): void
    {
        $this->sendMessage('accepted', $m, $message);
    }

    /** @throws TransportException */
    public function declined(DynamicRecord $m, string $message): void
    {
        $this->sendMessage('declined', $m, $message);
    }

    /** The owner's message from the Decision panel, already filled in; only the subject comes from the lang files. */
    private function sendMessage(string $mail, DynamicRecord $m, string $message): void
    {
        $lang = $this->lang($m);
        $this->notifier->send($this->compose($this->copy($mail, 'subject', $lang, []), $message, $lang, (string) $m->get('email')));
    }

    /** @param array<string, string> $vars */
    private function build(string $mail, string $lang, string $to, array $vars, string $body = 'body'): Email
    {
        return $this->compose(
            $this->copy($mail, 'subject', $lang, array_map(self::oneLine(...), $vars)),
            $this->copy($mail, $body, $lang, $vars),
            $lang,
            $to,
        );
    }

    /** @param array<string, string> $vars {name} {link} {login_url} {reset_url} {validity} {email} {details} {folio_url} */
    private function copy(string $mail, string $part, string $lang, array $vars): string
    {
        $map = [];
        foreach ($vars as $key => $value) {
            $map['{' . $key . '}'] = $value;
        }

        return strtr($this->translator->get('members.mail.' . $mail . '.' . $part, [], $lang), $map);
    }

    private function compose(string $subject, string $text, string $lang, string $to): Email
    {
        return new Email(
            to: $to,
            subject: $subject,
            text: $text,
            html: $this->engine->render($this->config->mailLayout(), [
                'lang' => $lang,
                'subject' => $subject,
                'accent' => $this->config->accent(),
                'paragraphs' => $this->paragraphs($text),
            ]),
            from: $this->from,
        );
    }

    /** The member's language when the site has it, else the site's default. */
    private function lang(DynamicRecord $m): string
    {
        $lang = (string) $m->get('language');

        return $this->locales->has($lang) ? $lang : $this->locales->default;
    }

    /** Control characters (CR/LF included) collapse to one space: a value must not break a header line. */
    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $value));
    }

    /** @return list<string> safe HTML: escaped paragraphs with URLs linked and single line breaks kept */
    private function paragraphs(string $text): array
    {
        $accent = htmlspecialchars($this->config->accent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = [];
        foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $p) {
            $html = htmlspecialchars($p, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html = (string) preg_replace('~https?://[^\s<]+~', '<a href="$0" style="color:' . $accent . '">$0</a>', $html);
            $out[] = nl2br($html, false);
        }

        return $out;
    }
}
