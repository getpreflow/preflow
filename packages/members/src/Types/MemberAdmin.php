<?php

declare(strict_types=1);

namespace Preflow\Members\Types;

use Nyholm\Psr7\Response;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Folio\Extension\Attributes\FolioType;
use Preflow\Folio\Extension\Badge;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\FormPanel;
use Preflow\Folio\Extension\ListColumn;
use Preflow\Folio\Extension\NavBadge;
use Preflow\Folio\Extension\RecordAction;
use Preflow\Folio\Extension\Screen\ScreenView;
use Preflow\Folio\Extension\Settings\ExtensionSettings;
use Preflow\I18n\Translator;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Languages;
use Preflow\Members\Links;
use Preflow\Members\MemberMailer;
use Preflow\Members\Members;
use Preflow\Members\MembersConfig;
use Preflow\Members\MemberState;
use Preflow\Notify\TransportException;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Members in the admin: how many wait for a decision, each member's state, and the Decision
 * panel — accept or decline with an editable message, beside the admission's details. The
 * mail goes out first; only when it was sent does the member's role change. Which decision
 * fits which state is checked here too, so a crafted POST changes nothing the panel doesn't offer.
 * Its words are the "members-admin" group's (the admin's language); the mails keep the
 * member's language and the public "members" group.
 */
#[FolioType('member')]
final class MemberAdmin
{
    private const array TONES = ['waiting' => 'changed', 'member' => 'published', 'declined' => 'draft', 'unconfirmed' => ''];

    /** The states each decision may start from. */
    private const array ALLOWED = [
        'member' => [MemberState::Waiting, MemberState::Declined],
        'rejected' => [MemberState::Waiting, MemberState::Member],
    ];

    public function __construct(
        private readonly Members $members,
        private readonly MemberMailer $mailer,
        private readonly Links $links,
        private readonly ExtensionSettings $settings,
        private readonly RememberMe $remember,
        private readonly Admission $admission,
        private readonly Locales $locales,
        private readonly Translator $translator,
    ) {}

    /** The owner may edit the address: store it the way login and reset look it up, and keep it unique. */
    #[BeforeSave]
    public function normalizeEmail(DynamicRecord $m): void
    {
        $email = Members::normalizeEmail((string) $m->get('email'));
        $m->set('email', $email);
        if ($email !== '' && $this->members->emailTaken($email, (string) $m->getId())) {
            throw new ValidationException(new ValidationResult(['email' => [$this->translator->get('members-admin.errors.email_taken')]]));
        }
    }

    #[NavBadge]
    public function waiting(): ?int
    {
        $count = $this->members->pendingCount();

        return $count > 0 ? $count : null;
    }

    #[ListColumn('members-admin.list.state', 5)]
    public function state(DynamicRecord $m): Badge
    {
        $state = MemberState::fromRecord($m);

        return new Badge($this->stateLabel($state), self::TONES[$state->value]);
    }

    #[FormPanel('members-admin.decision.title', position: 'side')]
    public function decision(DynamicRecord $m): ScreenView
    {
        $lang = $this->lang($m);
        $name = (string) $m->get('name');
        $state = MemberState::fromRecord($m);
        $registered = (string) $m->get('registered_at');
        try { // as Folio's date fields show it (DateFieldType), so the panel and the form read alike
            $registered = (new \DateTimeImmutable($registered))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('j M Y, H:i');
        } catch (\Exception) {
        }

        return new ScreenView('@members/decision.twig', [
            'state' => $state->value,
            'state_label' => $this->stateLabel($state),
            'state_tone' => self::TONES[$state->value],
            'registered' => $registered,
            'details' => self::lines($this->admission->details($m, Admission::PANEL)),
            'can_accept' => in_array($state, self::ALLOWED['member'], true),
            'can_decline' => in_array($state, self::ALLOWED['rejected'], true),
            'accept_text' => $this->text('accept_message', 'accepted', $lang, $name, $this->links->url($lang, '/login')),
            'decline_text' => $this->text('decline_message', 'declined', $lang, $name),
            'language_label' => $this->languageLabel($lang),
        ]);
    }

