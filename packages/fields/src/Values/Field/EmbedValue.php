<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Data\Values\ListsReferences;
use Preflow\Fields\EmbedProviders;
use Preflow\Fields\Values\Embed;
use Preflow\Fields\Values\Link;

/**
 * An "embed" field's value, shape "embed": the object {provider, id, url, poster}.
 *
 * toStored(): the object, JSON text of it, or a video's address as plain text (recognised by the
 * app's video hosts), as Embed::toStored() writes it; no value is null; what no host recognises
 * is passed on (an object as its object) for the rule. toOutput(): the object, null when none is
 * stored or its address, provider and id disagree. references(): the poster as an "asset".
 *
 * @since 0.93.0
 */
final class EmbedValue implements FieldValue, DescribesShapes, ListsReferences
{
    /** @param (\Closure(): EmbedProviders)|null $providers the app's video hosts, asked on use (default: the built-in ones) */
    public function __construct(private readonly ?\Closure $providers = null) {}

    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }
        if (is_string($input) && !str_starts_with(ltrim($input), '{')) {
            return Embed::fromStored($input, $this->providers())?->toStored() ?? trim($input);
        }

        return Embed::fromStored($input, $this->providers())?->toStored() ?? Link::decode($input) ?? $input;
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?array
    {
        return Embed::fromStored($stored, $this->providers())?->toStored();
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'embed';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('embed');
    }

    public function references(mixed $stored, TypeFieldDefinition $field): array
    {
        $poster = $this->toOutput($stored, $field)['poster'] ?? null;

        return is_string($poster) && $poster !== '' ? ['asset' => [$poster]] : [];
    }

    private function providers(): EmbedProviders
    {
        return $this->providers !== null ? ($this->providers)() : EmbedProviders::lazyBuiltIn()();
    }
}
