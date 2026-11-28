<?php

declare(strict_types=1);

namespace Preflow\Blocks\Preview;

/**
 * A type's "preview" that cannot work: its shape (TypePreview::fromSchema()) or a component that
 * does not exist (TypePreviews::assertTypes(), the boot check). The message starts with
 * `Type "<type>": ` and says what to change.
 *
 * @since 0.94.0
 */
final class PreviewConfigException extends \RuntimeException
{
}
