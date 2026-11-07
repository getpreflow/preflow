<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Builds the Symfony sanitizer (and its config) on the first sanitize call, so
 * requests that never touch rich text do not pay for it.
 *
 * @internal
 */
final class LazyHtmlSanitizer implements HtmlSanitizerInterface
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
