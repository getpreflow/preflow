<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * A pluggable content field type: it renders its own admin editor, normalizes
 * and (de)serializes its value, renders safe frontend output, and declares any
 * validation rules and admin assets it needs.
 */
interface FieldType
{
    /** Stable key used in model JSON ("type": "...") and the registry. */
    public function key(): string;

    /** Admin-form HTML for this field. */
    public function renderEditor(FieldContext $ctx): string;

    /**
     * Raw POST value -> domain value (sanitize/parse here).
     *
     * @param array<string, mixed> $config
     */
    public function normalizeInput(mixed $raw, array $config): mixed;

    /** Domain value -> storage-ready value (JSON string for structured types). */
    public function toStorage(mixed $value): mixed;

    /** Storage value -> domain value. */
    public function fromStorage(mixed $value): mixed;

    /**
     * Safe HTML for the public frontend.
     *
     * @param array<string, mixed> $config
     */
    public function renderFrontend(mixed $value, array $config): string;

    /**
     * Validation rules this field type contributes, given its config.
     *
     * @param array<string, mixed> $config
     * @return array<string, list<string>>
     */
    public function rules(array $config): array;

    /**
     * Admin asset handles (keys into the asset route) this editor needs.
     *
     * @return list<string>
     */
    public function assets(): array;
}
