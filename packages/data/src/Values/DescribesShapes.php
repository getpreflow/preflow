<?php

declare(strict_types=1);

namespace Preflow\Data\Values;

/**
 * Optional for a FieldValue: what each output shape it gives is, for the description's top-level
 * "values" (each shape listed once). A DescriptionContributor may implement it too, for the
 * shapes its sections name.
 *
 * @since 0.93.0
 */
interface DescribesShapes
{
    /** @return array<string, string> shape => one-sentence description */
    public function shapes(): array;
}
