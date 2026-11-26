<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

/**
 * The value of a code field: the code text and, when the editor named one, its language
 * ("php", "javascript", "html"; a short name, see isLanguage()).
 *
 * Stored as an object with both keys (toStored()):
 *
 *     {"code": "<?php echo 1;", "language": "php"}
 *
 * html() is the code for a page: escaped, in <pre><code>, with the language as the class
 * "language-php" the common highlighters read. The code is never output unescaped.
 *
 * @since 0.91.0
 */
final readonly class Code
{
    /** The language as a class-safe short name: letters, digits and + # . _ - (what isLanguage() accepts). */
    private const string LANGUAGE = '/^[A-Za-z0-9][A-Za-z0-9+#._-]{0,31}$/';

    public ?string $language;

    public function __construct(
        public string $code,
        ?string $language = null,
    ) {
        if ($code === '') {
            throw new \InvalidArgumentException('A code value needs its code text; use null for no code.');
        }
        $this->language = $language === null || trim($language) === '' ? null : trim($language);
    }

    /**
     * The code a stored value holds: the object toStored() writes, JSON text of it (SQL storage), or
     * plain text (code without a language). Null for an empty value; reading never fails.
     */
    public static function fromStored(mixed $stored): ?self
    {
        if (is_string($stored) && !str_starts_with(ltrim($stored), '{')) {
            return $stored === '' ? null : new self($stored);
        }
        $data = Link::decode($stored);
        if ($data === null || !is_string($data['code'] ?? null) || $data['code'] === '') {
            return null;
        }

        return new self($data['code'], is_string($data['language'] ?? null) ? $data['language'] : null);
    }

    /** @return array{code: string, language: string|null} */
    public function toStored(): array
    {
        return ['code' => $this->code, 'language' => $this->language];
    }

    /** Whether $language is a short name that can be a class name: "php", "c++", "objective-c". */
    public static function isLanguage(string $language): bool
    {
        return preg_match(self::LANGUAGE, $language) === 1;
    }

    /** `<pre><code class="language-php">…</code></pre>`, the code escaped; no class without a (valid) language. */
    public function html(): string
    {
        $class = $this->language !== null && self::isLanguage($this->language) ? ' class="language-' . htmlspecialchars($this->language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';

        return '<pre><code' . $class . '>' . htmlspecialchars($this->code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
    }
}
