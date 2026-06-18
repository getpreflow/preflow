<?php

declare(strict_types=1);

namespace Preflow\Folio\Routing;

final class PatternCompiler
{
    /**
     * Compile a route pattern into a regex with named groups.
     * `{name}` -> single segment; `{...name}` -> catch-all (matches slashes).
     *
     * @return array{regex: string, paramNames: string[], isCatchAll: bool}
     */
    public static function compile(string $pattern): array
    {
        $paramNames = [];
        $isCatchAll = false;
        $out = '';
        $i = 0;
        $len = strlen($pattern);

        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch === '{') {
                $end = strpos($pattern, '}', $i);
                $token = substr($pattern, $i + 1, $end - $i - 1);
                if (str_starts_with($token, '...')) {
                    $name = substr($token, 3);
                    $isCatchAll = true;
                    $out .= '(?P<' . $name . '>.+)';
                } else {
                    $name = $token;
                    $out .= '(?P<' . $name . '>[^/]+)';
                }
                $paramNames[] = $name;
                $i = $end + 1;
            } else {
                $out .= preg_quote($ch, '#');
                $i++;
            }
        }

        return ['regex' => '#^' . $out . '$#', 'paramNames' => $paramNames, 'isCatchAll' => $isCatchAll];
    }
}
