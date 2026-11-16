<?php

declare(strict_types=1);

namespace Preflow\Ai\Limits;

/**
 * AI limits are on but a setting they need is missing (today: the app key, APP_KEY / app.key, that
 * each person's id is hashed with). It is a site's setup, not a person's limit: an HTTP caller
 * answers it as 503 with the message (it names the setting, never its value), and a background
 * worker stops the work for good instead of retrying it on every run.
 *
 * @since 0.78
 */
final class AiLimitsMisconfigured extends \RuntimeException
{
    public function __construct(
        /** The setting that is missing, as an operator names it ("APP_KEY"). */
        public readonly string $setting,
        string $message,
    ) {
        parent::__construct($message);
    }
}
