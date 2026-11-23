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
 * is answered 500 from a shutdown function where no output has gone out yet. Output that has gone
 * out already (headers sent) cannot change the status, but the error page is still printed, never
 * silence. The full exception, trace included, goes to the site's logger (else the PHP log). An
 * exception handler set before create() is kept and called after the answer.
 *
 * @since 0.84.2
 */
final class FailureGuard
{
    private static ?Application $app = null;

    /**
     * Makes $app answer failures outside the kernel for the rest of the process. The last app
     * installed wins. The PHP handlers are registered once; an exception handler set before is
     * kept and called after the answer (see answer()).
     *
     * @since 0.84.2
     */
    public static function install(Application $app): void
    {
        $first = self::$app === null;
        self::$app = $app;
        if (!$first) {
            return;
        }
        $previous = set_exception_handler(null);
        set_exception_handler(static function (\Throwable $e) use ($previous): void {
            self::answer($e, $previous);
        });
        // Memory held back for a memory-exhaustion fatal: freed first in the shutdown function, so the error page can load.
        $reserve = str_repeat('x', 262144);
        register_shutdown_function(static function () use (&$reserve): void {
            $reserve = null;
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
        $container = $app->container();
        try {
            if ($container->bound(ErrorHandler::class)) {
                return $container->get(ErrorHandler::class)->handleException($e, $request);
            }
        } catch (\Throwable) {
            // the app's handler or error page is what failed: fall through to the built-in page
        }

        $status = $e instanceof HttpException ? $e->statusCode : 500;
        try {
            $body = self::builtIn($app)->render($e, $request, $status);
        } catch (\Throwable) {
            $body = '<!DOCTYPE html><title>500</title><p>Internal Server Error</p>';
            $status = 500;
        }

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }

    private static function builtIn(Application $app): ErrorRendererInterface
    {
        return (int) $app->config()->get('app.debug', 0) !== 0 ? new DevErrorRenderer() : new ProdErrorRenderer();
    }

    /**
     * Answers an exception nothing caught: logs it in full, then sends the page (status and
     * headers too while nothing has gone out; after output started only the page, so the error
     * is never silent). $previous, the handler set before the guard, runs after the answer: it
     * may report the error (a tracker) but must not decide the page; its output is dropped.
     */
    private static function answer(\Throwable $e, ?callable $previous): void
    {
        $app = self::$app;
        self::log($app, $e);
        try {
            $response = self::response($app, $e);
            if (!headers_sent()) {
                // Half-rendered output and queued headers (cookies, a Location) must not reach the error page.
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                header_remove();
                (new Emitter())->emit($response);
            } else {
                echo $response->getBody();
            }
        } catch (\Throwable) {
            echo 'Internal Server Error';
        }
        if ($previous !== null) {
            ob_start();
            try {
                $previous($e);
            } catch (\Throwable) {
                // a failing tracker must not turn the answered error into another one
            } finally {
                ob_end_clean();
            }
        }
    }

    /** The whole exception (previous chain and trace) to the site's logger, else the PHP log. */
    private static function log(Application $app, \Throwable $e): void
    {
        $message = 'Uncaught ' . $e;
        try {
            $container = $app->container();
            if (interface_exists(\Psr\Log\LoggerInterface::class) && $container->bound(\Psr\Log\LoggerInterface::class)) {
                $container->get(\Psr\Log\LoggerInterface::class)->error($message, ['exception' => $e]);

                return;
            }
        } catch (\Throwable) {
            // no usable logger: the PHP log below
        }
        error_log($message);
    }

    /** @param array{type: int, message: string, file: string, line: int}|null $error */
    private static function answerFatal(?array $error): void
    {
        // With output sent, PHP has shown the fatal error itself: nothing to add.
        if (headers_sent()) {
            return;
        }
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        if ($error === null || ($error['type'] & $fatal) === 0 || self::$app === null) {
            return;
        }
        $e = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        // Memory may be gone: no app handler here, only the built-in page.
        try {
            $body = self::builtIn(self::$app)->render($e, self::request(), 500);
        } catch (\Throwable) {
            $body = 'Internal Server Error';
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header_remove();
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        echo $body;
    }

    private static function request(): ServerRequestInterface
    {
        try {
            return \Preflow\Core\Http\GlobalRequest::create();
        } catch (\Throwable) {
            // no PSR-7 server package, or a request PHP cannot describe: a bare request is enough for an error page
            return new \Nyholm\Psr7\ServerRequest('GET', '/');
        }
    }
}
