<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Nyholm\Psr7\Response;
use Preflow\Core\Application;
use Preflow\Core\Exceptions\HttpException;
use Preflow\Core\Http\Emitter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What answers a web request that fails outside the kernel: an exception thrown while the app
 * boots (a wrong provider line, a weak key), a PHP Error that reaches the front controller, a
 * fatal error. Without it PHP prints "Fatal error: Uncaught …" with the stack trace and, with
 * display_errors on, the status 200.
 *
 * Application::create() installs it for web SAPIs (not the console), so a site's public/index.php
 * needs no change. An uncaught exception is answered 500 (or its HttpException status) with the
 * app's error handler once boot built it; before that, with the dev error page when app.debug is
 * on and the plain page (no trace) when off. A fatal error, which PHP cannot hand to a handler,
 * is answered 500 from a shutdown function where no output has gone out yet.
 *
 * @since 0.84.2
 */
final class FailureGuard
{
    private static ?Application $app = null;
    private static bool $installed = false;
    /** memory held back for a memory-exhaustion fatal: freed first in the shutdown function, so the error page can load */
    private static ?string $reserve = null;

    /**
     * Makes $app answer failures outside the kernel for the rest of the process. The last app
     * installed wins. Idempotent: the PHP handlers are registered once.
     *
     * @since 0.84.2
     */
    public static function install(Application $app): void
    {
        self::$app = $app;
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        self::$reserve = str_repeat('x', 262144);
        set_exception_handler(static function (\Throwable $e): void {
            self::answer($e);
        });
        register_shutdown_function(static function (): void {
            self::answerFatal(error_get_last());
        });
    }

    /**
     * The response for $e: the app's error handler when boot built it, else the plain page (the
     * dev page with debug on). Never throws; a failing handler falls back to the built-in page.
     *
     * @since 0.84.2
     */
    public static function response(Application $app, \Throwable $e, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $request ??= self::request();
        $debug = (int) $app->config()->get('app.debug', 0) !== 0;
        $container = $app->container();
        try {
            if ($container->bound(ErrorHandler::class)) {
                return $container->get(ErrorHandler::class)->handleException($e, $request);
            }
        } catch (\Throwable) {
            // the app's handler or error page is what failed: fall through to the built-in page
        }

        $status = $e instanceof HttpException ? $e->statusCode : 500;
        $renderer = $debug ? new DevErrorRenderer() : new ProdErrorRenderer();
        try {
            $body = $renderer->render($e, $request, $status);
        } catch (\Throwable) {
            $body = '<!DOCTYPE html><title>500</title><p>Internal Server Error</p>';
            $status = 500;
        }

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }

    private static function answer(\Throwable $e): void
    {
        $app = self::$app;
        error_log('Preflow: uncaught ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if ($app === null || headers_sent()) {
            return;
        }
        // Half-rendered output must not lead the error page.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        try {
            (new Emitter())->emit(self::response($app, $e));
        } catch (\Throwable) {
            http_response_code(500);
            echo 'Internal Server Error';
        }
    }

    /** @param array{type: int, message: string, file: string, line: int}|null $error */
    private static function answerFatal(?array $error): void
    {
        self::$reserve = null;
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        if ($error === null || ($error['type'] & $fatal) === 0 || headers_sent()) {
            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $app = self::$app;
        $e = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        // Memory may be gone: no app handler here, only the built-in page.
        $debug = $app !== null && (int) $app->config()->get('app.debug', 0) !== 0;
        $renderer = $debug ? new DevErrorRenderer() : new ProdErrorRenderer();
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        try {
            echo $renderer->render($e, self::request(), 500);
        } catch (\Throwable) {
            echo 'Internal Server Error';
        }
    }

    private static function request(): ServerRequestInterface
    {
        try {
            if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)
                && class_exists(\Nyholm\Psr7Server\ServerRequestCreator::class)) {
                $factory = new \Nyholm\Psr7\Factory\Psr17Factory();

                return (new \Nyholm\Psr7Server\ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
            }
        } catch (\Throwable) {
        }

        return new \Nyholm\Psr7\ServerRequest('GET', '/');
    }
}
