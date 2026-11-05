<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

/**
 * A page as the SEO audit rendered it: its absolute URL, locale, status, headers
 * (as PSR-7 getHeaders() gives them) and body. Checks read the HTML through
 * dom(), parsed on first use and kept.
 *
 * @since 0.54
 */
final readonly class RenderedPage
{
    /** The parsed body, or false when it isn't HTML; unset until dom() is first called. */
    private \DOMDocument|false $document;

    /** @param array<string, list<string>> $headers */
    public function __construct(
        public string $url,
        public string $locale,
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    /** A header's values joined with ", " (any case of $name); '' when absent. */
    public function header(string $name): string
    {
        $wanted = strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (strtolower((string) $key) === $wanted) {
                return implode(', ', (array) $values);
            }
        }

        return '';
    }

    /**
     * The body as a DOM (parsed once, as UTF-8, parse errors ignored); null when
     * it is empty or the response isn't HTML.
     */
    public function dom(): ?\DOMDocument
    {
        if (!isset($this->document)) {
            $this->document = $this->parse() ?? false;
        }

        return $this->document ?: null;
    }

    private function parse(): ?\DOMDocument
    {
        $type = $this->header('Content-Type');
        if (trim($this->body) === '' || ($type !== '' && !str_contains(strtolower($type), 'html'))) {
            return null;
        }

        // libxml reads HTML as Latin-1 unless told otherwise. The encoding hint (not entities,
        // which <script> and <style> would keep as raw text) makes it read UTF-8; its node goes after.
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = $document->loadHTML('<?xml encoding="UTF-8">' . $this->body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$parsed) {
            return null;
        }

        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node instanceof \DOMProcessingInstruction) {
                $document->removeChild($node);
            }
        }

        return $document;
    }
}
