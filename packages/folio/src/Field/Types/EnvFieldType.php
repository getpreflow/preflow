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
final class EnvFieldType implements FieldType, \Preflow\Folio\Field\HasColumnKind
{
    public function key(): string
    {
        return 'env';
    }

    public function columnKind(array $config): \Preflow\Data\Schema\ColumnKind
    {
        return \Preflow\Data\Schema\ColumnKind::Short;
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = is_string($ctx->config['env'] ?? null) ? $ctx->config['env'] : '';
        $set = $name !== '' && (getenv($name) ?: null) !== null;

        // Board LibFields: key icon, the variable's name in mono, "set" (accent dot) or "not set"
        // (warning ring). Only whether it is set leaves the server, never the value.
        $html = '<div class="form-group folio-env-field">' . "\n"
            . '  <span class="form-label">' . $e($ctx->label ?? (new FieldRenderer())->humanize($ctx->name)) . '</span>' . "\n"
            . '  <div class="folio-env">' . \Preflow\Folio\View\InlineIcon::svg('ui-key') . '<code class="folio-env-name">' . $e($name) . '</code>'
            . '<span class="folio-env-state ' . ($set ? 'is-set' : 'is-missing') . '"><span class="folio-env-dot" aria-hidden="true"></span>'
            . $e(\Preflow\Folio\FolioText::get($set ? 'folio.fields.env_set' : 'folio.fields.env_not_set')) . '</span></div>' . "\n";
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
