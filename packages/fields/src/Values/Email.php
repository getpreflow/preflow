<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

/**
 * The value of an email field: the address as text. It is stored as it is (trimmed); Email::href()
 * is the `mailto:` address for a page, null when the text is no email address.
 *
 * The local part is URL-encoded, so a "?" or "&" in it never becomes a mail header such as bcc; the
 * "@" and the domain stay as they are.
 *
 * @since 0.91.0
 */
final readonly class Email
{
    public function __construct(
        public string $address,
    ) {}

    /** The address a stored value holds; null for an empty or non-text value. */
    public static function fromStored(mixed $stored): ?self
    {
        if (!is_string($stored) || trim($stored) === '') {
            return null;
        }

        return new self(trim($stored));
    }

    public function toStored(): string
    {
        return $this->address;
    }

    /** "mailto:team@example.org"; null when the address is not a valid email address. */
    public function href(): ?string
    {
        if (filter_var($this->address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        $at = (int) strrpos($this->address, '@');

        return 'mailto:' . rawurlencode(substr($this->address, 0, $at)) . substr($this->address, $at);
    }
}
