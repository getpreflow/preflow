<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine\Support;

use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSource;
use Preflow\I18n\Machine\TranslationUnit;
use Preflow\I18n\Machine\WriteOutcome;
use Preflow\I18n\Machine\WriteOutcomeStatus;

/** In-memory source: items[id][path] = English text; translations[id][locale][path] = stored text. */
final class ArrayTextSource implements TextSource
{
    /** @var array<string, array<string, array<string,string>>> */
    public array $translations = [];

    /** @var list<string> */
    public array $writes = [];

    /** @var array<string, WriteOutcome> id => forced outcome */
    public array $outcomes = [];

    /** @var array<string, \Throwable> id => thrown by write() */
    public array $throws = [];

    /** @var array<string, \Throwable> id => thrown by units() (the record is gone) */
    public array $unitsThrow = [];

    /** What writesLive() answers. */
    public bool $live = false;

    /** Called on every write, before it is stored. */
    public ?\Closure $onWrite = null;

    /** @param array<string, array<string,string>> $items */
    public function __construct(public array $items) {}

    public function collection(): string
    {
        return 'games';
    }

    public function label(): string
    {
        return 'Games';
    }

    public function items(): array
    {
        return array_map('strval', array_keys($this->items));
    }

    public function title(string $id): string
    {
        return 'Item ' . $id;
    }

    public function units(string $id, string $to, Scope $scope): array
    {
        if (isset($this->unitsThrow[$id])) {
            throw $this->unitsThrow[$id];
        }
        $units = [];
        foreach ($this->items[$id] ?? [] as $path => $text) {
            if ($scope === Scope::Needed && isset($this->translations[$id][$to][$path])) {
                continue;
            }
            $units[] = new TranslationUnit(id: $path, label: $path, source: $text, to: $to, from: 'en');
        }

        return $units;
    }

    public function write(string $id, string $to, array $texts): WriteOutcome
    {
        $this->writes[] = $id . ':' . $to;
        if ($this->onWrite !== null) {
            ($this->onWrite)($id, $to);
        }
        if (isset($this->throws[$id])) {
            throw $this->throws[$id];
        }
        if (isset($this->outcomes[$id])) {
            return $this->outcomes[$id];
        }
        foreach ($texts as $path => $text) {
            $this->translations[$id][$to][$path] = $text;
        }

        return new WriteOutcome(WriteOutcomeStatus::Written);
    }

    public function writesLive(): bool
    {
        return $this->live;
    }
}
