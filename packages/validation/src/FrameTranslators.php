<?php

declare(strict_types=1);

namespace Preflow\Validation;

use Preflow\I18n\Translator;

/**
 * The translators the deprecated ValidatorFactory::useTranslator() set in one render frame, per
 * factory (a frame slot): they end with the frame. Removed with useTranslator() in 0.83.
 *
 * @internal ValidatorFactory
 * @since 0.81.0
 */
final class FrameTranslators
{
    /** @var \WeakMap<ValidatorFactory, array{0: ?Translator}> */
    public \WeakMap $set;

    public function __construct()
    {
        $this->set = new \WeakMap();
    }
}
