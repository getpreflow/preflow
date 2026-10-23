<?php

declare(strict_types=1);

namespace Preflow\Members\Http;

use Preflow\Core\Routing\RouteMode;
use Preflow\Folio\Routing\PatternCompiler;
use Preflow\Routing\RouteEntry;

/**
 * The member pages under the members path. Registered once: the locale middleware strips the
 * locale prefix before routing, so "/fr/konto/login" matches "/konto/login". The members home
 * (GET {path}) is the site's own page.
 */
final class MembersRoutes
{
    /** method, path below {path}, controller, action */
    private const array ROUTES = [
        ['GET', '/register', RegistrationController::class, 'form'],
        ['POST', '/register', RegistrationController::class, 'register'],
        ['GET', '/confirm/{token}', RegistrationController::class, 'confirmPage'],
        ['POST', '/confirm', RegistrationController::class, 'confirm'],
        ['GET', '/login', AuthController::class, 'form'],
        ['POST', '/login', AuthController::class, 'login'],
        ['POST', '/logout', AuthController::class, 'logout'],
        ['GET', '/forgot', AuthController::class, 'forgotForm'],
        ['POST', '/forgot', AuthController::class, 'forgot'],
        ['GET', '/reset/{token}', AuthController::class, 'resetForm'],
        ['POST', '/reset', AuthController::class, 'reset'],
        ['GET', '/resend', AuthController::class, 'resendForm'],
        ['POST', '/resend', AuthController::class, 'resend'],
        ['GET', '/account', AccountController::class, 'show'],
        ['POST', '/account/password', AccountController::class, 'password'],
        ['POST', '/account/delete', AccountController::class, 'delete'],
    ];

    /** @return list<RouteEntry> */
    public static function entries(string $path): array
    {
        $entries = [];
        foreach (self::ROUTES as [$method, $below, $controller, $action]) {
            $pattern = rtrim($path, '/') . $below;
            $compiled = PatternCompiler::compile($pattern);
            $entries[] = new RouteEntry(
                pattern: $pattern,
                handler: $controller . '@' . $action,
                method: $method,
                mode: RouteMode::Action,
                paramNames: $compiled['paramNames'],
                regex: $compiled['regex'],
                isCatchAll: $compiled['isCatchAll'],
            );
        }

        return $entries;
    }
}
