<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

use Preflow\Data\Locales;
use Preflow\Fields\AssetAddresses;
use Preflow\Fields\RecordAddresses;
use Preflow\Validation\Rules\Phone;

/**
 * The value of a link field: what it points to (its kind and target), the text it shows (its
 * label: one text, or a text per locale) and whether it opens in a new tab.
 *
 * Stored as an object with the keys kind, target, label and new_tab (toStored()):
 *
 *     {"kind": "record", "target": {"type": "page", "id": "p1"}, "label": {"en": "About us"}, "new_tab": false}
 *     {"kind": "url",    "target": "https://example.org", "label": "Example", "new_tab": true}
 *
 * kind "email", "phone" and "asset" take the address, the number as typed, or the asset id as
 * their target. A JSON file holds the object, SQL storage JSON text of it; fromStored() reads both.
 *
 * href() is the address a page links to: a record's current address through RecordAddresses
 * (null when it is gone or unpublished), mailto:, tel: (the digits), a file through
 * AssetAddresses. A web address is only used when it is http(s) or a path on the site, so a stored
 * "javascript:" never becomes an href.
 *
 * @since 0.91.0
 */
final readonly class Link
{
    /** @var array{type: string, id: string}|string */
    public array|string $target;

    /** @var string|array<string, string>|null one text, a text per locale, or none */
    public string|array|null $label;

    /**
     * @param array<mixed>|string $target a record's ["type" => …, "id" => …] (the id as text or a number), else the address, number or asset id
     * @param string|array<string, string>|null $label empty texts are dropped
     */
    public function __construct(
        public LinkKind $kind,
        array|string $target,
        string|array|null $label = null,
        public bool $newTab = false,
    ) {
        if ($kind === LinkKind::Record) {
            $type = is_array($target) ? ($target['type'] ?? null) : null;
            $id = is_array($target) ? ($target['id'] ?? null) : null;
            if (!is_string($type) || $type === '' || !(is_string($id) || is_int($id)) || (string) $id === '') {
                throw new \InvalidArgumentException('A record link needs its target as ["type" => …, "id" => …] (the record\'s type key and id), got ' . json_encode($target) . '.');
            }
            $this->target = ['type' => $type, 'id' => (string) $id];
        } else {
            if (!is_string($target) || trim($target) === '') {
                throw new \InvalidArgumentException("A {$kind->value} link needs its target as text (" . self::targetWord($kind) . '), got ' . json_encode($target) . '.');
            }
            $this->target = trim($target);
        }
        $this->label = self::cleanLabel($label);
    }

    /** @param string|array<string, string>|null $label */
    public static function toRecord(string $type, string $id, string|array|null $label = null, bool $newTab = false): self
    {
        return new self(LinkKind::Record, ['type' => $type, 'id' => $id], $label, $newTab);
    }

    /** @param string|array<string, string>|null $label */
    public static function toUrl(string $url, string|array|null $label = null, bool $newTab = false): self
    {
        return new self(LinkKind::Url, $url, $label, $newTab);
    }

    /** @param string|array<string, string>|null $label */
    public static function toEmail(string $address, string|array|null $label = null, bool $newTab = false): self
    {
        return new self(LinkKind::Email, $address, $label, $newTab);
    }

    /** @param string|array<string, string>|null $label */
    public static function toPhone(string $number, string|array|null $label = null, bool $newTab = false): self
    {
        return new self(LinkKind::Phone, $number, $label, $newTab);
    }

    /** @param string|array<string, string>|null $label */
    public static function toAsset(string $assetId, string|array|null $label = null, bool $newTab = false): self
    {
        return new self(LinkKind::Asset, $assetId, $label, $newTab);
    }

    /**
     * The link a stored value holds: the object toStored() writes, or JSON text of it (SQL storage).
     * Null for an empty value (null, '', an empty object) and for a value that is no link (an
     * unknown kind, a missing target): reading never fails; ValidLink refuses such values on save.
     */
    public static function fromStored(mixed $stored): ?self
    {
        $data = self::decode($stored);
        if ($data === null) {
            return null;
        }
        $kind = is_string($data['kind'] ?? null) ? LinkKind::tryFrom($data['kind']) : null;
        if ($kind === null) {
            return null;
        }
        $label = $data['label'] ?? null;
        try {
            return new self(
                $kind,
                is_array($data['target'] ?? null) || is_string($data['target'] ?? null) ? $data['target'] : '',
                is_string($label) || is_array($label) ? self::stringMap($label) : null,
                self::flag($data['new_tab'] ?? false),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The stored shape: kind, target, label and new_tab, always all four.
     *
     * @return array{kind: string, target: array{type: string, id: string}|string, label: string|array<string, string>|null, new_tab: bool}
     */
    public function toStored(): array
    {
        return ['kind' => $this->kind->value, 'target' => $this->target, 'label' => $this->label, 'new_tab' => $this->newTab];
    }

    /** The record's type of a record link; null for any other kind. */
    public function recordType(): ?string
    {
        return is_array($this->target) ? $this->target['type'] : null;
    }

    /** The record's id of a record link; null for any other kind. */
    public function recordId(): ?string
    {
        return is_array($this->target) ? $this->target['id'] : null;
    }

    /**
     * The address a page links to in $locale; null when there is none: a record or file that is
     * gone, unpublished or without RecordAddresses (AssetAddresses), an address that is not http(s)
     * or a path on the site, an invalid email address or phone number.
     */
    public function href(?RecordAddresses $addresses, string $locale): ?string
    {
        $target = $this->target;
        if (is_array($target)) {
            return $addresses?->addressOf($target['type'], $target['id'], $locale);
        }

        return match ($this->kind) {
            LinkKind::Url => self::isSiteOrWebAddress($target) ? $target : null,
            LinkKind::Email => filter_var($target, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:' . $target : null,
            LinkKind::Phone => ($dialable = Phone::dialable($target)) !== null ? 'tel:' . $dialable : null,
            LinkKind::Asset => $addresses instanceof AssetAddresses ? $addresses->assetAddressOf($target) : null,
            LinkKind::Record => null,
        };
    }

    /**
     * The label in $locale: a plain label in every locale; a text per locale in $locale, and with
     * $locales along its fallback chain (locale, fallback, default). Null without one.
     */
    public function label(string $locale, ?Locales $locales = null): ?string
    {
        if (!is_array($this->label)) {
            return $this->label;
        }
        foreach ($locales?->chain($locale) ?? [$locale] as $candidate) {
            if (isset($this->label[$candidate])) {
                return $this->label[$candidate];
            }
        }

        return null;
    }

    /**
     * The attributes of an <a> for this link: href, and target="_blank" with rel="noopener
     * noreferrer" when it opens in a new tab. Nothing when it has no href (no target either).
     *
     * @return array<string, string>
     */
    public function attributes(?RecordAddresses $addresses, string $locale): array
    {
        $href = $this->href($addresses, $locale);
        if ($href === null) {
            return [];
        }

        return ['href' => $href] + ($this->newTab ? ['target' => '_blank', 'rel' => 'noopener noreferrer'] : []);
    }

    /**
     * Whether $url may be a link's href: an http(s) address with a host, or a path on the site
     * ("/contact", "/a?b#c"; not "//host", which leaves the site). Nothing else: no "javascript:",
     * "data:" or other schemes, no bare host names.
     */
    public static function isSiteOrWebAddress(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }
        if ($url[0] === '/') {
            return !str_starts_with($url, '//') && !str_starts_with($url, '/\\');
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && (string) parse_url($url, PHP_URL_HOST) !== ''
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * A stored value as its object: an array, or JSON text of one; null when it holds nothing
     * (null, '', an empty object) or is no object (text, a list).
     *
     * @return array<mixed>|null
     */
    public static function decode(mixed $stored): ?array
    {
        if (is_string($stored)) {
            $stored = trim($stored);
            if (!str_starts_with($stored, '{')) {
                return null;
            }
            $stored = json_decode($stored, true);
        }
        if (!is_array($stored) || $stored === [] || array_is_list($stored)) {
            return null;
        }

        return $stored;
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }

    /**
     * @param string|array<mixed> $label
     * @return string|array<string, string>
     */
    private static function stringMap(string|array $label): string|array
    {
        return is_string($label) ? $label : self::texts($label);
    }

    /**
     * @param array<mixed> $label
     * @return array<string, string> the locale => text entries
     */
    private static function texts(array $label): array
    {
        $map = [];
        foreach ($label as $locale => $text) {
            if (is_string($locale) && is_string($text)) {
                $map[$locale] = $text;
            }
        }

        return $map;
    }

    /**
     * @param string|array<mixed>|null $label
     * @return string|array<string, string>|null
     */
    private static function cleanLabel(string|array|null $label): string|array|null
    {
        if (is_string($label)) {
            return trim($label) === '' ? null : $label;
        }
        if ($label === null) {
            return null;
        }
        $map = array_filter(self::texts($label), static fn (string $text): bool => trim($text) !== '');

        return $map === [] ? null : $map;
    }

    private static function targetWord(LinkKind $kind): string
    {
        return match ($kind) {
            LinkKind::Url => 'the web address',
            LinkKind::Email => 'the email address',
            LinkKind::Phone => 'the phone number',
            LinkKind::Asset => 'the asset id',
            LinkKind::Record => 'the record',
        };
    }
}
