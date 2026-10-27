<?php

declare(strict_types=1);

namespace Preflow\Members\Http;

use Preflow\Auth\AuthManager;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\PasswordRehash;
use Preflow\Auth\Record\RecordUserProvider;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Locales;
use Preflow\Auth\Record\RecordUser;
use Preflow\Members\Links;
use Preflow\Members\MemberMailer;
use Preflow\Members\Members;
use Preflow\Members\MembersConfig;
use Preflow\Members\MembersView;
use Preflow\Members\SignIn;
use Preflow\Members\MemberState;
use Preflow\Members\Viewer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Sign-in with a remember cookie, sign-out, forgot and reset password, resend the confirmation.
 * Answers never tell whether an address has an account: an unknown address costs a hash like
 * a wrong password, a declined account fails like one, and forgot/resend answer alike.
 */
final class AuthController
{
    public function __construct(
        private readonly MembersView $view,
        private readonly Viewer $viewer,
        private readonly Members $members,
        private readonly MemberMailer $mailer,
        private readonly MembersConfig $config,
        private readonly SafeNext $safeNext,
        private readonly Links $links,
        private readonly Locales $locales,
        private readonly Tokens $tokens,
        private readonly Throttle $throttle,
        private readonly AuthManager $auth,
        private readonly RememberMe $remember,
        private readonly PasswordHasherInterface $hasher,
        private readonly DataManager $dm,
        private readonly SignIn $signIn,
    ) {}

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        $next = Input::text($request->getQueryParams(), 'next');
        if ($this->viewer->canAccess()) {
            return $this->view->redirect($this->safeNext->of($next, $this->locale($request)));
        }

        return $this->loginPage($request, $next);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = Input::body($request);
        $email = Members::normalizeEmail(Input::text($body, 'email'));
        $password = Input::text($body, 'password');
        $next = Input::text($body, 'next');

        $client = $this->throttle->client($request);
        $key = hash('sha256', $email);
        [$max, $window] = $this->config->throttle('login');
        $purpose = MembersConfig::THROTTLE_LOGIN;
        if ($this->throttle->tooMany($purpose, $client, $max, $window) || $this->throttle->tooMany($purpose, $key, $max, $window)) {
            return $this->loginPage($request, $next, $email, 'throttled', 429);
        }

        $member = $email === '' ? null : $this->members->findByEmail($email);
        if ($member === null) {
            $this->hasher->hash($password); // an unknown address costs a hash too, so the answer takes as long as a wrong password
            $ok = false;
        } else {
            $ok = $this->hasher->verify($password, (string) $member->get('password_hash'));
        }
        $state = $member === null ? null : MemberState::fromRecord($member);
        // A locked account fails like a declined one, before its state shows.
        if (!$ok || $member === null || $state === MemberState::Declined || $this->members->isLocked($member)) {
            $this->throttle->hit($purpose, $client, $window);
            $this->throttle->hit($purpose, $key, $window);

            return $this->loginPage($request, $next, $email, 'login', 422);
        }

        if ($state === MemberState::Unconfirmed) {
            return $this->view->render('resend', ['email' => $email], $request);
        }
        if ($state === MemberState::Waiting) {
            return $this->view->render('thanks', ['notice' => ''], $request);
        }

        $user = new RecordUser($member);
        // An outdated hash (an imported $1$, an older cost) is upgraded while the password is at hand.
        PasswordRehash::afterVerify(new RecordUserProvider($this->dm, ['type' => MembersConfig::TYPE]), $user, $password, $this->hasher);
        // Read again: the SIGNED_IN listeners get the record as stored, the upgraded hash included.
        $member = $this->members->find((string) $member->getId()) ?? $member;
        $this->signIn->in($member, $request);
        $this->throttle->clear($purpose, $client);
        $this->throttle->clear($purpose, $key);

        return $this->view->redirect($this->safeNext->of($next, $this->locale($request)));
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->guard(MembersConfig::GUARD)->logout($request);

