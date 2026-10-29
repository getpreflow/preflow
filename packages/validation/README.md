# preflow/validation

Rules for validating arrays and records. A rule is written as a string alias with parameters
(`min:3`, `between:2,4`) or as a `ValidationRule` instance.

## Built-in rules

`required`, `nullable`, `email`, `url`, `numeric`, `integer`, `min:n`, `max:n`, `between:min,max`,
`step:n`, `in:a,b`, `regex:/pattern/`, `confirmed`.

- `step:n` (since 0.46): the value must be a whole multiple of `n` counted from 0 (`step:0.5` accepts
  `2.5`, refuses `2.3`). Float error is tolerated (`0.3` is a multiple of `0.1`). Empty and non-numeric
  values pass; `required` and `numeric` judge those. A step of 0 or below throws an
  `InvalidArgumentException`. The message is `validation.step` with `:step`.
