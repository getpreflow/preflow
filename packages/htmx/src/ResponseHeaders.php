<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Core\Context\Contexts;

/**
 * The hypermedia hints a render asks its response to carry (HX-Trigger, HX-Redirect,
 * HX-Push-Url: HtmxDriver records them, applyEffects() sends them).
 *
 * Since 0.81 each render frame has its own (a frame slot): a hint recorded in one render never
 * reaches another render's response. Outside every render the headers are this object's own.
 */
final class ResponseHeaders
{
    /** @var array<string, string> */
    private array $headers = [];

    private bool $frameBound = true;

    public function set(string $name, string $value): void
    {
        $this->target()->headers[$name] = $value;
    }

    public function get(string $name): ?string
    {
        return $this->target()->headers[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->target()->headers;
    }

    public function clear(): void
    {
        $this->target()->headers = [];
    }

    /** The headers the calls work on: the current frame's, else this object's. */
    private function target(): self
    {
        if (!$this->frameBound || Contexts::depth() === 0) {
            return $this;
        }

        return Contexts::frame()->slot(self::class, static function (): self {
            $headers = new self();
            $headers->frameBound = false;

            return $headers;
        });
    }
}
