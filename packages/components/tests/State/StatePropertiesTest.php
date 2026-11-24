<?php

declare(strict_types=1);

namespace Preflow\Components\Tests\State;

use PHPUnit\Framework\TestCase;
use Preflow\Components\State;
use Preflow\Components\StateProperties;
use Preflow\Components\StateRefused;
use Preflow\Components\StateValue;

enum Sort: string
{
    case New = 'new';
    case Old = 'old';
}

final class Filters implements StateValue
{
    public function __construct(public readonly string $city = '', public readonly int $min = 0) {}

    public function toState(): array
    {
        return ['city' => $this->city, 'min' => $this->min];
    }

    public static function fromState(array $state): static
    {
        return new static($state['city'], $state['min'] ?? throw new \InvalidArgumentException('min is missing'));
    }
}

final class Stateful
{
    #[State] public string $q = '';
    #[State] public int $page = 1;
    #[State] public ?bool $open = null;
    #[State] public array $tags = [];
    #[State] public Sort $sort = Sort::New;
    #[State] public ?Filters $filters = null;
    public string $notState = 'x';
}

final class Unsupported
{
    #[State] public \DateTimeImmutable $when;
}

final class UnionState
{
    #[State] public string|Sort $s = 'pizza';
}

final class NullableState
{
    #[State] public ?Sort $s = null;
    #[State] public ?Filters $f = null;
}

final class ArrayHolder
{
    #[State] public array $list = [];
}

final class HoldsObject
{
    #[State] public mixed $thing = null;
}

final class StatePropertiesTest extends TestCase
{
    public function test_reads_and_writes_scalars_arrays_enums_and_state_values(): void
    {
        $c = new Stateful();
        $c->q = 'pizza';
        $c->page = 2;
        $c->open = true;
        $c->tags = ['a', ['b' => 1]];
        $c->sort = Sort::Old;
        $c->filters = new Filters('Bern', 3);

        $this->assertSame(['q', 'page', 'open', 'tags', 'sort', 'filters'], StateProperties::of($c));

        $plain = StateProperties::read($c);
        $this->assertSame([
            'q' => 'pizza', 'page' => 2, 'open' => true, 'tags' => ['a', ['b' => 1]],
            'sort' => 'old', 'filters' => ['city' => 'Bern', 'min' => 3],
        ], $plain);

        $fresh = new Stateful();
        StateProperties::write($fresh, $plain);

        $this->assertSame('pizza', $fresh->q);
        $this->assertSame(Sort::Old, $fresh->sort);
        $this->assertEquals(new Filters('Bern', 3), $fresh->filters);
        $this->assertSame($plain, StateProperties::read($fresh));
    }

    public function test_unsupported_state_type_names_class_and_property(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Unsupported::class);
        try {
            StateProperties::of(new Unsupported());
        } catch (\LogicException $e) {
            $this->assertStringContainsString('$when', $e->getMessage());
            throw $e;
        }
    }

    public function test_a_value_that_cannot_be_kept_names_class_and_property(): void
    {
        $c = new HoldsObject();
        $c->thing = new \stdClass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('$thing');
        StateProperties::read($c);
    }

    public function test_state_of_an_older_shape_is_refused(): void
    {
        $this->expectException(StateRefused::class);
        StateProperties::write(new Stateful(), ['filters' => ['city' => 'Bern']]);
    }

    public function test_state_of_a_renamed_property_is_refused(): void
    {
        $this->expectException(StateRefused::class);
        $this->expectExceptionMessage('$renamed');
        StateProperties::write(new Stateful(), ['renamed' => 'x']);
    }

    public function test_an_unknown_enum_case_is_refused(): void
    {
        $this->expectException(StateRefused::class);
        StateProperties::write(new Stateful(), ['sort' => 'gone']);
    }

    public function test_a_union_of_a_class_type_with_another_type_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(UnionState::class);
        $this->expectExceptionMessage('$s');
        StateProperties::of(new UnionState());
    }

    public function test_nullable_class_types_stay_allowed(): void
    {
        $this->assertSame(['s', 'f'], StateProperties::of(new NullableState()));
    }

    public function test_an_enum_inside_an_array_is_refused(): void
    {
        $c = new ArrayHolder();
        $c->list = [Sort::New];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(ArrayHolder::class . '::$list');
        StateProperties::read($c);
    }
}
