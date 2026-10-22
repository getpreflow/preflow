<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * The extension versions installed in this environment (folio:extensions:apply), one record
 * per extension in the default storage. Folio's own table, not a content type: never in the
 * TypeCatalog, so never exported or imported.
 */
final class ExtensionState
{
    public const string TABLE = 'folio_extension_state';

    private ?TypeDefinition $definition = null;

    public function __construct(
        private readonly DataManager $dm,
    ) {}

    public function definition(): TypeDefinition
    {
        return $this->definition ??= new TypeDefinition(
            key: '_extension_state',
            table: self::TABLE,
            storage: 'default',
            fields: [
                'version' => new TypeFieldDefinition('version', 'string'),
                'installed_at' => new TypeFieldDefinition('installed_at', 'string'),
            ],
            idField: 'name',
        );
    }

    public function installedVersion(string $name): ?string
    {
        $version = $this->dm->findIn($this->definition(), $name)?->get('version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Every discovered extension with its installed version and the state of its parts
     * (folio:extensions:list and the Extensions screen).
     *
     * @return list<array{name: string, label: string, description: string, version: string, enabled: bool, installed: ?string, parts: list<array{id: string, kind: string, label: string, enabled: bool}>}>
     */
    public function overview(ExtensionRegistry $extensions): array
    {
        $rows = [];
        foreach ($extensions->all() as $definition) {
            $rows[] = [
                'name' => $definition->name,
                'label' => $definition->label,
                'description' => $definition->description,
                'version' => $definition->version,
                'enabled' => $extensions->isEnabled($definition->name),
                'installed' => $this->installedVersion($definition->name),
                'parts' => array_map(static fn (array $part): array => [
                    'id' => $part['id'],
                    'kind' => $part['kind'],
                    'label' => $part['label'],
                    'enabled' => $extensions->partEnabled($definition->name, $part['id']),
                ], $definition->parts),
            ];
        }

        return $rows;
    }

    public function markInstalled(string $name, string $version): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->definition(), [
            'name' => $name,
            'version' => $version,
            'installed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]), false);
    }
}
