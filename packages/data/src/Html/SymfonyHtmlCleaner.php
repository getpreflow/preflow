<?php

declare(strict_types=1);

namespace Preflow\Data\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * The core's HtmlCleaner over symfony/html-sanitizer. standard() is the config Folio's rich text
 * field used through 0.92 (safe elements, relative links and relative media), so HTML Folio saved
 * comes back byte for byte. The sanitizer is built on the first clean(), so a request that never
 * writes rich text does not pay for it.
 *
 * @since 0.93.0
 */
final class SymfonyHtmlCleaner implements HtmlCleaner
{
    /** Test hook: how many real sanitizers were built. */
    public static int $built = 0;

    private ?HtmlSanitizerInterface $inner = null;

    /** @param \Closure(): HtmlSanitizerInterface $factory */
    public function __construct(private readonly \Closure $factory)
    {
    }

    public static function standard(): self
    {
        return new self(static function (): HtmlSanitizerInterface {
            self::$built++;

            return new HtmlSanitizer(
                (new HtmlSanitizerConfig())
                    ->allowSafeElements()
                    ->allowRelativeLinks()
                    ->allowRelativeMedias(),
            );
        });
    }

    public function clean(string $html): string
    {
        return $this->sanitizer()->sanitize($html);
    }

    /**
     * The Symfony sanitizer clean() uses, built on the first call: for code that needs Symfony's
     * interface (its sanitizeFor()), such as Folio's deprecated LazyHtmlSanitizer.
     */
    public function sanitizer(): HtmlSanitizerInterface
    {
        return $this->inner ??= ($this->factory)();
    }
}
