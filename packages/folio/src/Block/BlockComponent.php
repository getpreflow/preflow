<?php

declare(strict_types=1);

namespace Preflow\Folio\Block;

use Preflow\Components\Component;

/**
 * Base class for component blocks: the block's (localized) data arrives as
 * props and fills the #[BlockField] public properties, so a block needs no
 * boilerplate and can still add its own logic in resolveState().
 */
abstract class BlockComponent extends Component
{
    /** The block's stable id within its page. */
    public string $_uid = '';

    public function setProps(array $props): void
    {
        parent::setProps($props);

        if (isset($props['_uid']) && is_string($props['_uid'])) {
            $this->_uid = $props['_uid'];
        }

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(BlockField::class) === [] || !array_key_exists($property->getName(), $props)) {
                continue;
            }
            $value = $props[$property->getName()];
            $type = $property->getType();
            if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                $value = match ($type->getName()) {
                    'string' => is_scalar($value) ? (string) $value : '',
                    'array' => is_array($value) ? $value : [],
                    'int' => (int) $value,
                    'bool' => (bool) $value,
                    default => $value,
                };
            }
            $property->setValue($this, $value);
        }
    }
}
