<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;

/**
 * `aria-hidden.focusable` (warning): elements hidden from screen readers
 * (aria-hidden="true") that are, or contain, something keyboard users can focus
 * (a link, a button, a form field, tabindex ≥ 0). Content under `inert`, disabled
 * fields and tabindex="-1" are fine. Once per page, with the count.
 *
 * @since 0.54
 */
final class AriaHiddenFocusable implements PageCheck
{
    public function __construct(private readonly AuditRegistry $registry) {}

    public function id(): string
    {
        return 'aria-hidden.focusable';
    }

    public function check(RenderedPage $page): array
    {
        $dom = $page->dom();
        if ($dom === null) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $focusable = [];
        foreach ($xpath->query('//*[@aria-hidden]') ?: [] as $hidden) {
            if (!$hidden instanceof \DOMElement || strtolower(trim($hidden->getAttribute('aria-hidden'))) !== 'true'
                || $xpath->query('ancestor-or-self::*[@inert]', $hidden)?->length) {
                continue;
            }
            foreach ($xpath->query('descendant-or-self::*', $hidden) ?: [] as $element) {
                if ($element instanceof \DOMElement && self::focusable($element)) {
                    $focusable[(string) $element->getNodePath()] = true;
                }
            }
        }
        if ($focusable === []) {
            return [];
        }

        $count = count($focusable);
        $what = $count === 1 ? '1 element' : "{$count} elements";

        return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Warning,
            "{$what} inside aria-hidden=\"true\" can still be focused, so keyboard users land on something screen readers don't announce; remove aria-hidden or take them out of the tab order.")];
    }

    private static function focusable(\DOMElement $element): bool
    {
        if ($element->hasAttribute('tabindex')) {
            return (int) $element->getAttribute('tabindex') >= 0;
        }
        $editable = strtolower(trim($element->getAttribute('contenteditable')));
        if ($element->hasAttribute('contenteditable') && $editable !== 'false') {
            return true;
        }

        return match (strtolower($element->tagName)) {
            'a', 'area' => $element->hasAttribute('href'),
            'button', 'select', 'textarea' => !$element->hasAttribute('disabled'),
            'input' => !$element->hasAttribute('disabled') && strtolower($element->getAttribute('type')) !== 'hidden',
            'iframe', 'summary' => true,
            'audio', 'video' => $element->hasAttribute('controls'),
            default => false,
        };
    }
}
