<?php

declare(strict_types=1);

namespace Preflow\Folio\View;

/**
 * Hands a fragment's form controls to a form they are not inside: every input, select, textarea,
 * button, fieldset and output without a form attribute gets form="<id>". The editor's side panel sits
 * outside the record's <form> (extension panels carry forms of their own, and forms do not nest), so
 * the record fields it shows (address, "panel": "side") name the form this way and are posted,
 * autosaved and previewed with it: native HTML, no copying of values in script.
 *
 * The fragment is parsed as HTML (Dom\HTMLDocument), so only real attributes count: a value or a
 * placeholder that reads "form=…", or a ">" inside a quoted value, change nothing. Left alone: a
 * control that names a form already, and <button type="button"> (it submits nothing). A <button>
 * without a type is a submit button, so it becomes a submit of the record form. Controls inside a
 * <template> are not touched; script that clones them into the side panel gets the form from
 * admin.js (the side fieldset's observer), like any control added later. Every <textarea> gets the
 * guard newline after its start tag back, so a value that starts with a newline round-trips.
 *
 * @since 0.75
 */
final class FormOwner
{
    private const string ROOT = 'folio-form-owner-root';

    public static function assign(string $html, string $formId): string
    {
        if (preg_match('/^[A-Za-z][\w-]*$/', $formId) !== 1) {
            throw new \InvalidArgumentException("A form id of letters, digits, \"-\" and \"_\" is expected, got \"{$formId}\"");
        }
        if (trim($html) === '') {
            return $html;
        }

        $doc = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body><div id="' . self::ROOT . '">' . $html . '</div></body></html>',
            LIBXML_NOERROR,
            'UTF-8',
        );
        $root = $doc->getElementById(self::ROOT);
        if ($root === null) {
            return $html;
        }
        foreach ($root->querySelectorAll('input, select, textarea, button, fieldset, output') as $control) {
            if ($control->hasAttribute('form')) {
                continue;
            }
            if ($control->localName === 'button' && strtolower(trim((string) $control->getAttribute('type'))) === 'button') {
                continue;
            }
            $control->setAttribute('form', $formId);
        }
        // The parse dropped the newline right after each <textarea> (FieldRenderer's guard) and the
        // serialiser does not write one: written again, a value that starts with a newline keeps it.
        foreach ($root->querySelectorAll('textarea') as $textarea) {
            $textarea->insertBefore($doc->createTextNode("\n"), $textarea->firstChild);
        }

        return $root->innerHTML;
    }
}
