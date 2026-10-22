<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A POST button on the type's edit form, at {prefix}/{type}/{id}/_action/{name}, for those
 * with the permission (and the type's edit permission). With toolbar: false the button is not in the form header (the
 * action stays routed and permission-checked), for actions posted from a FormPanel's own form. The method is
 * fn(DynamicRecord $record, ServerRequestInterface $request): ?ResponseInterface;
 * null redirects back to the form with "{label}: done".
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RecordAction
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $permission,
        public readonly ?string $icon = null,
        public readonly ?string $confirm = null,
        public readonly bool $toolbar = true,
    ) {}
}
