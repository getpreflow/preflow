<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

/**
 * The Allow and Disallow rules robots.txt gives every crawler (`User-agent: *`),
 * read the way RobotsTxt writes them and SeoMiddleware matches them: a prefix,
 * `*` for any characters, a closing `$` for the end; the longest matching rule
 * wins, Allow on a tie.
 *
 * @internal
 * @since 0.54
 */
final class RobotsRules
{
    /** @param list<array{0: bool, 1: string}> $rules [allow?, path rule] */
    public function __construct(public readonly array $rules = []) {}

    public static function parse(string $text): self
    {
        $rules = [];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if (!str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = $value;
            } elseif ($field === 'allow' || $field === 'disallow') {
                $inRules = true;
                if ($value !== '' && in_array('*', $agents, true)) {
                    $rules[] = [$field === 'allow', $value];
                }
            }
        }

        return new self($rules);
    }

    /** Whether crawlers are kept from $path (as written in the URL). */
    public function disallows(string $path): bool
    {
        $best = -1;
        $allowed = true;
        foreach ($this->rules as [$allow, $rule]) {
            $length = strlen($rule);
            if (($length > $best || ($length === $best && $allow)) && self::covers($path, $rule)) {
                $best = $length;
                $allowed = $allow;
            }
        }

        return !$allowed;
    }

    /** As SeoMiddleware reads a rule; a path that is the rule's directory without its slash counts too. */
    private static function covers(string $path, string $rule): bool
    {
        $end = str_ends_with($rule, '$');
        $pattern = '~^' . str_replace('\\*', '.*', preg_quote($end ? substr($rule, 0, -1) : $rule, '~')) . ($end ? '$' : '') . '~';

        return preg_match($pattern, $path) === 1 || (!$end && str_ends_with($rule, '/') && $path . '/' === $rule);
    }
}
