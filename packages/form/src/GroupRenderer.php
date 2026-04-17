<?php

declare(strict_types=1);

namespace Preflow\Form;

final class GroupRenderer
{
    /** @param array<string, mixed> $options */
    public function render(string $content, array $options = []): string
    {
        $label = $options['label'] ?? null;
        $class = $options['class'] ?? null;

        $wrapperClass = 'form-group-wrapper' . ($class !== null ? ' ' . $class : '');

        $html = '<div class="' . htmlspecialchars($wrapperClass, ENT_QUOTES, 'UTF-8') . '">';

        if ($label !== null) {
            $html .= '<div class="form-group-label">'
                   . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                   . '</div>';
        }

        $html .= '<div class="form-group-fields">' . $content . '</div>';
        $html .= '</div>';

        return $html;
    }
}
