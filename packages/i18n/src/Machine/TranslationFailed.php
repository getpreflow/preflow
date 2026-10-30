<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** A single on-the-fly translation produced no usable text (blocked, refused, markup changed …). */
final class TranslationFailed extends \RuntimeException {}
