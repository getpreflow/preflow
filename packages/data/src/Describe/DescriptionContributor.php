<?php

declare(strict_types=1);

namespace Preflow\Data\Describe;

use Preflow\Data\TypeDefinition;

/**
 * A package's part of the description (Description): top-level sections of its own and keys it
 * adds to a type's entry (the blocks package: "blocks", "layouts", "area_layout_sets" and each
 * type's "layouts"). Registered in the package's service provider on DescriptionContributors.
 *
 * Its output is part of the description's public format: plain JSON data (no class names, no
 * template paths), an empty map as an object (\stdClass). A section or type key named like one of
 * the description's own is refused (\LogicException). A contributor that also implements
 * DescribesShapes gives the shapes its sections name, for the description's "values"; it lists
 * only the shapes it uses.
 *
 * @since 0.93.0
 */
interface DescriptionContributor
{
    /** @return array<string, mixed> top-level sections */
    public function sections(): array;

    /** @return array<string, mixed> keys added to this type's entry (none: []) */
    public function forType(TypeDefinition $def): array;
}
