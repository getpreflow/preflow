<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Preflow\View\Regions;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\Template;

/**
 * The {% region %} tag (RegionTokenParser) and the region element it prints.
 *
 * @since 0.86.0
 */
final class RegionExtension extends AbstractExtension
{
    /** The prefix of the block a region compiles to. */
    public const string BLOCK_PREFIX = '__region_';

    /** The context variable holding the context a template with regions was rendered with. */
    public const string CONTEXT = '__preflow_region_context';

    public function getTokenParsers(): array
    {
        return [new RegionTokenParser()];
    }

    public function getNodeVisitors(): array
    {
        return [new RegionBlocks()];
    }

    /** The block the region $name compiles to (its name hex-encoded: any region name is a block name). */
    public static function blockName(string $name): string
    {
        return self::BLOCK_PREFIX . bin2hex($name);
    }

    /** The region name of the block $block, null for a block that is no region's. */
    public static function regionName(string $block): ?string
    {
        if (!str_starts_with($block, self::BLOCK_PREFIX)) {
            return null;
        }
        $name = hex2bin(substr($block, strlen(self::BLOCK_PREFIX)));

        return is_string($name) && Regions::validName($name) ? $name : null;
    }

    /**
     * The opening tag of the region $name (Regions::open()).
     *
     * @param array<string, mixed> $context
     * @internal compiled templates
     */
    public function open(Template $template, array $context, string $name, mixed $attributes): string
    {
        try {
            return Regions::open($context, $name, $attributes);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeError($e->getMessage(), -1, $template->getSourceContext(), $e);
        }
    }
}
