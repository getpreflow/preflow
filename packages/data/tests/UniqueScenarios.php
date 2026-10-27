<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Validation\ValidationException;

/**
 * Unique fields through DataManager, the same on every storage: a duplicate is a field error,
 * from validation and from the storage's own guard (the index, or the check under the lock).
 * The using test sets $this->dm with a driver named storage().
 */
trait UniqueScenarios
{
    protected DataManager $dm;

    abstract protected function storage(): string;

    protected function page(): TypeDefinition
    {
        return $this->synced(new TypeDefinition('page', 'page', $this->storage(), [
            'slug' => new TypeFieldDefinition('slug', unique: true),
            'title' => new TypeFieldDefinition('title'),
        ]));
    }

    protected function code(): TypeDefinition
    {
        return $this->synced(new TypeDefinition('code', 'code', $this->storage(), [
            'code' => new TypeFieldDefinition('code', unique: ['purpose']),
            'purpose' => new TypeFieldDefinition('purpose'),
        ]));
    }

    protected function doc(): TypeDefinition
    {
        return $this->synced(new TypeDefinition('doc', 'doc', $this->storage(), [
            'slug' => new TypeFieldDefinition('slug', translatable: true, unique: true),
            'title' => new TypeFieldDefinition('title'),
        ], locales: new Locales(['de', 'en'], 'de')));
    }

    private function synced(TypeDefinition $def): TypeDefinition
    {
        (new RecordTableSync($this->dm))->sync($def);

        return $def;
    }

