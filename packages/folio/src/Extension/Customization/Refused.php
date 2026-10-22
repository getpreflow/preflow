<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Customization;

/** Thrown by a #[BeforeDelete] hook to cancel the delete; the message is shown to the editor. */
final class Refused extends \RuntimeException
{
}
