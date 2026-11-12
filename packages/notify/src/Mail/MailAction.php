<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

/** A mail's primary link: the layout shows it as a button, with the URL in small text below. */
final readonly class MailAction
{
    public function __construct(
        public string $label,
        public string $url,
    ) {}
}
