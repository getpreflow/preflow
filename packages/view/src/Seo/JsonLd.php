<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

final class JsonLd
{
    /**
     * A schema.org graph as JSON, safe to print inside a <script> element:
     * `<`, `>`, `&` and quotes are \u-escaped, so no `</script>` can appear.
     *
     * @param array<array> $graph
     */
    public static function encode(array $graph): string
    {
        return json_encode(
            ['@context' => 'https://schema.org', '@graph' => array_values($graph)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }
}
