<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * The `Vary` header of a response, merged: the one place a middleware adds the request headers an
 * answer depends on (the hypermedia client's, the preview token, Origin, the locale's, Accept). The
 * names already there stay, in their order and spelling, and each name is listed once
 * (case-insensitive), so no middleware drops another's. A `Vary: *` already varies on everything.
 *
 * @since 0.84.0
 */
final class Vary
{
    /**
     * $response with each of $names in its Vary header, after the names it has; several Vary lines
     * become one. The response itself when nothing is new or it says `Vary: *`.
     *
     * @since 0.84.0
     */
    public static function add(ResponseInterface $response, string ...$names): ResponseInterface
    {
        $present = [];
        foreach (explode(',', $response->getHeaderLine('Vary')) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $present[strtolower($name)] ??= $name;
            }
        }
        if (isset($present['*'])) {
            return $response;
        }
        $merged = $present;
        foreach ($names as $name) {
            $name = trim($name);
            if ($name !== '') {
                $merged[strtolower($name)] ??= $name;
            }
        }

        return count($merged) === count($present) ? $response : $response->withHeader('Vary', implode(', ', $merged));
    }
}
