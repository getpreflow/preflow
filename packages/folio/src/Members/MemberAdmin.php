<?php

declare(strict_types=1);

namespace Preflow\Folio\Members;

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
use Preflow\I18n\Translator;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Languages;
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
    private const array TONES = ['waiting' => 'changed', 'member' => 'published', 'declined' => 'draft', 'unconfirmed' => '', 'locked' => 'incomplete'];

    /** The states each decision may start from. */
    private const array ALLOWED = [
        'member' => [MemberState::Waiting, MemberState::Declined],
        'rejected' => [MemberState::Waiting, MemberState::Member],
    ];

    public function __construct(
        private readonly Members $members,
        private readonly MemberMailer $mailer,
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
        // A lock shows over the state (the member keeps it); never the declined label.
        $key = $this->members->isLocked($m) ? 'locked' : MemberState::fromRecord($m)->value;

        return new Badge($this->translator->get('members-admin.states.' . $key), self::TONES[$key]);
    }

    #[FormPanel('members-admin.decision.title', position: 'side')]
    public function decision(DynamicRecord $m): ScreenView
    {
        $lang = $this->lang($m);
        $state = MemberState::fromRecord($m);
        // The raw value: the panel prints it with folio_date(…, 'datetime'), as Folio's date fields show it.
        $registered = (string) $m->get('registered_at');
        $locked = $this->members->isLocked($m);

        return new ScreenView('@members/decision.twig', [
            'state' => $state->value,
            'state_label' => $locked ? $this->translator->get('members-admin.states.locked') : $this->stateLabel($state),
            'state_tone' => self::TONES[$locked ? 'locked' : $state->value],
            'locked_at' => $locked ? (string) $m->get('locked_at') : '',
            'last_login_at' => (string) $m->get('last_login_at'),
            'can_lock' => !$locked,
            'can_unlock' => $locked,
            'can_resend' => $state === MemberState::Unconfirmed && !$locked,
            'registered' => $registered,
            'details' => self::lines($this->admission->details($m, Admission::PANEL)),
            'can_accept' => in_array($state, self::ALLOWED['member'], true),
            'can_decline' => in_array($state, self::ALLOWED['rejected'], true),
            'accept_text' => $this->mailer->defaultMessage($m, 'accepted'),
            'decline_text' => $this->mailer->defaultMessage($m, 'declined'),
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

    // Folio hides an action per record nowhere: the panel offers only the buttons that apply (toolbar: false),
    // and a crafted POST where one does not apply is refused with an error flash and changes nothing.
    #[RecordAction(name: 'lock', label: 'members-admin.lock.lock', permission: MembersConfig::DECIDE, toolbar: false)]
    public function lock(DynamicRecord $m, ServerRequestInterface $request): ResponseInterface
    {
        if ($this->members->isLocked($m)) {
            return $this->back($request, $this->translator->get('members-admin.lock.already_locked'), $m);
        }
        $this->members->lock($m);

        return $this->back($request, $this->translator->get('members-admin.lock.done_lock'), $m, 'success');
    }

    #[RecordAction(name: 'unlock', label: 'members-admin.lock.unlock', permission: MembersConfig::DECIDE, toolbar: false)]
    public function unlock(DynamicRecord $m, ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->members->isLocked($m)) {
            return $this->back($request, $this->translator->get('members-admin.lock.not_locked'), $m);
        }
        $this->members->unlock($m);

        return $this->back($request, $this->translator->get('members-admin.lock.done_unlock'), $m, 'success');
    }

    #[RecordAction(name: 'resend_confirmation', label: 'members-admin.lock.resend', permission: MembersConfig::DECIDE, toolbar: false)]
    public function resendConfirmation(DynamicRecord $m, ServerRequestInterface $request): ResponseInterface
    {
        $state = MemberState::fromRecord($m);
        if ($state !== MemberState::Unconfirmed || $this->members->isLocked($m)) {
            return $this->back($request, $this->translator->get('members-admin.lock.cant_resend', ['state' => $this->stateLabel($state)]), $m);
        }
        try {
            $this->members->resendConfirmation($m);
        } catch (TransportException) {
            return $this->back($request, $this->translator->get('members-admin.lock.resend_failed'), $m);
        }

        return $this->back($request, $this->translator->get('members-admin.lock.done_resend'), $m, 'success');
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

    private function back(ServerRequestInterface $request, string $message, DynamicRecord $m, string $type = 'error'): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if ($session instanceof SessionInterface) {
            $session->flash($type, $message);
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
