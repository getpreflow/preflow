<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Hypermedia\ActionCall;
use Preflow\Core\Http\Hypermedia\ActionOptions;

/** The neutral action options (core), checked once for every driver. */
final class ActionOptionsTest extends TestCase
{
    public function test_unknown_option_lists_known_ones(): void
    {
        try {
            ActionOptions::from(['tagret' => 'results'], true);
            $this->fail('an unknown option passed in debug');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tagret', $e->getMessage());
            foreach (ActionOptions::KNOWN as $known) {
                $this->assertStringContainsString($known, $e->getMessage());
            }
        }

        // Outside debug: left out, with a log line.
        $lines = [];
        $options = ActionOptions::from(['tagret' => 'results', 'swap' => 'none'], false, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertSame('self', $options->target);
        $this->assertSame('none', $options->swap);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('tagret', $lines[0]);
    }

    public function test_region_target_defaults_to_inner(): void
    {
        $region = ActionOptions::from(['target' => 'results'], true);
        $this->assertSame('inner', $region->swap, 'a region swaps its contents');
        $this->assertSame('results', $region->region());

        $self = ActionOptions::from([], true);
        $this->assertSame('self', $self->target);
        $this->assertSame('outer', $self->swap);
        $this->assertNull($self->region());

        $this->assertSame('append', ActionOptions::from(['target' => 'results', 'swap' => 'append'], true)->swap, 'an explicit swap wins');
        $this->assertSame('delete', ActionOptions::from(['swap' => 'delete'], true)->swap);
    }

    /** @return iterable<string, array{array<string, string>, string, list<string>}> */
    public static function unfittingSwaps(): iterable
    {
        // The answer to target self is the whole component: only outer, none, delete fit.
        foreach (['inner', 'append', 'prepend'] as $swap) {
            yield "self $swap" => [['swap' => $swap], 'outer', ActionOptions::SELF_SWAPS];
        }
        // The answer to a region is its contents: only inner, append, prepend, none fit.
        foreach (['outer', 'delete'] as $swap) {
            yield "region $swap" => [['target' => 'results', 'swap' => $swap], 'inner', ActionOptions::REGION_SWAPS];
        }
    }

    /**
     * @param array<string, string> $options
     * @param list<string> $allowed
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unfittingSwaps')]
    public function test_a_swap_that_does_not_fit_the_target_is_refused(array $options, string $default, array $allowed): void
    {
        try {
            ActionOptions::from($options, true);
            $this->fail('an unfitting swap passed in debug');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("swap '{$options['swap']}'", $e->getMessage());
            $this->assertStringContainsString("target '" . ($options['target'] ?? 'self') . "'", $e->getMessage());
            $this->assertStringContainsString(implode(', ', $allowed), $e->getMessage());
        }

        // Outside debug: a log line, and the target's default swap.
        $lines = [];
        $fallback = ActionOptions::from($options, false, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertSame($default, $fallback->swap);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString("swap '{$options['swap']}'", $lines[0]);
    }

    public function test_every_fitting_swap_passes(): void
    {
        foreach (ActionOptions::SELF_SWAPS as $swap) {
            $this->assertSame($swap, ActionOptions::from(['swap' => $swap], true)->swap);
        }
        foreach (ActionOptions::REGION_SWAPS as $swap) {
            $this->assertSame($swap, ActionOptions::from(['target' => 'results', 'swap' => $swap], true)->swap);
        }
        $this->assertSame([], array_diff(ActionOptions::SWAPS, ActionOptions::SELF_SWAPS, ActionOptions::REGION_SWAPS), 'no swap fits no target');
    }

    public function test_every_option_reads_back(): void
    {
        $options = ActionOptions::from([
            'target' => 'results', 'swap' => 'append', 'on' => ['input', 'change'], 'debounce' => 250,
            'latest' => true, 'include' => 'filters', 'disable' => 'self', 'scroll' => 'top', 'component' => 'parent',
            'htmx' => ['hx-confirm' => 'Sure?'],
        ], true);

        $this->assertSame(['input', 'change'], $options->on);
        $this->assertSame(250, $options->debounce);
        $this->assertTrue($options->latest);
        $this->assertSame(['filters'], $options->include);
        $this->assertSame('self', $options->disable);
        $this->assertSame('top', $options->scroll);
        $this->assertSame('parent', $options->component);
        $this->assertSame(['hx-confirm' => 'Sure?'], $options->driverOptions('htmx'));
        $this->assertSame([], $options->driverOptions('datastar'), 'another driver sees nothing');
        $this->assertSame(['htmx'], $options->drivers());
        $this->assertSame(['click'], ActionOptions::from(['on' => 'click'], true)->on);
        $this->assertSame(['blur'], ActionOptions::from(['on' => 'blur'], true)->on, 'blur: the field loses focus');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badValues(): iterable
    {
        yield 'target' => [['target' => '1bad']];
        yield 'target parent' => [['target' => 'parent']]; // the parent's action is component: 'parent'
        yield 'swap' => [['swap' => 'outerHTML']];
        yield 'swap before' => [['swap' => 'before']]; // inserting beside the component or a region breaks it
        yield 'on' => [['on' => 'keyup']];
        yield 'on list' => [['on' => [['click']]]];
        yield 'debounce' => [['debounce' => '300ms']];
        yield 'latest' => [['latest' => 'yes']];
        yield 'include' => [['include' => '#form']];
        yield 'disable' => [['disable' => '.button']];
        yield 'scroll' => [['scroll' => 'bottom']];
        yield 'component' => [['component' => 'App\\Other']];
    }

    /** @param array<string, mixed> $options */
    #[\PHPUnit\Framework\Attributes\DataProvider('badValues')]
    public function test_a_bad_value_fails_also_outside_debug(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage((string) array_key_first($options));
        ActionOptions::from($options, false, static function (): void {});
    }

    public function test_debounce_without_typing_fails_in_debug(): void
    {
        try {
            ActionOptions::from(['on' => 'click', 'debounce' => 300], true);
            $this->fail('a debounce on clicks passed in debug');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('typing only', $e->getMessage());
        }

        $lines = [];
        $options = ActionOptions::from(['on' => 'click', 'debounce' => 300], false, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertNull($options->debounce, 'left out in production');
        $this->assertCount(1, $lines);
        $this->assertSame(300, ActionOptions::from(['on' => ['input', 'change'], 'debounce' => 300], true)->debounce);

        try {
            ActionOptions::from(['on' => 'blur', 'debounce' => 300], true);
            $this->fail('a debounce on blur passed in debug');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('The action option debounce delays typing only: add input to on (blur).', $e->getMessage());
        }
    }

    public function test_another_drivers_key_is_kept_and_a_plain_unknown_key_is_not(): void
    {
        $options = ActionOptions::from(['datastar' => ['data-on' => 'x']], true);
        $this->assertSame(['datastar'], $options->drivers(), 'a mapping under an unknown key: some driver\'s own attributes');

        $this->expectException(\InvalidArgumentException::class);
        ActionOptions::from(['datastar' => 'x'], true);
    }

    public function test_an_action_call_is_get_or_post(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ActionCall('delete', '/x', 'c', 'c', ActionOptions::from([], true));
    }
}
