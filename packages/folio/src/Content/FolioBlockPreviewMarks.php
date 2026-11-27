<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Blocks\BlockDefinition;
use Preflow\Blocks\BlockPreviewMarks;
use Preflow\Folio\FolioText;
use Preflow\I18n\Translator;

/**
 * The preview editor finds a block by data-folio-block and names it by data-folio-label, in the
 * language Folio's texts have in the render (as a linked record's, FolioRecordPreviewMarks).
 *
 * A label that is a translation key of another package's group (preflow/block-library's
 * "block-library.columns.label", a site's own) is translated by the app's translator when Folio's
 * texts lack it (since 0.92.1): the preview renders as a visitor, where Folio's own texts know
 * only Folio's groups.
 */
final class FolioBlockPreviewMarks implements BlockPreviewMarks
{
    /** @param (\Closure(): ?Translator)|null $translator the app's translator, resolved on use */
    public function __construct(private readonly ?\Closure $translator = null) {}

    public function attributes(BlockDefinition $definition, array $block): array
    {
        return [
            'data-folio-block' => (string) ($block['_uid'] ?? ''),
            'data-folio-label' => $this->label($definition->label),
        ];
    }

    private function label(string $label): string
    {
        $text = FolioText::label($label);
        $translator = $text === $label && $this->translator !== null ? ($this->translator)() : null;
        if ($translator === null || preg_match('/^[a-z][a-z0-9_-]*(\.[a-z0-9_]+)+$/', $label) !== 1) {
            return $text;
        }

        return $translator->get($label, [], FolioText::locale());
    }
}