        return $this->view->redirect($this->links->local($this->locale($request)));
    }

    public function forgotForm(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('forgot', ['sent' => false, 'error' => ''], $request);
    }

    public function forgot(ServerRequestInterface $request): ResponseInterface
    {
        $email = Members::normalizeEmail(Input::text(Input::body($request), 'email'));
        $client = $this->throttle->client($request);
        $key = hash('sha256', $email);
        [$max, $window] = $this->config->throttle('forgot');
        $purpose = MembersConfig::THROTTLE_FORGOT;
        if ($this->throttle->tooMany($purpose, $client, $max, $window) || $this->throttle->tooMany($purpose, $key, $max, $window)) {
            return $this->view->render('forgot', ['sent' => false, 'error' => 'throttled'], $request, 429);
        }
        $this->throttle->hit($purpose, $client, $window);
        $this->throttle->hit($purpose, $key, $window);

        $member = $email === '' ? null : $this->members->findByEmail($email);
        if ($member !== null && MemberState::fromRecord($member) === MemberState::Member && !$this->members->isLocked($member)) {
            $id = (string) $member->getId();
            $this->mailer->resetLater(
                $member,
                $this->tokens->issue(MembersConfig::RESET_PURPOSE, $id, $this->config->resetTtl()),
                static function () use ($id): void {
                    error_log('members: reset mail failed for member ' . $id); // no address, no exception text
                },
            );
        }

        return $this->view->render('forgot', ['sent' => true, 'error' => ''], $request);
    }

    /** Opening the link only shows the form; the token is used up by the POST. */
    public function resetForm(ServerRequestInterface $request): ResponseInterface
    {
        return $this->resetPage($request, (string) $request->getAttribute('token', ''));
    }

    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        $body = Input::body($request);
        $token = Input::text($body, 'token');
        $password = Input::text($body, 'password');

        if (mb_strlen($password) < $this->config->passwordMin()) {
            return $this->resetPage($request, $token, 'password', 422);
        }
        if ($password !== Input::text($body, 'repeat')) {
            return $this->resetPage($request, $token, 'mismatch', 422);
        }

        $id = $token === '' ? null : $this->tokens->consume($token, MembersConfig::RESET_PURPOSE);
        $member = $id === null ? null : $this->members->find($id);
        if ($member === null || MemberState::fromRecord($member) !== MemberState::Member || $this->members->isLocked($member)) {
            return $this->resetPage($request, '', 'expired', 410);
        }

        $id = (string) $member->getId();
        // The new password ends every other session (epoch and remember rows); this one signs in again.
        $this->members->setPassword($member, $password);
        $this->remember->revokeAll(MembersConfig::GUARD, $id);
        $this->tokens->revokeAll(MembersConfig::RESET_PURPOSE, $id);
        $this->signIn->in($member, $request);

        return $this->view->redirect($this->links->localize($this->locale($request), $this->config->landing()));
    }

    /** The resend form comes with a sign-in of an unconfirmed account; on its own there is no address to send to. */
    public function resendForm(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->redirect($this->links->local($this->locale($request), '/login'));
    }

    public function resend(ServerRequestInterface $request): ResponseInterface
    {
        $email = Members::normalizeEmail(Input::text(Input::body($request), 'email'));
        $key = hash('sha256', $email);
        [$max, $window] = $this->config->throttle('resend');
        $purpose = MembersConfig::THROTTLE_RESEND;
        // Over the limit the page stays the same; only the mail is skipped.
        if (!$this->throttle->tooMany($purpose, $key, $max, $window)) {
            $this->throttle->hit($purpose, $key, $window);
            $member = $email === '' ? null : $this->members->findByEmail($email);
            if ($member !== null && MemberState::fromRecord($member) === MemberState::Unconfirmed) {
                $id = (string) $member->getId();
                $this->tokens->revokeAll(MembersConfig::CONFIRM_PURPOSE, $id);
                $this->mailer->confirmLater(
                    $member,
                    $this->tokens->issue(MembersConfig::CONFIRM_PURPOSE, $id, $this->config->confirmTtl()),
                    static function () use ($id): void {
                        error_log('members: confirm mail failed for member ' . $id);
                    },
                );
            }
        }

        return $this->view->render('check-inbox', ['email' => $email], $request);
    }

    private function loginPage(ServerRequestInterface $request, string $next, string $email = '', string $error = '', int $status = 200): ResponseInterface
    {
        return $this->view->render('login', ['next' => $next, 'next_query' => $this->safeNext->query($next, $this->locale($request)), 'old_email' => $email, 'error' => $error], $request, $status);
    }

    private function resetPage(ServerRequestInterface $request, string $token, string $error = '', int $status = 200): ResponseInterface
    {
        return $this->view->render('reset', ['token' => $token, 'error' => $error], $request, $status);
    }

    private function locale(ServerRequestInterface $request): string
    {
        return Input::locale($request, $this->locales);
    }
}
