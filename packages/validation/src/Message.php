<?php

declare(strict_types=1);

namespace Preflow\Validation;

use Preflow\I18n\Translator;

/**
 * A rule's failure as a translation key ("validation.required") with its parameters. As a
 * string it is the English text of lang/en/validation.php, exactly the message a validator
 * without a translator gives; text() gives it in a translator's language, falling back to
 * that English text where the translator has no text for the key.
 */
final readonly class Message implements \Stringable
{
    /** The rules' own texts, the English of this package. */
    public const string LANG_PATH = __DIR__ . '/../lang';

    /** @param array<string, string> $params :name => value */
    public function __construct(
        public string $key,
        public array $params = [],
    ) {}

    public function text(?Translator $translator = null): string
    {
        if ($translator !== null) {
            $text = $translator->get($this->key, $this->params);
            if ($text !== $this->key) {
                return $text;
            }
        }

        return $this->english();
    }

    /** The English text; the key itself when the package has none for it. */
    public function english(): string
    {
        $value = self::englishTexts();
        foreach (explode('.', $this->key) as $i => $segment) {
            if ($i === 0) {
                if ($segment !== 'validation') {
                    return $this->key;
                }
                continue;
            }
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $this->key;
            }
            $value = $value[$segment];
        }
        if (!is_string($value)) {
            return $this->key;
        }
        foreach ($this->params as $name => $param) {
            $value = str_replace(':' . $name, $param, $value);
        }

        return $value;
    }

    public function __toString(): string
    {
        return $this->english();
    }

    /** @return array<string, mixed> */
    private static function englishTexts(): array
    {
        static $texts = null;

        return $texts ??= (array) require self::LANG_PATH . '/en/validation.php';
    }
}
