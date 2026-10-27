<?php

declare(strict_types=1);

namespace Preflow\Members\Http;

use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\RouteEntry;
use Preflow\Routing\RoutePattern;

/**
 * The member pages under the members path. Registered once: the locale middleware strips the
 * locale prefix before routing, so "/fr/konto/login" matches "/konto/login". The members home
 * (GET {path}) is the site's own page when it has one; else the default landing (see landing()).
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

    /**
     * The default landing, GET {path}. The provider adds it after the site's routes and only when
     * none of them answers that path (the matcher takes the first static match, so a later site
     * route would lose to it); null for a members path of "/", which is the site's home.
     */
    public static function landing(string $path): ?RouteEntry
    {
        $pattern = rtrim($path, '/');
        if ($pattern === '') {
            return null;
        }
        $compiled = RoutePattern::compile($pattern);

        return new RouteEntry(
            pattern: $pattern,
            handler: LandingController::class . '@show',
            method: 'GET',
            mode: RouteMode::Action,
            paramNames: $compiled['paramNames'],
            regex: $compiled['regex'],
            isCatchAll: $compiled['isCatchAll'],
        );
    }

    /** @return list<RouteEntry> */
    public static function entries(string $path): array
    {
        $entries = [];
        foreach (self::ROUTES as [$method, $below, $controller, $action]) {
            $pattern = rtrim($path, '/') . $below;
            $compiled = RoutePattern::compile($pattern);
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
