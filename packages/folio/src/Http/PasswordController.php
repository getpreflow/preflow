<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\AuthManager;
use Preflow\Core\Throttle\Throttle;
use Preflow\Folio\Users\StaffPasswords;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Forgot password" and the set-password page of the Folio login (public, CSRF-protected,
 * throttled; routed only when the default guard has record users).
 *
 * Forgot answers alike for every address and mails a reset link only to an active record
 * account. The set-password page takes reset and invite links: opening one only shows the form
 * (mail scanners follow links), the POST uses the token up, sets the password and signs in.
 */
final class PasswordController
{
    public const string THROTTLE_FORGOT = 'folio.forgot';
    public const string THROTTLE_PASSWORD = 'folio.password';

    /** [attempts, seconds] */
    private const array FORGOT_LIMIT = [5, 3600];
    private const array PASSWORD_LIMIT = [10, 900];

    private const string THROTTLED = 'folio.auth.throttled';

    public function __construct(
        private readonly StaffPasswords $passwords,
        private readonly Throttle $throttle,
        private readonly AuthManager $auth,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly int $passwordMin = 10,
    ) {}

    public function forgotForm(ServerRequestInterface $request): ResponseInterface
    {
        return $this->forgotPage(false);
    }

    public function forgot(ServerRequestInterface $request): ResponseInterface
    {
        $email = mb_strtolower(trim((string) (((array) $request->getParsedBody())['email'] ?? '')));
        [$max, $window] = self::FORGOT_LIMIT;
        $client = $this->throttle->client($request);
        $key = hash('sha256', $email);
        if ($this->throttle->tooMany(self::THROTTLE_FORGOT, $client, $max, $window) || $this->throttle->tooMany(self::THROTTLE_FORGOT, $key, $max, $window)) {
            return $this->forgotPage(false, \Preflow\Folio\FolioText::get(self::THROTTLED), 429);
        }
        $this->throttle->hit(self::THROTTLE_FORGOT, $client, $window);
        $this->throttle->hit(self::THROTTLE_FORGOT, $key, $window);

        $account = $this->passwords->findActive($email);
        if ($account !== null) {
            try {
                $this->passwords->sendReset($account); // queued: the answer goes out first
            } catch (\Preflow\Folio\Users\MissingAppUrl) { // the answer must not differ
                error_log('folio: app.url is not set, so no reset link was sent for account ' . $account->getType()->key . ':' . $account->getId());
            } catch (\Throwable) { // no mail set up (config/notify.php): the answer must not differ
                error_log('folio: reset mail could not be queued for account ' . $account->getType()->key . ':' . $account->getId());
            }
        }

        return $this->forgotPage(true);
    }

    /** Opening the link only shows the form; the token is used up by the POST. */
    public function passwordForm(ServerRequestInterface $request): ResponseInterface
    {
        return $this->passwordPage((string) $request->getAttribute('token', ''));
    }

    public function password(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');
        [$max, $window] = self::PASSWORD_LIMIT;
        $client = $this->throttle->client($request);
        if ($this->throttle->tooMany(self::THROTTLE_PASSWORD, $client, $max, $window)) {
            return $this->passwordPage($token, \Preflow\Folio\FolioText::get(self::THROTTLED), 429);
        }
        $this->throttle->hit(self::THROTTLE_PASSWORD, $client, $window);

        if (mb_strlen($password) < $this->passwordMin) {
            return $this->passwordPage($token, \Preflow\Folio\FolioText::get('folio.auth.too_short', ['min' => $this->passwordMin]), 422);
        }
        if ($password !== (string) ($body['repeat'] ?? '')) {
            return $this->passwordPage($token, \Preflow\Folio\FolioText::get('folio.auth.mismatch'), 422);
        }

        $account = $this->passwords->consume($token);
        if ($account === null) {
            return $this->expiredPage();
        }
        // The new password ends the account's other sessions; this browser signs in afresh.
        if (!$this->passwords->setPassword($account, $password)) {
            return $this->expiredPage(); // disabled since the link was used up: the same answer
        }
        $user = $this->passwords->account($account);
        if ($user === null) {
            return $this->expiredPage();
        }
        $this->auth->guard()->login($user, $request);

        return new Response(302, ['Location' => $this->prefix]);
    }

    private function forgotPage(bool $sent, string $error = '', int $status = 200): ResponseInterface
    {
        return $this->render('forgot', [
            'forgot_action' => $this->prefix . '/forgot',
            'sent' => $sent,
            'error' => $error,
        ], $status);
    }

    private function passwordPage(string $token, string $error = '', int $status = 200): ResponseInterface
    {
        return $this->render('password', [
            'password_action' => $this->prefix . '/password',
            'token' => $token,
            'error' => $error,
            'expired' => false,
            'password_min' => $this->passwordMin,
        ], $status);
    }

    private function expiredPage(): ResponseInterface
    {
        return $this->render('password', [
            'password_action' => $this->prefix . '/password',
            'token' => '',
            'error' => '',
            'expired' => true,
            'password_min' => $this->passwordMin,
        ], 410);
    }

    /** @param array<string, mixed> $context */
    private function render(string $page, array $context, int $status): ResponseInterface
    {
        $html = $this->engine->render('@folio/admin/' . $page . '.twig', $context + [
            'login_url' => $this->prefix . '/login',
            'forgot_url' => $this->prefix . '/forgot',
        ]);

        return new Response($status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer', // the token stays out of Referer headers
        ], $html);
    }
}
