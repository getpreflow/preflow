<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/**
 * What a page says about itself to search engines and link previews.
 * URLs are absolute; whoever builds the meta resolves them.
 */
final readonly class PageMeta
{
    /**
     * @param ?array{url: string, width: int, height: int, alt: string} $image share image, as MediaLibrary::shareImage() returns it
     * @param list<string> $alternateLocales other locales the page exists in
     * @param list<array>  $schemas          extra JSON-LD nodes for this page
     * @param bool         $publicContent    the content is the same for everyone although the response is private
     *                                       (it embeds one visitor's tokens): cards and JSON-LD all the same (since 0.53.1)
     * @param string       $modified         when the content last changed (ISO 8601; '' unknown): og:updated_time,
     *                                       the WebPage's dateModified and the Markdown view's Updated line (since 0.55)
     */
    public function __construct(
        public string $title = '',
        public string $description = '',
        public ?array $image = null,
        public string $url = '',
        public string $locale = '',
        public array $alternateLocales = [],
        public bool $noindex = false,
        public string $ogType = 'website',
        public array $schemas = [],
        public bool $publicContent = false,
        public string $modified = '',
    ) {}

    /**
     * A time as `modified` takes it: ISO 8601 with its offset (DATE_ATOM), from a string that
     * starts with a real date (`2026-11-05`, `2026-11-05T10:00:00+01:00`) or a DateTimeInterface;
     * '' for anything else (null, "yesterday", 2026-02-30). Since 0.55.
     */
    public static function time(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $m) !== 1) {
            return '';
        }
        try {
            $time = new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return '';
        }

        // DateTime rolls 2026-02-30 over to March: only a date that exists counts.
        return $time->format('Y-m-d') === $m[1] ? $time->format(DATE_ATOM) : '';
    }

    /** A copy with some properties changed; keys are the constructor's parameter names. */
    public function with(array $changes): self
    {
        $unknown = array_diff_key($changes, get_object_vars($this));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown page meta key(s): ' . implode(', ', array_keys($unknown)));
        }

        return new self(...array_replace(get_object_vars($this), $changes));
    }
}
