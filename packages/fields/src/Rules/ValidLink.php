<?php

declare(strict_types=1);

namespace Preflow\Fields\Rules;

use Preflow\Fields\LinkOptions;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\LinkKind;
use Preflow\Validation\Message;
use Preflow\Validation\Rules\Email;
use Preflow\Validation\Rules\Phone;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A link field's value (Link's stored shape, or JSON text of it) is a link of a kind the field
 * offers, with a target that is valid for its kind: a record of an offered type, an http(s)
 * address or a path on the site, an email address, a phone number, an asset id. An empty value
 * passes ("required" decides). Each refusal says what to choose or type instead.
 *
 * FieldsServiceProvider registers it for every "link" field (FieldKinds::rules()); Folio's link
 * input returns it from rules().
 *
 * @since 0.91.0
 */
final class ValidLink implements ValidationRule
{
    public function __construct(
        private readonly LinkOptions $options,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        $data = Link::decode($value);
        $kind = is_string($data['kind'] ?? null) ? LinkKind::tryFrom($data['kind']) : null;
        if ($kind === null || !$this->options->allows($kind)) {
            return new Message('validation.link.kind', ['kinds' => implode(', ', $this->options->kindNames())]);
        }
        $link = Link::fromStored($data);
        if ($link === null) {
            return new Message('validation.link.target');
        }
        $target = $link->target;
        if (is_array($target)) {
            return $this->options->allowsType($target['type'])
                ? true
                : new Message('validation.link.type', ['refused' => $target['type'], 'types' => implode(', ', $this->options->types ?? [])]);
        }

        return match ($kind) {
            LinkKind::Url => Link::isSiteOrWebAddress($target) ? true : new Message('validation.link.url'),
            LinkKind::Email => (new Email())->validate($target, $context),
            LinkKind::Phone => (new Phone())->validate($target, $context),
            LinkKind::Asset, LinkKind::Record => true,
        };
    }
}
