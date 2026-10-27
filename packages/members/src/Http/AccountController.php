<?php

declare(strict_types=1);

namespace Preflow\Members\Http;

use Preflow\Auth\AuthManager;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Auth\Record\RecordUser;
use Preflow\I18n\Translator;
use Preflow\Members\Links;
use Preflow\Members\Members;
use Preflow\Members\MembersConfig;
use Preflow\Members\MembersView;
use Preflow\Members\Viewer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A member's own page: change the password, delete the account and with it the personal data.
 * The current password is asked for both and throttled per member, so a stolen session
 * cannot guess it.
 */
final class AccountController
{
    public function __construct(
        private readonly MembersView $view,
        private readonly Viewer $viewer,
        private readonly Members $members,
        private readonly MembersConfig $config,
        private readonly Links $links,
        private readonly Locales $locales,
        private readonly Tokens $tokens,
        private readonly Throttle $throttle,
        private readonly AuthManager $auth,
        private readonly RememberMe $remember,
        private readonly PasswordHasherInterface $hasher,
        private readonly Translator $translator,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $member = $this->member($request, $gate);
        if ($member === null) {
            return $gate;
        }

        return $this->page($request, $member, Input::flash($request, Input::ACCOUNT_NOTICE));
    }

    public function password(ServerRequestInterface $request): ResponseInterface
    {
        $member = $this->member($request, $gate);
        if ($member === null) {
            return $gate;
        }
        $body = Input::body($request);
        $new = Input::text($body, 'password');
        $id = (string) $member->getId();

        if ($this->blocked($id)) {
            return $this->page($request, $member, '', 'throttled', 429);
        }
        if (mb_strlen($new) < $this->config->passwordMin()) {
            return $this->page($request, $member, '', 'password', 422);
        }
        if ($new !== Input::text($body, 'repeat')) {
            return $this->page($request, $member, '', 'mismatch', 422);
        }
        if (!$this->currentIsRight($member, Input::text($body, 'current'))) {
            return $this->page($request, $member, '', 'current', 422);
        }

        // The new password ends every other session (epoch and remember rows); this one signs in again.
        $this->members->setPassword($member, $new);
        $this->remember->revokeAll(MembersConfig::GUARD, $id);
        $this->auth->guard(MembersConfig::GUARD)->login(new RecordUser($member), $request, remember: true);
        Input::flashSet($request, Input::ACCOUNT_NOTICE, $this->translator->get('members.account.changed'));

        return $this->view->redirect($this->links->local($this->locale($request), '/account'));
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $member = $this->member($request, $gate);
        if ($member === null) {
            return $gate;
        }
        $body = Input::body($request);
        $id = (string) $member->getId();

        if ($this->blocked($id)) {
            return $this->page($request, $member, '', 'throttled', 429);
        }
        if (Input::text($body, 'confirm') === '') {
            return $this->page($request, $member, '', 'confirm', 422);
        }
        if (!$this->currentIsRight($member, Input::text($body, 'current'))) {
            return $this->page($request, $member, '', 'delete_current', 422);
        }

        // Through the delete plan with the self rules: a restrict rule (or a hook) keeps the account, and
        // the member reads whether the site knows. Nothing else changes then; the member stays signed in.
        $result = $this->members->delete($member);
        if ($result->refused) {
            return $this->page($request, $member, '', $result->told ? 'refused_told' : 'refused_contact', 409);
        }
        // What other packages keep about the member (a code redemption's redeemer id) is a trail, not personal data.
        $this->tokens->revokeAll(MembersConfig::CONFIRM_PURPOSE, $id);
        $this->tokens->revokeAll(MembersConfig::RESET_PURPOSE, $id);
        $this->remember->revokeAll(MembersConfig::GUARD, $id);
        $this->auth->guard(MembersConfig::GUARD)->logout($request);
        $this->throttle->clear(MembersConfig::THROTTLE_LOGIN, $this->key($id));
        // After the logout, which starts a fresh session: the register page shows the notice once.
        Input::flashSet($request, Input::NOTICE, $this->translator->get('members.account.deleted'));

        return $this->view->redirect($this->links->localize($this->locale($request), $this->config->register()));
    }

    /** The signed-in member, or null with $gate set to where everybody else goes. */
    private function member(ServerRequestInterface $request, ?ResponseInterface &$gate): ?DynamicRecord
    {
        $member = $this->viewer->canAccess() ? $this->viewer->member() : null;
        if ($member !== null) {
            return $member;
        }
        $locale = $this->locale($request);
        // Staff without a member account has no account page: the members home.
        $gate = $this->view->redirect($this->viewer->isStaff() && $this->viewer->member() === null
            ? $this->links->local($locale)
            : $this->links->local($locale, '/login') . '?next=' . $this->links->local($locale, '/account'));

        return null;
    }

    private function currentIsRight(DynamicRecord $member, string $current): bool
    {
        [, $window] = $this->config->throttle('login');
        if ($this->hasher->verify($current, (string) $member->get('password_hash'))) {
            $this->throttle->clear(MembersConfig::THROTTLE_LOGIN, $this->key((string) $member->getId()));

            return true;
        }
        $this->throttle->hit(MembersConfig::THROTTLE_LOGIN, $this->key((string) $member->getId()), $window);

        return false;
    }

    private function blocked(string $id): bool
    {
        [$max, $window] = $this->config->throttle('login');

        return $this->throttle->tooMany(MembersConfig::THROTTLE_LOGIN, $this->key($id), $max, $window);
    }

    /** The per-member throttle key (the sign-in form throttles per address hash instead). */
    private function key(string $id): string
    {
        return 'member:' . $id;
    }

    private function page(ServerRequestInterface $request, DynamicRecord $member, string $notice = '', string $error = '', int $status = 200): ResponseInterface
    {
        return $this->view->render('account', [
            'email' => (string) $member->get('email'),
            'name' => (string) $member->get('name'),
            'notice' => $notice,
            'error' => $error,
        ], $request, $status);
    }

    private function locale(ServerRequestInterface $request): string
    {
        return Input::locale($request, $this->locales);
    }
}
