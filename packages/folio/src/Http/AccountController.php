<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Throttle\Throttle;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Users\RecordUser;
use Preflow\Folio\Users\StaffPasswords;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The signed-in account's own page ({prefix}/_account, from the user menu): who you are, the
 * interface language (posted to {prefix}/_locale, LocaleController) and "change my password". Any signed-in record user of the default guard may use it, without a
 * permission (FolioPermissionMiddleware::SIGNED_IN); a config user has no page (404). Only the
 * password changes, through StaffPasswords::setPassword (the epoch goes up, other sessions,
 * remember-me logins and links end); this browser signs in afresh. The current password is
 * asked for, and the POST is throttled per account (folio.password).
 */
final class AccountController
{
    /** [attempts, seconds] */
    private const array LIMIT = [10, 900];

    public function __construct(
        private readonly StaffPasswords $passwords,
        private readonly Throttle $throttle,
        private readonly AuthManager $auth,
        private readonly TemplateEngineInterface $engine,
        private readonly TypeCatalog $catalog,
        private readonly string $prefix,
        private readonly int $passwordMin = 10,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user($request);

        return $user === null ? self::notFound() : $this->page($user);
    }

    public function password(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user($request);
        if ($user === null) {
            return self::notFound();
        }
        $record = $user->record();
        $key = 'account:' . $record->getType()->key . ':' . $record->getId();
        [$max, $window] = self::LIMIT;
        if ($this->throttle->tooMany(PasswordController::THROTTLE_PASSWORD, $key, $max, $window)) {
            return $this->page($user, 'Too many attempts. Try again later.', 429);
        }
        $this->throttle->hit(PasswordController::THROTTLE_PASSWORD, $key, $window);

        $body = (array) $request->getParsedBody();
        $password = (string) ($body['password'] ?? '');
        if (!$this->passwords->verify($record, (string) ($body['current'] ?? ''))) {
            return $this->page($user, 'The current password is wrong.', 422);
        }
        if (mb_strlen($password) < $this->passwordMin) {
            return $this->page($user, "The new password needs at least {$this->passwordMin} characters.", 422);
        }
        if ($password !== (string) ($body['repeat'] ?? '')) {
            return $this->page($user, "The two passwords don't match.", 422);
        }

        // Only the password: the record as stored, nothing from the form but the new password.
        if (!$this->passwords->setPassword($record, $password)) {
            return self::notFound(); // disabled meanwhile: as if the account were gone
        }
        $fresh = $this->passwords->account($record);
        if ($fresh !== null) {
            $this->auth->guard()->login($fresh, $request);
        }
        $session = $request->getAttribute(SessionInterface::class);
        if ($session instanceof SessionInterface) {
            $session->flash('success', 'Password changed. Your other sessions are signed out.');
        }

        return new Response(302, ['Location' => $this->prefix . '/_account']);
    }

    /** The signed-in record user, read afresh (null for a config user or an account that no longer resolves). */
    private function user(ServerRequestInterface $request): ?RecordUser
    {
        $user = $request->getAttribute(Authenticatable::class) ?? $this->auth->user();

        return $user instanceof RecordUser ? $this->passwords->account($user->record()) : null;
    }

    private function page(RecordUser $user, string $error = '', int $status = 200): ResponseInterface
    {
        $record = $user->record();
        $name = isset($record->getType()->fields['name']) ? trim((string) $record->get('name')) : '';
        $html = $this->engine->render('@folio/admin/account.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'email' => $user->getEmail(),
            'name' => $name,
            'error' => $error,
            'password_min' => $this->passwordMin,
            'password_action' => $this->prefix . '/_account/password',
            'locale_action' => $this->prefix . '/_locale',
        ]);

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store'], $html);
    }

    private static function notFound(): ResponseInterface
    {
        return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8'], 'Not found');
    }
}
