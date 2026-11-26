<?php

declare(strict_types=1);

namespace Preflow\Fields;

/**
 * An embed field's options, top-level in its config:
 *
 *     "video": {"type": "embed"}
 *     "clip":  {"type": "embed", "providers": ["peertube"]}
 *
 * - "providers": the hosts the site added (EmbedProviders::add()) this field also offers, by
 *   name. YouTube and Vimeo are always offered; an address of any other host is refused.
 *
 * @since 0.91.0
 */
final readonly class EmbedOptions
{
    /** @param list<string> $providers the added providers the field offers */
    public function __construct(
        public array $providers = [],
    ) {}

    /** @param array<string, mixed> $config the field's config */
    public static function fromConfig(array $config): self
    {
        $listed = is_array($config['providers'] ?? null) ? $config['providers'] : [];

        return new self(array_values(array_unique(array_filter(
            $listed,
            static fn (mixed $name): bool => is_string($name) && $name !== '' && !in_array($name, EmbedProviders::BUILT_IN, true),
        ))));
    }

    /**
     * @param array<string, mixed> $config the field's config (its "translatable" too)
     * @throws \LogicException
     */
    public static function check(array $config): void
    {
        FieldOptions::refuseTranslatable('embed', $config);
        FieldOptions::refuseNested('embed', $config, ['providers']);
        if (!array_key_exists('providers', $config)) {
            return;
        }
        $providers = $config['providers'];
        if (!is_array($providers) || !array_is_list($providers) || $providers === []) {
            throw new \LogicException('"providers" must be a list of provider names such as ["peertube"] (YouTube and Vimeo are always offered).');
        }
        foreach ($providers as $name) {
            if (!is_string($name) || preg_match('/^[a-z0-9_-]+$/', $name) !== 1) {
                throw new \LogicException('"providers" lists ' . json_encode($name, JSON_UNESCAPED_UNICODE) . '; a provider name is lowercase letters, digits, - and _.');
            }
            if (in_array($name, EmbedProviders::BUILT_IN, true)) {
                throw new \LogicException("\"providers\" lists \"{$name}\", which is always offered; list only the providers your site adds.");
            }
        }
    }
}
