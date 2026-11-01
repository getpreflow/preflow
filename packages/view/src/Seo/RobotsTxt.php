<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/** robots.txt: search and assistant crawlers always welcome, AI training by config. */
final class RobotsTxt
{
    private const SEARCH_BOTS = ['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User'];
    private const TRAINING_BOTS = ['GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'meta-externalagent'];

    /**
     * @param list<string> $extraDisallow paths on top of the config's, e.g. from the site's gated routes
     * @param list<string> $allow         exceptions under those paths, in every group that allows the site
     */
    public static function render(SeoConfig $config, array $extraDisallow, string $origin, bool $llms, array $allow = []): string
    {
        $origin = rtrim($origin, '/');
        $disallow = array_map(
            static fn (string $path): string => 'Disallow: ' . $path,
            array_values(array_unique([...$config->disallow, ...$extraDisallow])),
        );
        $rules = [
            'Allow: /',
            ...array_map(static fn (string $path): string => 'Allow: ' . $path, array_values(array_unique($allow))),
            ...$disallow,
        ];

        $groups = [
            ['User-agent: *', ...$rules],
            ['# AI search and assistant crawlers', ...self::agents(self::SEARCH_BOTS), ...$rules],
            // Training crawlers kept out get no exceptions.
            ['# AI training crawlers', ...self::agents(self::TRAINING_BOTS), ...($config->aiTraining ? $rules : ['Disallow: /'])],
        ];

        $text = implode("\n\n", array_map(static fn (array $lines): string => implode("\n", $lines), $groups)) . "\n";

        $tail = $config->robotsExtra;
        if ($config->sitemap) {
            $tail[] = 'Sitemap: ' . $origin . '/sitemap.xml';
        }
        if ($llms) {
            $tail[] = '# LLM-readable site index: ' . $origin . '/llms.txt';
        }

        return $tail === [] ? $text : $text . "\n" . implode("\n", $tail) . "\n";
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function agents(array $names): array
    {
        return array_map(static fn (string $n): string => 'User-agent: ' . $n, $names);
    }
}
