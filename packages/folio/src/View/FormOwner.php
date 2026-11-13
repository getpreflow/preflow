<?php

declare(strict_types=1);

namespace Preflow\Folio\View;

/**
 * Hands a fragment's form controls to a form they are not inside: every input, select, textarea,
 * button, fieldset and output start tag without a form attribute gets form="<id>". The editor's
 * side panel sits outside the record's <form> (extension panels carry forms of their own, and forms
 * do not nest), so the record fields it shows (address, "panel": "side") name the form this way and
 * are posted, autosaved and previewed with it: native HTML, no copying of values in script.
 *
 * The fragment is markup Folio's field types rendered: a tag inside a comment or a <template> gets the
 * attribute too (a template's clone then belongs to the same form, which is what a field wants).
 * Text and attribute values are escaped by the field types, so "<input" never appears inside them.
 */
final class FormOwner
{
    public static function assign(string $html, string $formId): string
    {
        if (preg_match('/^[A-Za-z][\w-]*$/', $formId) !== 1) {
            throw new \InvalidArgumentException("A form id of letters, digits, \"-\" and \"_\" is expected, got \"{$formId}\"");
        }

        return (string) preg_replace_callback(
            '/<(input|select|textarea|button|fieldset|output)\b([^>]*)>/i',
            static fn (array $m): string => preg_match('/\sform\s*=/i', $m[2]) === 1
                ? $m[0]
                : '<' . $m[1] . ' form="' . $formId . '"' . $m[2] . '>',
            $html,
        );
    }
}
