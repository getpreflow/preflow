<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Blocks\RecordPreviewMarks;

/**
 * The preview editor opens a linked record (a template's preview_mark(type, record)) by
 * data-folio-record (its type) and data-folio-record-id, and names it by data-folio-label: the
 * type's label for one record, in the language Folio's texts have in the render (as a block's).
 *
 * @since 0.92.0
 */
final class FolioRecordPreviewMarks implements RecordPreviewMarks
{
    /** @param (\Closure(): ?\Preflow\I18n\Translator)|null $translator the app's translator, resolved on use */
    public function __construct(
        private readonly DisplayLabels $labels,
        private readonly ?\Closure $translator = null,
    ) {}

    public function attributes(string $type, string $id): array
    {
        // The admin's language of the preview being rendered (since 0.94.0); none: the label as the render has it.
        $language = \Preflow\Core\Context\Contexts::peek()?->get(PreviewLabelLanguage::class)?->locale;
        $translator = $language !== null && $this->translator !== null ? ($this->translator)() : null;
        return [
            'data-folio-record' => $type,
            'data-folio-record-id' => $id,
            'data-folio-label' => $translator !== null ? $this->labels->oneIn($type, $language, $translator) : $this->labels->one($type),
        ];
    }
}
