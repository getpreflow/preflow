# preflow/validation

Rules for validating arrays and records. A rule is written as a string alias with parameters
(`min:3`, `between:2,4`) or as a `ValidationRule` instance.

## Built-in rules

`required`, `nullable`, `email`, `url`, `numeric`, `integer`, `min:n`, `max:n`, `between:min,max`,
`step:n`, `in:a,b`, `regex:/pattern/`, `confirmed`, `slug`, `slug:path`.

- `step:n` (since 0.46): the value must be a whole multiple of `n` counted from 0 (`step:0.5` accepts
  `2.5`, refuses `2.3`). The check uses the decimal digits of the value and the step, not floats, so
  `0.3` is a multiple of `0.1` and large values stay exact; without bcmath a value too long for an
  integer is refused. Empty and non-numeric values pass; `required` and `numeric` judge those. A step
  of 0 or below throws an `InvalidArgumentException`. The message is `validation.step` with `:step`.
- `slug` (since 0.52): a part of a web address, lowercase `a-z` and digits with single hyphens between
  them (`ueber-uns`). Umlauts, capitals, spaces, underscores and leading, trailing or double hyphens
  are refused. `slug:path` also allows single slashes between parts (`docs/ueber-uns`). Empty values
  pass. The messages are `validation.slug.segment` and `validation.slug.path`.
  `Slug::clean($text, path: false)` makes text into a valid value (`Über uns` → `ueber-uns`, `é` → `e`;
  with `path: true` single slashes stay); text with no a-z form gives `''`. Accents are dropped with
  `ext-intl`'s `Normalizer`; without it only the letters ä, ö, ü, ß, æ, œ, ø, đ, ł and þ are converted.

## Rules for a filled value only

`new WhenFilled(...$rules)` (since 0.46) runs the wrapped `ValidationRule` instances only when the value
is filled: `null` and `''` pass, anything else answers with the first failing rule. Unlike `nullable`
it does not end the chain, so a `required` next to it still refuses an empty value:

```php
use Preflow\Validation\Rules\{Numeric, Step, WhenFilled};

$rules = ['price' => [new WhenFilled(new Numeric(), new Step(0.5))]];             // empty is fine
$rules = ['price' => ['required', new WhenFilled(new Numeric(), new Step(0.5))]]; // empty: "required"
```

`numeric` alone keeps refusing `''`.

`WhenFilled` is used as an object only: it has no string alias, and a rule string naming its class would
build it without rules, so it would check nothing.

## In an app

When `preflow/validation` is installed, the application binds `RuleFactory` and `ValidatorFactory`
in the container, and registers the rules in `app/Rules/` (classes `App\Rules\*` with
`#[RuleAlias('name')]`). Since 0.66 this needs no database: it works without `config/data.php`
too, for forms that are not models. With `config/data.php`, models validate on save through the
same factory.

```php
$result = $container->get(ValidatorFactory::class)->make(['to' => ['required', 'not_below:from']], $input)->validate();
```

A package registers its own rule names with `RuleFactory::register('name', RuleClass::class)` in
its provider.
