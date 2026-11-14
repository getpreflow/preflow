<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\FolioText;
use Preflow\Folio\View\InlineIcon;

/**
 * A one-line text. A route parameter of the record's address ($ctx->address) shows the static path
 * before it as a mono prefix ("/jobs/") and a copy button (the whole address; fields.js); a web
 * address ("format": "url", or a "url" rule) gets a button that opens it in a new tab (board LibFields).
 */
final class StringFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\SearchableText, \Preflow\Folio\Field\HasColumnKind
{
    public function key(): string
    {
        return 'string';
    }

    public function columnKind(array $config): \Preflow\Data\Schema\ColumnKind
    {
        return \Preflow\Data\Schema\ColumnKind::Short;
    }

    protected function inputType(): string
    {
        return 'text';
    }

    /** "monospace": true renders a code-style input, as for text (a key, an identifier; editor only). @since 0.76 */
    protected function editorAttrs(FieldContext $ctx): array
    {
        return ($ctx->config['monospace'] ?? false) === true ? ['class' => 'folio-input-mono'] : [];
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $address = $ctx->address;
        $url = $address === null && $ctx->isUrl();
        if ($address === null && !$url) {
            return parent::renderEditor($ctx);
        }

        $e = self::escaper();
        $value = (string) ($ctx->value ?? '');
        $options = [
            'type' => 'text',
            'value' => $value,
            'help' => $ctx->help ?? '',
            'required' => $ctx->required,
            'errors' => $ctx->errors,
            'attrs' => $ctx->placeholder !== null ? ['placeholder' => $ctx->placeholder] : [],
        ];
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }

        if ($address !== null) {
            $copy = '';
            if ($address->before !== null && $address->after !== null) {
                $copyText = FolioText::get('folio.fields.copy_address');
                $copy = '<button type="button" class="folio-input-action" data-folio-copy-address'
                    . ' data-address-before="' . $e($address->before) . '" data-address-after="' . $e($address->after) . '"'
                    . ($address->home !== null && $address->homeAddress !== null ? ' data-address-home="' . $e($address->home) . '" data-address-root="' . $e($address->homeAddress) . '"' : '')
                    . ' data-text-copied="' . $e(FolioText::get('folio.fields.copied')) . '"'
                    . ' aria-label="' . $e($copyText) . '" title="' . $e($copyText) . '">' . InlineIcon::svg('ui-copy') . '</button>';
            }
            $control = static fn (string $input): string => '<div class="folio-input-affix" data-folio-affix>'
                . '<span class="folio-input-prefix" aria-hidden="true">' . $e($address->prefix) . '</span>' . $input . $copy . '</div>';

            return $this->renderDecorated($ctx->name, $options, $control, '', 'folio-affix-field');
        }

        $open = FolioText::get('folio.fields.open_address');
        $href = self::webAddress($value);
        $link = '<a class="folio-input-action"' . ($href !== null ? ' href="' . $e($href) . '"' : '') . ' target="_blank" rel="noopener noreferrer" data-folio-open-url'
            . ' aria-label="' . $e($open) . '" title="' . $e($open) . '"' . ($href === null ? ' hidden' : '') . '>' . InlineIcon::svg('ui-external') . '</a>';
        $control = static fn (string $input): string => '<div class="folio-input-affix" data-folio-affix>' . $input . $link . '</div>';

        return $this->renderDecorated($ctx->name, $options, $control, '', 'folio-affix-field');
    }

    /** The value when it is an http(s) address the open button may link to; null otherwise. */
    private static function webAddress(string $value): ?string
    {
        $value = trim($value);

        return preg_match('~^https?://[^\s/?#]+[^\s]*$~i', $value) === 1 ? $value : null;
    }
}
