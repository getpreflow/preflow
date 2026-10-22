<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Form\FieldRenderer;

/**
 * A reference to an environment variable ("env": "NAME"), for secrets in settings. The form
 * shows the name and whether it is set, never the value; nothing is read from the request or
 * stored (DraftRecordFactory writes no key). ExtensionSettings::get() reads getenv(NAME).
 */
final class EnvFieldType implements FieldType
{
    public function key(): string
    {
        return 'env';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = is_string($ctx->config['env'] ?? null) ? $ctx->config['env'] : '';
        $set = $name !== '' && (getenv($name) ?: null) !== null;

        $html = '<div class="form-group">' . "\n"
            . '  <span class="form-label">' . $e($ctx->label ?? (new FieldRenderer())->humanize($ctx->name)) . '</span>' . "\n"
            . '  <p><code>' . $e($name) . '</code> <span class="folio-badge">' . ($set ? 'set' : 'not set') . '</span></p>' . "\n";
        if (($ctx->help ?? '') !== '') {
            $html .= '  <small class="form-help">' . $e((string) $ctx->help) . '</small>' . "\n";
        }

        return $html . '</div>';
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return null; // a posted value is never taken
    }

    public function toStorage(mixed $value): mixed
    {
        return null;
    }

    public function fromStorage(mixed $value): mixed
    {
        return null;
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        return '';
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return [];
    }
}
