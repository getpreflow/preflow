<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Screen;

use Preflow\Folio\Extension\ExtensionException;
use Preflow\View\TemplateEngineInterface;

/**
 * What a screen method returns to be rendered in the admin: a template (extending
 * @folio/admin/_layout.twig) and its data. Folio adds prefix, csrf and screen (label, path).
 * A form panel may return one too: a fragment template, given prefix, csrf, type, id and record.
 */
final readonly class ScreenView
{
    /**
     * @param array<string, mixed> $data
     * @param list<string> $assets asset entries after the screen attribute's (see PageAssets)
     */
    public function __construct(
        public string $template,
        public array $data = [],
        public array $assets = [],
    ) {}

    /**
     * The template with Folio's variables and the view's data over them; a missing
     * template is an ExtensionException naming $owner (the screen or panel method).
     *
     * @param array<string, mixed> $defaults
     */
    public function render(TemplateEngineInterface $engine, array $defaults, string $owner): string
    {
        if (!$engine->exists($this->template)) {
            throw new ExtensionException("{$owner} renders \"{$this->template}\", which does not exist.");
        }

        return $engine->render($this->template, array_merge($defaults, $this->data));
    }
}
