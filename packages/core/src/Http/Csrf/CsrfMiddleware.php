<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    private const FORM_FIELD = '_csrf_token';
    private const HEADER_NAME = 'X-CSRF-Token';

    public function __construct(private readonly array $exempt = ['/--component/']) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session === null) {
            return $handler->handle($request);
        }

        $token = CsrfToken::generate($session);
        $request = $request->withAttribute(CsrfToken::class, $token);

        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        if ($this->isExempt($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $submitted = $this->extractSubmittedToken($request);

        if ($submitted === null || !hash_equals($token->getValue(), $submitted)) {
            throw new ForbiddenHttpException('CSRF token mismatch.');
        }

        return $handler->handle($request);
    }

    private function isExempt(string $path): bool
    {
        foreach ($this->exempt as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function extractSubmittedToken(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();

        if (is_array($body) && isset($body[self::FORM_FIELD])) {
            return (string) $body[self::FORM_FIELD];
        }

        $header = $request->getHeaderLine(self::HEADER_NAME);
        if ($header !== '') {
            return $header;
        }

        return null;
    }
}
