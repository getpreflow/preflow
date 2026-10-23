<?php

declare(strict_types=1);

namespace Preflow\Members\Http;

use Preflow\Auth\AuthManager;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Folio\Extension\Settings\ExtensionSettings;
use Preflow\Folio\Users\RecordUser;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Admission\Decision;
use Preflow\Members\Links;
use Preflow\Members\MemberMailer;
use Preflow\Members\Members;
use Preflow\Members\MembersConfig;
use Preflow\Members\MembersView;
use Preflow\Members\MemberState;
use Preflow\Members\Viewer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Registration and the double opt-in. The register answer is the same for a new and a known
 * address (the known one gets a mail instead); after the confirmation the configured
 * Admission decides: a member at once (signed in), or the owner decides.
 */
final class RegistrationController
{
    /** Form fields that never go back into the page or to the admission. */
    private const array SECRET = ['password', '_csrf_token'];

    public function __construct(
        private readonly MembersView $view,
        private readonly Viewer $viewer,
        private readonly Members $members,
        private readonly MemberMailer $mailer,
        private readonly MembersConfig $config,
        private readonly Admission $admission,
        private readonly Links $links,
        private readonly Locales $locales,
        private readonly Tokens $tokens,
        private readonly Throttle $throttle,
        private readonly AuthManager $auth,
        private readonly ExtensionSettings $settings,
        private readonly PasswordHasherInterface $hasher,
    ) {}

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->viewer->member() !== null && $this->viewer->canAccess()) {
            return $this->view->redirect($this->landing($request));
        }

        return $this->page($request);
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->open($request)) {
            return $this->show('closed', $request, [], 403);
        }
        $client = $this->throttle->client($request);
        [$max, $window] = $this->config->throttle('register');
        if ($this->throttle->tooMany(MembersConfig::THROTTLE_REGISTER, $client, $max, $window)) {
            return $this->page($request, ['form' => 'throttled'], [], 429);
        }
        $this->throttle->hit(MembersConfig::THROTTLE_REGISTER, $client, $window);

        $body = Input::body($request);
        $password = Input::text($body, 'password');
        // The admission's own fields come along as posted; name, email and consent normalised.
        $input = [
            'name' => trim(Input::text($body, 'name')),
            'email' => Members::normalizeEmail(Input::text($body, 'email')),
            'consent' => Input::text($body, 'consent') !== '',
        ] + array_diff_key(array_filter($body, is_string(...)), array_flip(self::SECRET));

        $errors = [];
        if ($input['name'] === '') {
            $errors['name'] = 'name';
        }
        if (filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'email';
        }
        if (mb_strlen($password) < $this->config->passwordMin()) {
            $errors['password'] = 'password';
        }
        if (!$input['consent']) {
            $errors['consent'] = 'consent';
        }
        // The admission only sees an otherwise valid post: its check may cost (a throttled code lookup).
        if ($errors === []) {
            $errors = $this->admission->validate($input, $request);
        }
        if ($errors !== []) {
            return $this->page($request, $errors, $input, 422);
        }

        $existing = $this->members->findByEmail($input['email']);
        if ($existing !== null) {
            // A fresh registration costs a hash and a mail; every duplicate path costs at least the hash, so the answer takes alike.
            $this->hasher->hash($password);
            $this->remindExisting($existing);

            return $this->show('check-inbox', $request, ['email' => $input['email']]);
        }

        $member = $this->members->register(
            $input['email'],
            $input['name'],
            $password,
            $this->locale($request),
            $this->admission->recordValues($input),
        );
        // Queued: it goes out after the response, so a fresh address answers as fast as a known one. If it cannot
        // be sent, the account is removed again (the visitor can simply register once more); no address is logged.
        $id = (string) $member->getId();
        $this->mailer->confirmLater(
            $member,
            $this->tokens->issue(MembersConfig::CONFIRM_PURPOSE, $id, $this->config->confirmTtl()),
            function () use ($id): void {
                $this->tokens->revokeAll(MembersConfig::CONFIRM_PURPOSE, $id);
                $this->members->removeIfUnconfirmed($id);
                error_log('members: confirm mail failed for member ' . $id); // no address, no exception text
            },
        );

        return $this->show('check-inbox', $request, ['email' => $input['email']]);
    }

    /** Mail scanners open links: this only renders the form that posts the token; the token is not touched. */
    public function confirmPage(ServerRequestInterface $request): ResponseInterface
    {
        return $this->show('confirm', $request, ['token' => (string) $request->getAttribute('token', '')]);
    }

    public function confirm(ServerRequestInterface $request): ResponseInterface
    {
        $token = Input::text(Input::body($request), 'token');
        $id = $token === '' ? null : $this->tokens->consume($token, MembersConfig::CONFIRM_PURPOSE);
        $member = $id === null ? null : $this->members->find($id);
        if ($member === null || MemberState::fromRecord($member) !== MemberState::Unconfirmed) {
            return $this->show('confirm', $request, ['token' => '', 'used' => true], 410);
        }
        $this->members->confirm($member);

        $decision = $this->admission->afterConfirm($member, $request);
        $notice = $this->admission->confirmNotice($member);
        if ($decision === Decision::Accept) {
            $this->members->decide($member, 'member');
            $this->auth->guard(MembersConfig::GUARD)->login(new RecordUser($member), $request, remember: true);
            // After the login, which starts a fresh session id: the landing (the site's page) shows it.
            if ($notice !== null && $notice !== '') {
                Input::flashSet($request, Input::NOTICE, $notice);
            }

            return $this->view->redirect($this->landing($request));
        }

        $this->members->decide($member, 'pending');
        $this->tellOwner($member);

        return $this->show('thanks', $request, $notice !== null && $notice !== '' ? ['notice' => $notice] : []);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $input
     */
    private function page(ServerRequestInterface $request, array $errors = [], array $input = [], int $status = 200): ResponseInterface
    {
        return $this->show('register', $request, ['open' => $this->open($request), 'errors' => $errors, 'old' => $input], $status);
    }

    /** A page with the flash notice a previous request left (e.g. after deleting the account). @param array<string, mixed> $vars */
    private function show(string $template, ServerRequestInterface $request, array $vars = [], int $status = 200): ResponseInterface
    {
        return $this->view->render($template, $vars + ['notice' => Input::flash($request, Input::NOTICE)], $request, $status);
    }

    private function open(ServerRequestInterface $request): bool
    {
        return $this->settings->get('members', 'registration_open', $this->locale($request)) !== false;
    }

    /** Someone registered with an address that already has an account: tell the owner of the address, not the visitor. */
    private function remindExisting(DynamicRecord $existing): void
    {
        $id = (string) $existing->getId();
        $failed = static function () use ($id): void {
            error_log('members: mail to an existing account failed, member ' . $id);
        };
        switch (MemberState::fromRecord($existing)) {
            case MemberState::Member:
                $this->mailer->existingLater($existing, $this->tokens->issue(MembersConfig::RESET_PURPOSE, $id, $this->config->resetTtl()), $failed);
                break;
            case MemberState::Unconfirmed:
                $this->tokens->revokeAll(MembersConfig::CONFIRM_PURPOSE, $id);
                $this->mailer->confirmLater($existing, $this->tokens->issue(MembersConfig::CONFIRM_PURPOSE, $id, $this->config->confirmTtl()), $failed);
                break;
            default: // waiting or declined: no mail, the answer stays the same
        }
    }

    private function tellOwner(DynamicRecord $member): void
    {
        $to = trim((string) $this->settings->get('members', 'notice_email'));
        if ($to === '') {
            return;
        }
        $id = (string) $member->getId();
        $this->mailer->noticeLater($member, $to, static function () use ($id): void {
            error_log('members: owner notice failed for member ' . $id);
        });
    }

    private function landing(ServerRequestInterface $request): string
    {
        return $this->links->localize($this->locale($request), $this->config->landing());
    }

    private function locale(ServerRequestInterface $request): string
    {
        return Input::locale($request, $this->locales);
    }
}
