<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Fields\Values\Phone;

/**
 * A phone field's option, top-level in its config:
 *
 *     "phone": {"type": "phone", "default_region": "CH"}
 *
 * "default_region" is the country (a code of Phone::REGIONS: CH, DE, AT, …) whose calling code a
 * number typed without "+" gets in its E.164 form. Without it every number must start with "+" (or
 * "00") and the country code, apart from short service numbers such as 112.
 *
 * @since 0.91.0
 */
final readonly class PhoneOptions
{
    public function __construct(
        public ?string $defaultRegion = null,
    ) {}

    /** @param array<string, mixed> $config the field's config */
    public static function fromConfig(array $config): self
    {
        $region = $config['default_region'] ?? null;

        return new self(is_string($region) && isset(Phone::REGIONS[strtoupper($region)]) ? strtoupper($region) : null);
    }

    /**
     * @param array<string, mixed> $config the field's config (its "translatable" too)
     * @throws \LogicException
     */
    public static function check(array $config): void
    {
        FieldOptions::refuseTranslatable('phone', $config);
        if (!array_key_exists('default_region', $config)) {
            return;
        }
        $region = $config['default_region'];
        if (!is_string($region) || !isset(Phone::REGIONS[strtoupper($region)])) {
            throw new \LogicException('"default_region" is ' . json_encode($region) . '; use one of ' . implode(', ', array_keys(Phone::REGIONS)) . ', or leave it out and have numbers typed with + and the country code.');
        }
    }
}
