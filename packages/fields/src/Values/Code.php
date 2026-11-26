<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

/**
 * The value of a code field: the code as plain text. It is stored as that text, so loose search
 * finds it like any text. The language is a setting of the field (`"language": "php"`, see
 * CodeOptions), one per field, not part of each value; a template passes it to code_html().
 *
 * html() is the code for a page: escaped, in <pre><code>, with the language as the class
 * "language-php" the common highlighters read. The code is never output unescaped.
 *
 * @since 0.91.0
 */
final readonly class Code
{
    public ?string $language;

    public function __construct(
        public string $code,
        ?string $language = null,
    ) {
        if ($code === '') {
            throw new \InvalidArgumentException('A code value needs its code text; use null for no code.');
        }
        $this->language = $language !== null && self::isLanguage(trim($language)) ? trim($language) : null;
    }

    /** The code a stored value holds, with the field's $language; null for empty or non-text values. */
    public static function fromStored(mixed $stored, ?string $language = null): ?self
    {
        return is_string($stored) && $stored !== '' ? new self($stored, $language) : null;
    }

    public function toStored(): string
    {
        return $this->code;
    }

    /** Whether $language is a short name that can be a class name: "php", "c++", "objective-c". */
    public static function isLanguage(string $language): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9+#._-]{0,31}$/', $language) === 1;
    }

    /** `<pre><code class="language-php">…</code></pre>`, the code escaped; no class without a language. */
    public function html(): string
    {
        $class = $this->language !== null ? ' class="language-' . htmlspecialchars($this->language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';

        return '<pre><code' . $class . '>' . htmlspecialchars($this->code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
    }
}
