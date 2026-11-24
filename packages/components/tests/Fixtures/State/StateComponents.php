<?php

declare(strict_types=1);

namespace Preflow\Components\Tests\Fixtures\State;

use Preflow\Components\Component;
use Preflow\Components\Freshness;
use Preflow\Components\Live;
use Preflow\Components\SessionStateStore;
use Preflow\Components\State;
use Preflow\Components\StateIn;
use Preflow\Core\PageCache\Freshness as Dial;

/** Writes each hook it runs into one shared log. */
final class Log
{
    /** @var list<string> */
    public static array $calls = [];
}

/** Starts its count from the "start" prop; prepare() derives a label from the state. */
class Counter extends Component
{
    #[State] public int $count = 0;

    public string $label = '';

    public function mount(): void
    {
        Log::$calls[] = static::class . ':mount';
        $this->count = (int) ($this->props['start'] ?? 0);
    }

    public function prepare(): void
    {
        Log::$calls[] = static::class . ':prepare';
        $this->label = 'count ' . $this->count;
    }

    public function actions(): array
    {
        return ['add'];
    }

    public function actionAdd(): void
    {
        $this->count++;
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'Counter';
    }
}

/** A component without state: resolveState() before every render, as in 0.85. */
final class PlainResolver extends Component
{
    public string $label = '';

    public function resolveState(): void
    {
        Log::$calls[] = 'PlainResolver:resolveState';
        $this->label = 'plain';
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'Plain';
    }
}

/** State and resolveState(): the old hook on a component with state. */
final class StatefulResolver extends Component
{
    #[State] public int $count = 0;

    public function resolveState(): void
    {
        Log::$calls[] = 'StatefulResolver:resolveState';
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'Counter';
    }
}

/** Keeps rows in its state; its template renders a Counter child (the test engine does). */
final class RowsForm extends Component
{
    /** @var list<string> */
    #[State] public array $rows = [];

    public function mount(): void
    {
        Log::$calls[] = 'RowsForm:mount';
        $this->rows = ['first'];
    }

    public function prepare(): void
    {
        Log::$calls[] = 'RowsForm:prepare';
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'RowsForm';
    }
}

#[Live]
final class LiveCounter extends Counter
{
}

#[Freshness(Dial::Timed, seconds: 600)]
final class FreshCounter extends Counter
{
}

#[StateIn(SessionStateStore::class)]
final class SessionCounter extends Counter
{
}
