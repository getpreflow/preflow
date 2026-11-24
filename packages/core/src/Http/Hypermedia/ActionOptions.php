<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Hypermedia;

/**
 * How an element runs a component action, in words no client library owns: where the answer goes
 * (`target`, `swap`), when the request leaves (`on`, `debounce`), what happens to a request still
 * running (`latest`), what else it sends (`include`), what it disables while it runs (`disable`),
 * where the page scrolls afterwards (`scroll`) and whose action it is (`component`). A driver that
 * drives component actions (DrivesComponentActions) turns them into its own attributes.
 *
 * One more key is let through untouched: a key named after a driver (`htmx`) holding a mapping of
 * that driver's own attributes, for what the neutral set does not cover. A template that uses it
 * is visibly tied to that driver; every other driver ignores the key.
 *
 *     ActionOptions::from(['target' => 'results', 'on' => 'input', 'debounce' => 300, 'latest' => true]);
 *
 * @since 0.86.0
 */
final class ActionOptions
{
    /** The neutral option names. */
    public const array KNOWN = ['target', 'swap', 'on', 'debounce', 'latest', 'include', 'disable', 'scroll', 'component'];

    /** Where the answer goes in, relative to the target. */
    public const array SWAPS = ['outer', 'inner', 'before', 'after', 'prepend', 'append', 'delete', 'none'];

    /** The events that send the request. `visible`: when the element scrolls into view. */
    public const array EVENTS = ['click', 'submit', 'change', 'input', 'load', 'visible'];

