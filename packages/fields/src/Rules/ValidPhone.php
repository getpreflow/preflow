<?php

declare(strict_types=1);

namespace Preflow\Fields\Rules;

use Preflow\Fields\PhoneOptions;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\Phone;
use Preflow\Validation\Message;
use Preflow\Validation\Rules\Phone as PhoneRule;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A phone field's value (the stored object {as_typed, e164}, JSON text of it, or the number as
 * text) is a phone number (Preflow\Validation\Rules\Phone) that can be normalized: it starts with "+"
 * or "00" and the country code, or it is national and the field has a default region, or it is a
 * short service number (Phone::problem()). A stored e164 must be the number as_typed normalizes
 * to (with the field's default region; an international as_typed needs none), so a "tel:" link
 * never dials a number the editor did not type: it is null for a service number. An empty value
 * passes ("required" decides).
 *
 * FieldsServiceProvider registers it for every "phone" field (FieldKinds::rules()).
 *
 * @since 0.91.0
 */
final class ValidPhone implements ValidationRule
{
    public function __construct(
        private readonly PhoneOptions $options = new PhoneOptions(),
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        $e164 = null;
        if (is_array($value) || (is_string($value) && str_starts_with(ltrim($value), '{'))) {
            $data = Link::decode($value);
            $typed = $data['as_typed'] ?? null;
            $e164 = $data['e164'] ?? null;
            if (!is_string($typed) || ($e164 !== null && (!is_string($e164) || preg_match('/^\+[0-9]{3,15}$/', $e164) !== 1))) {
                return new Message('validation.phone');
            }
        } else {
            $typed = $value;
        }
        if (!is_string($typed)) {
            return new Message('validation.phone');
        }
        $result = (new PhoneRule())->validate($typed, $context);
        if ($result !== true) {
            return $result;
        }
        $problem = Phone::problem($typed, $this->options->defaultRegion);
        if ($problem !== null) {
            return new Message($problem);
        }

        return $e164 === null || $e164 === Phone::e164($typed, $this->options->defaultRegion) ? true : new Message('validation.phone');
    }
}
