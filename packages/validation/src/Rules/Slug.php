<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A part of a web address: lowercase a-z and digits, single hyphens between them ("ueber-uns").
 * No umlauts, capitals, spaces or other characters. "slug:path" also allows single slashes
 * between parts ("docs/ueber-uns"). Empty values pass; "required" judges those.
 */
final class Slug implements ValidationRule
{
    private const WORD = '[a-z0-9]+(?:-[a-z0-9]+)*';

    private readonly bool $path;

    public function __construct(?string $mode = null)
    {
        if ($mode !== null && $mode !== 'path') {
            throw new \InvalidArgumentException(sprintf('slug: unknown mode "%s" (use "slug" or "slug:path").', $mode));
        }
        $this->path = $mode === 'path';
    }

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '') {
            return true;
        }
        $pattern = $this->path ? '#^' . self::WORD . '(?:/' . self::WORD . ')*$#D' : '#^' . self::WORD . '$#D';
        if (is_scalar($value) && !is_bool($value) && preg_match($pattern, (string) $value) === 1) {
            return true;
        }

        return new Message($this->path ? 'validation.slug.path' : 'validation.slug.segment');
    }
}
