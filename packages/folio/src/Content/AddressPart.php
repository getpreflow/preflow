<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;
use Preflow\Validation\Message;
use Preflow\Validation\Rules\Slug;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * "slug" on a value of a route field as the address uses it: a language map (a translatable
 * field on a one-language site) is judged by its default-language value, like
 * FrontendResolver::pathFor() reads it.
 */
final class AddressPart implements ValidationRule
{
    private readonly Slug $slug;

    public function __construct(private readonly Locales $locales, bool $catchAll)
    {
        $this->slug = new Slug($catchAll ? 'path' : null);
    }

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        return $this->slug->validate(is_array($value) ? LocaleValue::default($value, $this->locales) : $value, $context);
    }
}