    /** @param array<string, mixed> $data */
    protected function save(TypeDefinition $def, array $data, bool $validate = true): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($def, $data), $validate);
    }

    /**
     * The field errors the save failed with ([] when it saved).
     *
     * @param array<string, mixed> $data
     * @return array<string, list<string>>
     */
    protected function errorsOf(TypeDefinition $def, array $data, bool $validate = true): array
    {
        try {
            $this->save($def, $data, $validate);

            return [];
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    protected function stored(TypeDefinition $def, string $id, string $field): mixed
    {
        return $this->dm->findIn($def, $id)?->get($field);
    }

    public function testDuplicateIsAFieldError(): void
    {
        $page = $this->page();
        $this->save($page, ['uuid' => 'a', 'slug' => 'x']);

        $this->assertSame(['slug' => ['This value is already taken.']], $this->errorsOf($page, ['uuid' => 'b', 'slug' => 'x']));
        $this->assertNull($this->dm->findIn($page, 'b'));
    }

    public function testOwnValueIsNotADuplicate(): void
    {
        $page = $this->page();
        $this->save($page, ['uuid' => 'a', 'slug' => 'x', 'title' => 'A']);

        $this->assertSame([], $this->errorsOf($page, ['uuid' => 'a', 'slug' => 'x', 'title' => 'A2']));
        $this->assertSame([], $this->errorsOf($page, ['uuid' => 'a', 'slug' => 'x', 'title' => 'A3'], validate: false));
        $this->assertSame('A3', $this->stored($page, 'a', 'title'));
    }

    public function testEmptyIsNeverDuplicate(): void
    {
        $page = $this->page();
        foreach (['a' => '', 'b' => '', 'c' => null, 'd' => null] as $id => $slug) {
            $this->assertSame([], $this->errorsOf($page, ['uuid' => $id, 'slug' => $slug]), "record {$id}");
        }
        $this->assertSame([], $this->errorsOf($page, ['uuid' => 'e', 'slug' => ''], validate: false));
        $this->assertCount(5, $this->dm->queryIn($page)->get()->items());
    }

    public function testScopedUnique(): void
    {
        $code = $this->code();
        $this->assertSame([], $this->errorsOf($code, ['uuid' => 'a', 'code' => 'ABC', 'purpose' => 'p1']));
        $this->assertSame([], $this->errorsOf($code, ['uuid' => 'b', 'code' => 'ABC', 'purpose' => 'p2']));

        $this->assertSame(['code' => ['This value is already taken.']], $this->errorsOf($code, ['uuid' => 'c', 'code' => 'ABC', 'purpose' => 'p1']));
        $this->assertSame(['code' => ['This value is already taken.']], $this->errorsOf($code, ['uuid' => 'c', 'code' => 'ABC', 'purpose' => 'p1'], validate: false));
        $this->assertNull($this->dm->findIn($code, 'c'));
    }

    public function testScopeFieldIsLengthLimited(): void
    {
        $errors = $this->errorsOf($this->code(), ['uuid' => 'a', 'code' => 'ABC', 'purpose' => str_repeat('p', 192)]);

        $this->assertSame(['purpose'], array_keys($errors));
    }

    /** What the storage would refuse, for a save that skips the rules (a draft): bounded lengths and unique fields. */
    public function testValidateStorable(): void
    {
        $page = $this->page();
        $code = $this->code();
        $this->save($page, ['uuid' => 'a', 'slug' => 'x']);
        $storable = function (TypeDefinition $def, array $data): array {
            try {
                $this->dm->validateStorable(DynamicRecord::fromArray($def, $data));

                return [];
            } catch (ValidationException $e) {
                return $e->errors();
            }
        };

        $this->assertSame(['slug' => ['Must be at most 191 characters.']], $storable($page, ['uuid' => 'b', 'slug' => str_repeat('s', 192)]));
        $this->assertSame(['purpose' => ['Must be at most 191 characters.']], $storable($code, ['uuid' => 'c', 'code' => 'ABC', 'purpose' => str_repeat('p', 192)]));
        $this->assertSame(['slug' => ['This value is already taken.']], $storable($page, ['uuid' => 'b', 'slug' => 'x']));
        $this->assertSame([], $storable($page, ['uuid' => 'b', 'slug' => str_repeat('s', 191), 'title' => str_repeat('t', 500)]), 'text fields are unbounded');
        $this->assertSame([], $storable($page, ['uuid' => 'b', 'slug' => '2024']), 'a number-like value is measured as text');
        $this->assertSame([], $storable($page, ['uuid' => 'b', 'slug' => null]));
        $this->assertNull($this->dm->findIn($page, 'b'), 'a check writes nothing');
    }

    public function testRaceIsAFieldErrorAndTouchesNothing(): void
    {
        $page = $this->page();
        $this->save($page, ['uuid' => 'a', 'slug' => 'x', 'title' => 'A']);
        $this->save($page, ['uuid' => 'b', 'slug' => 'y', 'title' => 'B']);

        // Unvalidated, as a save that passed validation before the other record took the value.
        $this->assertSame(['slug' => ['This value is already taken.']], $this->errorsOf($page, ['uuid' => 'b', 'slug' => 'x', 'title' => 'B2'], validate: false));
        $this->assertSame(['slug' => ['This value is already taken.']], $this->errorsOf($page, ['uuid' => 'c', 'slug' => 'x', 'title' => 'C'], validate: false));

        $this->assertSame(['x', 'A'], [$this->stored($page, 'a', 'slug'), $this->stored($page, 'a', 'title')]);
        $this->assertSame(['y', 'B'], [$this->stored($page, 'b', 'slug'), $this->stored($page, 'b', 'title')]);
        $this->assertNull($this->dm->findIn($page, 'c'));
    }

    public function testTranslatableUniquePerLocale(): void
    {
        $doc = $this->doc();
        $this->save($doc, ['uuid' => 'a', 'slug' => ['de' => 'spiel', 'en' => 'game']]);

        $taken = ['slug.en' => ['This value is already taken.']];
        $this->assertSame($taken, $this->errorsOf($doc, ['uuid' => 'b', 'slug' => ['de' => 'spiel2', 'en' => 'game']]));
        $this->assertSame($taken, $this->errorsOf($doc, ['uuid' => 'b', 'slug' => ['de' => 'spiel2', 'en' => 'game']], validate: false), 'checked in the save too');
        $this->assertSame(['slug' => ['This value is already taken.']], $this->errorsOf($doc, ['uuid' => 'b', 'slug' => ['de' => 'spiel', 'en' => 'other']], validate: false));
        $this->assertNull($this->dm->findIn($doc, 'b'));

        // The same text in another locale, an empty locale and the record's own values are no duplicates.
        $this->assertSame([], $this->errorsOf($doc, ['uuid' => 'b', 'slug' => ['de' => 'game', 'en' => 'spiel']]));
        $this->assertSame([], $this->errorsOf($doc, ['uuid' => 'c', 'slug' => ['de' => 'drei', 'en' => '']]));
        $this->assertSame([], $this->errorsOf($doc, ['uuid' => 'd', 'slug' => ['de' => 'vier', 'en' => '']]));
        $this->assertSame([], $this->errorsOf($doc, ['uuid' => 'a', 'slug' => ['de' => 'spiel', 'en' => 'game'], 'title' => 'T']));
    }
}
