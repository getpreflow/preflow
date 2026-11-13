<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * Everything an editor needs to render one field instance.
 */
final readonly class FieldContext
{
    /**
     * @param list<string> $errors
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $name,
        public ?string $label = null,
        public ?string $help = null,
        public mixed $value = null,
        public array $errors = [],
        public array $config = [],
        public bool $required = false,
        public ?string $placeholder = null,
        /** @var array<string, list<string>> errors inside the field, keys relative to it (blocks: "<uid>.<field>") */
        public array $nestedErrors = [],
        public ?string $locale = null,
        public ?\Preflow\Data\Locales $locales = null,
        /** @var array{type: string, id: ?string}|null the record being edited */
        public ?array $record = null,
        /**
         * The field's declared rules ("validate"; rule strings such as "max:160"), so an editor can
         * show what they mean (the text field's counter, a URL's open button). @since 0.75
         *
         * @var list<mixed>
         */
        public array $validate = [],
        /** The field's declared "length" (the column's size), if any. @since 0.75 */
        public ?int $length = null,
        /** Set when the field is a route parameter of the record's public address (the string field's affix). @since 0.75 */
        public ?AddressAffix $address = null,
    ) {}

    /**
     * The longest text the field takes: the smaller of "length" and a "max:N" rule; null without either.
     *
     * @since 0.75
     */
    public function maxLength(): ?int
    {
        $max = $this->length !== null && $this->length > 0 ? $this->length : null;
        foreach ($this->validate as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', trim($rule), $m) === 1) {
                $max = $max === null ? (int) $m[1] : min($max, (int) $m[1]);
            }
        }

        return $max;
    }

    /** Whether the field holds a web address: "format": "url" or a "url" rule. @since 0.75 */
    public function isUrl(): bool
    {
        return ($this->config['format'] ?? null) === 'url' || in_array('url', array_map(static fn ($r) => is_string($r) ? trim($r) : $r, $this->validate), true);
    }
}
