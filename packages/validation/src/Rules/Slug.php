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

    /** Letters that become two (or other) letters rather than losing their accent. */
    private const LETTERS = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'đ' => 'd', 'ł' => 'l', 'þ' => 'th'];

    /**
     * Text made into a valid part ("Über uns" → "ueber-uns"); with $path, single slashes between
     * parts are kept. Accents are dropped ("é" → "e"); what has no a-z form (other scripts,
     * punctuation) becomes a hyphen between words, so the result may be "". Folio's admin.js
     * does the same in the editor.
     */
    public static function clean(string $text, bool $path = false): string
    {
        $text = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($text, \Normalizer::FORM_C) : $text;
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::LETTERS);
        if (class_exists(\Normalizer::class)) {
            $text = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D));
        }
        $word = static fn (string $part): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', $part), '-');
        if (!$path) {
            return $word($text);
        }

        return implode('/', array_filter(array_map($word, explode('/', $text)), static fn (string $part): bool => $part !== ''));
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
