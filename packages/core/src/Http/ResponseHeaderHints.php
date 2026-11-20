<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Preflow\Core\Context\Contexts;
use Psr\Http\Message\ResponseInterface;

/**
 * Response headers a page or component adds while it renders, for example
 * `Cache-Control: no-store` on a form's outcome page. The kernel puts them on the response the
 * route gives (and on its error page), inside the middleware pipeline: the page cache treats a
 * `no-store` (or `private`) from here like any such response and never stores the page.
 *
 * Each render frame has its own (a frame slot): the one the container gives, ofCurrentFrame(),
 * works on the current frame's at each call, so a header a render adds never reaches another
 * render's response. A component reaches it through the container; a template through
 * `response_header(name, value)`. Hints made with `new` are a plain list of their own.
 *
 * @since 0.81.0
 */
final class ResponseHeaderHints
{
    /** @var array<string, array{string, string}> lower-case name => [name as given, value] */
    private array $headers = [];

    private bool $frameBound = false;

    /** The hints that follow the render frames: each call works on the current frame's own. */
    public static function ofCurrentFrame(): self
    {
        $hints = new self();
        $hints->frameBound = true;

        return $hints;
    }

    /**
     * The response carries $name: $value, in place of a value the route gave it (a later call
     * for the same name wins).
     *
     * @throws \InvalidArgumentException when the name or the value could split the header
     */
    public function set(string $name, string $value): void
    {
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
            throw new \InvalidArgumentException("Not a response header: {$name}.");
        }
        $this->target()->headers[strtolower($name)] = [$name, $value];
    }

    /** The value added for $name (any case), null when none was. */
    public function get(string $name): ?string
    {
        return $this->target()->headers[strtolower($name)][1] ?? null;
    }

    /** @return array<string, string> name => value, in the order they were first added */
    public function all(): array
    {
        $all = [];
        foreach ($this->target()->headers as [$name, $value]) {
            $all[$name] = $value;
        }

        return $all;
    }

    /** $response with every added header (unchanged when none was added). */
    public function applyTo(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->target()->headers as [$name, $value]) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** The hints the calls work on: the current frame's for frame-bound hints, else these. */
    private function target(): self
    {
        if (!$this->frameBound || Contexts::depth() === 0) {
            return $this;
        }

        return Contexts::frame()->slot(self::class, static fn (): self => new self());
    }
}
