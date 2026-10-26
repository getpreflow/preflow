<?php

declare(strict_types=1);

namespace Preflow\Routing;

/**
 * A path pattern as RouteEntry needs it: `{name}` matches one segment, `{...name}` the rest
 * (slashes included); everything else matches literally. For packages that add routes in code.
 */
final class RoutePattern
{
    /** @return array{regex: string, paramNames: list<string>, isCatchAll: bool} */
    public static function compile(string $pattern): array
    {
        $paramNames = [];
        $isCatchAll = false;
        $parts = preg_split('/(\{(?:\.\.\.)?\w+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{(\.\.\.)?(\w+)\}$/', $part, $m) === 1) {
                $paramNames[] = $m[2];
                $isCatchAll = $isCatchAll || $m[1] !== '';
                $regex .= '(?P<' . $m[2] . '>' . ($m[1] !== '' ? '.+' : '[^/]+') . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        return ['regex' => '#^' . $regex . '$#', 'paramNames' => $paramNames, 'isCatchAll' => $isCatchAll];
    }
}