    /** A region name or a field name: a letter, then letters, digits, - and _ (as Preflow\View\Regions). */
    private const string NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]*$/';

    /** A driver's key for its own attributes (the escape hatch): a lower-case word. */
    private const string DRIVER_KEY_PATTERN = '/^[a-z][a-z0-9]*$/';

    /**
     * @param string $target `self`, `parent`, or the name of a region of the component
     * @param string $swap one of SWAPS
     * @param list<string> $on events of EVENTS; empty: the client's default for the element
     * @param int|null $debounce milliseconds after the last typing (`input`) event; other events send at once
     * @param bool $latest a newer request replaces one still running (instead of waiting its turn)
     * @param list<string> $include region or field names whose fields go with the request
     * @param string|null $disable `self`, or a region name, disabled while the request runs
     * @param string|null $scroll `top`: the top of the target is shown after the swap
     * @param string|null $component `parent`: the action of the component that rendered this one
     * @param array<string, array<string, mixed>> $drivers driver name => that driver's own attributes
     */
    private function __construct(
        public readonly string $target,
        public readonly string $swap,
        public readonly array $on,
        public readonly ?int $debounce,
        public readonly bool $latest,
        public readonly array $include,
        public readonly ?string $disable,
        public readonly ?string $scroll,
        public readonly ?string $component,
        private readonly array $drivers,
    ) {}

    /**
     * The options in $options, checked. A value of the wrong kind fails always. An unknown key
     * fails in $debug, naming the known ones; outside debug it is left out and $log gets a line
     * (error_log() without one), so a page keeps working.
     *
     * @param array<array-key, mixed> $options
     * @param (\Closure(string): void)|null $log
     * @throws \InvalidArgumentException a bad value; an unknown key in $debug
     */
    public static function from(array $options, bool $debug = true, ?\Closure $log = null): self
    {
        $drivers = [];
        $unknown = [];
        foreach ($options as $key => $value) {
            if (in_array($key, self::KNOWN, true)) {
                continue;
            }
            if (is_string($key) && preg_match(self::DRIVER_KEY_PATTERN, $key) === 1 && is_array($value)
                && ($value === [] || !array_is_list($value))) {
                $drivers[$key] = $value; // a driver's own attributes, passed on untouched
                continue;
            }
            $unknown[] = (string) $key;
        }
        if ($unknown !== []) {
            $message = sprintf(
                'Unknown action option%s %s; the known ones: %s (and a driver\'s own attributes under its name, e.g. htmx: {…}).',
                count($unknown) === 1 ? '' : 's',
                implode(', ', $unknown),
                implode(', ', self::KNOWN),
            );
            if ($debug) {
                throw new \InvalidArgumentException($message);
            }
            $log !== null ? $log($message . ' Left out.') : error_log($message . ' Left out.');
        }

        $target = $options['target'] ?? 'self';
        if (!is_string($target) || ($target !== 'self' && $target !== 'parent' && !self::isName($target))) {
            throw new \InvalidArgumentException(sprintf("The action option target is 'self', 'parent' or a region name, not %s.", self::shown($target)));
        }
        $swap = $options['swap'] ?? (self::isRegion($target) ? 'inner' : 'outer');
        if (!is_string($swap) || !in_array($swap, self::SWAPS, true)) {
            throw new \InvalidArgumentException(sprintf('The action option swap is one of %s, not %s.', implode(', ', self::SWAPS), self::shown($swap)));
        }
        $on = $options['on'] ?? [];
        $on = is_string($on) ? [$on] : $on;
        if (!is_array($on) || !array_is_list($on)
            || array_filter($on, static fn (mixed $event): bool => !is_string($event) || !in_array($event, self::EVENTS, true)) !== []
            || $on !== array_values(array_unique($on))) {
            throw new \InvalidArgumentException(sprintf('The action option on is one of %s, or a list of them, not %s.', implode(', ', self::EVENTS), self::shown($options['on'] ?? null)));
        }
        $debounce = $options['debounce'] ?? null;
        if ($debounce !== null && (!is_int($debounce) || $debounce < 0)) {
            throw new \InvalidArgumentException(sprintf('The action option debounce is a number of milliseconds (0 or more), not %s.', self::shown($debounce)));
        }
        $latest = $options['latest'] ?? false;
        if (!is_bool($latest)) {
            throw new \InvalidArgumentException(sprintf('The action option latest is true or false, not %s.', self::shown($latest)));
        }
        $include = $options['include'] ?? [];
        $include = is_string($include) ? [$include] : $include;
        if (!is_array($include) || !array_is_list($include) || array_filter($include, static fn (mixed $name): bool => !is_string($name) || !self::isName($name)) !== []) {
            throw new \InvalidArgumentException(sprintf('The action option include is a region or field name, or a list of them, not %s.', self::shown($options['include'] ?? null)));
        }
        $disable = $options['disable'] ?? null;
        if ($disable !== null && (!is_string($disable) || ($disable !== 'self' && !self::isName($disable)))) {
            throw new \InvalidArgumentException(sprintf("The action option disable is 'self' or a region name, not %s.", self::shown($disable)));
        }
        $scroll = $options['scroll'] ?? null;
        if ($scroll !== null && $scroll !== 'top') {
            throw new \InvalidArgumentException(sprintf("The action option scroll is 'top', not %s.", self::shown($scroll)));
        }
        $component = $options['component'] ?? null;
        if ($component !== null && $component !== 'parent') {
            throw new \InvalidArgumentException(sprintf("The action option component is 'parent', not %s.", self::shown($component)));
        }

        /** @var array<string, array<string, mixed>> $drivers */
        return new self($target, $swap, $on, $debounce, $latest, $include, $disable, $scroll, $component, $drivers);
    }

    /** The region the answer goes into, null for `self` and `parent`. */
    public function region(): ?string
    {
        return self::isRegion($this->target) ? $this->target : null;
    }

    /**
     * The attributes the template gave under $driver's name (the escape hatch), as given; empty
     * without any.
     *
     * @return array<string, mixed>
     */
    public function driverOptions(string $driver): array
    {
        return $this->drivers[$driver] ?? [];
    }

    /**
     * The driver names the options carry attributes for.
     *
     * @return list<string>
     */
    public function drivers(): array
    {
        return array_keys($this->drivers);
    }

    private static function isRegion(mixed $target): bool
    {
        return is_string($target) && $target !== 'self' && $target !== 'parent';
    }

    private static function isName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    private static function shown(mixed $value): string
    {
        return is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }
}
