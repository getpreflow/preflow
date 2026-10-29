# preflow/validation

Rules for validating arrays and records. A rule is written as a string alias with parameters
(`min:3`, `between:2,4`) or as a `ValidationRule` instance.

## Built-in rules

`required`, `nullable`, `email`, `url`, `numeric`, `integer`, `min:n`, `max:n`, `between:min,max`,
`step:n`, `in:a,b`, `regex:/pattern/`, `confirmed`.

- `step:n` (since 0.46): the value must be a whole multiple of `n` counted from 0 (`step:0.5` accepts
  `2.5`, refuses `2.3`). The check uses the decimal digits of the value and the step, not floats, so
  `0.3` is a multiple of `0.1` and large values stay exact; without bcmath a value too long for an
  integer is refused. Empty and non-numeric values pass; `required` and `numeric` judge those. A step
  of 0 or below throws an `InvalidArgumentException`. The message is `validation.step` with `:step`.

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
