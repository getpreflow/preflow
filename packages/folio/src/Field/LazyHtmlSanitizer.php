<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Data\Html\SymfonyHtmlCleaner;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Builds the Symfony sanitizer (and its config) on the first sanitize call, so
 * requests that never touch rich text do not pay for it.
 *
 * Since 0.93 Folio cleans rich text with the core's cleaner (DataManager::htmlCleaner(), a site's
 * bound HtmlCleaner included); standard() is the core's SymfonyHtmlCleaner::standard() sanitizer
 * (the same config), built on the first sanitize call.
 *
 * @internal
 * @deprecated since 0.93.0, removed no earlier than 0.95: use Preflow\Data\Html\HtmlCleaner
 *             (DataManager::htmlCleaner(), or SymfonyHtmlCleaner::standard()).
 */
final class LazyHtmlSanitizer implements HtmlSanitizerInterface
{
    /** Test hook: how many real sanitizers were built (the core's is SymfonyHtmlCleaner::$built). */
    public static int $built = 0;

    private ?HtmlSanitizerInterface $inner = null;

    /** @param \Closure(): HtmlSanitizerInterface $factory */
    public function __construct(private readonly \Closure $factory)
    {
    }

    /** The core cleaner's sanitizer (SymfonyHtmlCleaner::standard()), built on the first sanitize call. */
    public static function standard(): self
    {
        $cleaner = SymfonyHtmlCleaner::standard();

        return new self(static function () use ($cleaner): HtmlSanitizerInterface {
            self::$built++;

            return $cleaner->sanitizer();
        });
    }

    public function sanitize(string $input): string
    {
        return $this->inner()->sanitize($input);
    }

    public function sanitizeFor(string $element, string $input): string
    {
        return $this->inner()->sanitizeFor($element, $input);
    }

    private function inner(): HtmlSanitizerInterface
    {
        return $this->inner ??= ($this->factory)();
    }
}
