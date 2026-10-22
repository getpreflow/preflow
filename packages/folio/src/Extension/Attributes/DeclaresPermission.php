<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Attributes;

/**
 * A permission an extension declares, e.g. #[DeclaresPermission('members.export', 'Export members')]
 * on its Extension class (repeatable). The name must start with "{extension name}."; the
 * group is the extension's label. While the extension is not enabled it is denied, never a 500.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class DeclaresPermission
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
    ) {}
}
