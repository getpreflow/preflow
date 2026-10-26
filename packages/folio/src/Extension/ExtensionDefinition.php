<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/** A discovered extension: its #[FolioExtension] values, class, folder and parts. */
final readonly class ExtensionDefinition
{
    /**
     * @param class-string $class
     * @param string       $folder the directory of the extension's Extension.php
     * @param list<array{id: string, kind: string, class?: string, label: string, opt_in?: true}> $parts (opt_in: a model the site switches on itself)
     * @param string|null  $translations the translation group of its models' labels (#[FolioExtension(translations: …)]); null: "site"
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $icon,
        public string $version,
        public string $class,
        public string $folder,
        public array $parts,
        public ?string $translations = null,
    ) {}

    /** The translation group of its models' labels (a definition cached before it had one: "site"). */
    public function labelGroup(): string
    {
        return $this->translations ?? 'site';
    }

    public function hasPart(string $id): bool
    {
        foreach ($this->parts as $part) {
            if ($part['id'] === $id) {
                return true;
            }
        }

        return false;
    }
}