    #[RecordAction(name: 'accept', label: 'members-admin.decision.accept', permission: MembersConfig::DECIDE, toolbar: false)]
    public function accept(DynamicRecord $m, ServerRequestInterface $request): ?ResponseInterface
    {
        return $this->decide($m, $request, 'member');
    }

    #[RecordAction(name: 'decline', label: 'members-admin.decision.decline', permission: MembersConfig::DECIDE, toolbar: false)]
    public function decline(DynamicRecord $m, ServerRequestInterface $request): ?ResponseInterface
    {
        return $this->decide($m, $request, 'rejected');
    }

    private function decide(DynamicRecord $m, ServerRequestInterface $request, string $role): ?ResponseInterface
    {
        $body = $request->getParsedBody();
        $message = trim(is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : '');
        if ($message === '') {
            return $this->back($request, $this->translator->get('members-admin.decision.empty_message'), $m);
        }
        $state = MemberState::fromRecord($m);
        if ($state === MemberState::Unconfirmed) {
            return $this->back($request, $this->translator->get('members-admin.decision.unconfirmed'), $m);
        }
        if (!in_array($state, self::ALLOWED[$role], true)) {
            return $this->back($request, $this->translator->get('members-admin.decision.cant_' . ($role === 'member' ? 'accept' : 'decline'), ['state' => $this->stateLabel($state)]), $m);
        }
        try {
            $role === 'member' ? $this->mailer->accepted($m, $message) : $this->mailer->declined($m, $message);
        } catch (TransportException) {
            return $this->back($request, $this->translator->get('members-admin.decision.mail_failed'), $m);
        }

        $this->members->decide($m, $role);
        if ($role === 'rejected') {
            $this->remember->revokeAll(MembersConfig::GUARD, (string) $m->getId());
        }

        return null;
    }

    private function back(ServerRequestInterface $request, string $error, DynamicRecord $m): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if ($session instanceof SessionInterface) {
            $session->flash('error', $error);
        }
        $prefix = rtrim((string) preg_replace('~/member/.*$~', '', $request->getUri()->getPath()), '/');

        return new Response(302, ['Location' => $prefix . '/member/' . rawurlencode((string) $m->getId()) . '/edit']);
    }

    /** A state as the admin reads it ("Waiting"), in the interface language. */
    private function stateLabel(MemberState $state): string
    {
        return $this->translator->get('members-admin.states.' . $state->value);
    }

    /** The name of the language a member's mail goes out in, in the interface language. */
    private function languageLabel(string $lang): string
    {
        $key = 'members-admin.decision.languages.' . $lang;
        $name = $this->translator->get($key);

        return $name === $key ? Languages::english($lang) : $name;
    }

    /** The member's language when the site has it, else the site's default. */
    private function lang(DynamicRecord $m): string
    {
        $lang = (string) $m->get('language');

        return $this->locales->has($lang) ? $lang : $this->locales->default;
    }

    /**
     * The default message in the member's language, with the placeholders filled in: the
     * owner's text from Members settings stored for $lang itself, else the lang files'
     * (mail.{mail}.body in $lang), which a site overrides with its own lang/{locale}/members.php.
     */
    private function text(string $setting, string $mail, string $lang, string $name, string $loginUrl = ''): string
    {
        // exact: a text stored for another locale only would put the wrong language into the mail.
        $text = $this->settings->get('members', $setting, $lang, exact: true);
        if (!is_string($text) || trim($text) === '') {
            $text = $this->translator->get('members.mail.' . $mail . '.body', [], $lang);
        }

        return strtr($text, ['{name}' => $name, '{login_url}' => $loginUrl]);
    }

    /**
     * @param array<int|string, string> $details
     * @return list<string> "label: value", or the value alone for an int key
     */
    private static function lines(array $details): array
    {
        $lines = [];
        foreach ($details as $label => $value) {
            $lines[] = is_int($label) ? $value : $label . ': ' . $value;
        }

        return $lines;
    }
}
