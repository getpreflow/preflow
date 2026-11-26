<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Fields\Values\LinkKind;

/**
 * A link field's options, under "link" in its config:
 *
 *     "cta": {"type": "link", "link": {"kinds": ["record", "url"], "types": ["page", "post"]}}
 *
 * - "kinds": the kinds the field takes (default: every kind: record, url, email, phone, asset);
 * - "types": the record types a record link may point to (default: any type). Folio's editor
 *   offers these types to pick a record from; without them it offers every type the editor may see.
 *
 * check() reports a mistake, fromConfig() reads what it can (a site's boot runs check()).
 *
 * @since 0.91.0
 */
final readonly class LinkOptions
{
    /**
     * @param list<LinkKind> $kinds
     * @param list<string>|null $types null: any type
     */
    public function __construct(
        public array $kinds,
        public ?array $types = null,
    ) {}

    /** @param array<string, mixed> $config the field's config */
    public static function fromConfig(array $config): self
    {
        $link = is_array($config['link'] ?? null) ? $config['link'] : [];
        $kinds = [];
        foreach (is_array($link['kinds'] ?? null) ? $link['kinds'] : [] as $name) {
            $kind = is_string($name) ? LinkKind::tryFrom($name) : null;
            if ($kind !== null && !in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }
        $types = null;
        if (is_array($link['types'] ?? null)) {
            $types = array_values(array_unique(array_filter($link['types'], static fn (mixed $t): bool => is_string($t) && $t !== '')));
        }

        return new self($kinds === [] ? LinkKind::cases() : $kinds, $types);
    }

    public function allows(LinkKind $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    public function allowsType(string $type): bool
    {
        return $this->types === null || in_array($type, $this->types, true);
    }

    /** @return list<string> the kinds' stored names */
    public function kindNames(): array
    {
        return array_map(static fn (LinkKind $kind): string => $kind->value, $this->kinds);
    }

    /**
     * Refuses a link field config it cannot use, naming the key and what to write instead.
     *
     * @param array<string, mixed> $config the field's config (its "translatable" too)
     * @throws \LogicException
     */
    public static function check(array $config): void
    {
        if (($config['translatable'] ?? false) === true) {
            throw new \LogicException('A link field cannot be translatable; its label is translated in the link itself (a text per language). Remove "translatable".');
        }
        if (!array_key_exists('link', $config)) {
            return;
        }
        $link = $config['link'];
        if (!is_array($link) || ($link !== [] && array_is_list($link))) {
            throw new \LogicException('"link" must be an object such as {"types": ["page"]} (the options "kinds" and "types").');
        }
        foreach (array_keys($link) as $key) {
            if (!in_array($key, ['kinds', 'types'], true)) {
                throw new \LogicException("\"link\" takes \"kinds\" and \"types\"; \"{$key}\" is not one.");
            }
        }
        if (array_key_exists('kinds', $link)) {
            if (!is_array($link['kinds']) || !array_is_list($link['kinds'])) {
                throw new \LogicException('"link.kinds" must be a list such as ["record", "url"].');
            }
            if ($link['kinds'] === []) {
                throw new \LogicException('"link.kinds" lists no kind; leave it out to offer every kind.');
            }
            foreach ($link['kinds'] as $name) {
                if (!is_string($name) || LinkKind::tryFrom($name) === null) {
                    throw new \LogicException('"link.kinds" lists ' . json_encode($name) . '; use record, url, email, phone or asset.');
                }
            }
        }
        if (array_key_exists('types', $link)) {
            $types = $link['types'];
            if (!is_array($types) || !array_is_list($types) || array_filter($types, static fn (mixed $t): bool => !is_string($t) || $t === '') !== []) {
                throw new \LogicException('"link.types" must be a list of type keys, e.g. ["page"].');
            }
            if ($types === []) {
                throw new \LogicException('"link.types" lists no type; name the types a record link may point to, or leave it out for any type.');
            }
        }
    }
}
