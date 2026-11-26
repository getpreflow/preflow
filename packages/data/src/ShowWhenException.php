<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A field's show_when that cannot work: a malformed rule, or one naming an unknown field, the
 * field itself, a translatable field or a field that depends on it (a cycle). The message names
 * the type (or block) and the field and says what to write instead.
 *
 * @since 0.91.0
 */
final class ShowWhenException extends \InvalidArgumentException {}
