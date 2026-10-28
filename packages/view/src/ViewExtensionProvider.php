<?php

declare(strict_types=1);

namespace Preflow\View;

use Preflow\View\Pdf\Blocks;
use Preflow\View\Qr\QrCode;
use Psr\Container\ContainerInterface;

/**
 * Template functions that belong to the view layer itself: QR codes and the
 * PDF building blocks.
 */
final class ViewExtensionProvider implements TemplateExtensionProvider
{
    /**
     * @param ?ContainerInterface $container lets pdf_image() look up media asset ids when preflow/media is installed
     */
    public function __construct(private readonly ?ContainerInterface $container = null)
    {
    }

    public function getTemplateFunctions(): array
    {
        $pdf = new Blocks($this->container);

        return [
            new TemplateFunctionDefinition(
                name: 'qr_svg',
                callable: fn (string $data, array $options = []): string => QrCode::svg($data, $options),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(name: 'pdf_page_number', callable: $pdf->pageNumber(...), isSafe: true),
            new TemplateFunctionDefinition(name: 'pdf_page_count', callable: $pdf->pageCount(...), isSafe: true),
            new TemplateFunctionDefinition(name: 'pdf_page_break', callable: $pdf->pageBreak(...), isSafe: true),
            new TemplateFunctionDefinition(name: 'pdf_image', callable: $pdf->image(...), isSafe: true),
            new TemplateFunctionDefinition(name: 'pdf_table', callable: $pdf->table(...), isSafe: true),
            new TemplateFunctionDefinition(name: 'pdf_qr', callable: $pdf->qr(...), isSafe: true),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }
}
