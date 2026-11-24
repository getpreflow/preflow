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
        $options = ActionOptions::from(['tagret' => 'results', 'swap' => 'inner'], false, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertSame('self', $options->target);
        $this->assertSame('inner', $options->swap);
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

        $this->assertSame('outer', ActionOptions::from(['target' => 'parent'], true)->swap);
        $this->assertNull(ActionOptions::from(['target' => 'parent'], true)->region());
        $this->assertSame('outer', ActionOptions::from(['target' => 'results', 'swap' => 'outer'], true)->swap, 'an explicit swap wins');
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
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badValues(): iterable
    {
        yield 'target' => [['target' => '1bad']];
        yield 'swap' => [['swap' => 'outerHTML']];
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
