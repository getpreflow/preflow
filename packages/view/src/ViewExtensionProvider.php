<?php

declare(strict_types=1);

namespace Preflow\View;

use Preflow\View\Qr\QrCode;

/**
 * Template functions that belong to the view layer itself.
 */
final class ViewExtensionProvider implements TemplateExtensionProvider
{
    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'qr_svg',
                callable: fn (string $data, array $options = []): string => QrCode::svg($data, $options),
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }
}
