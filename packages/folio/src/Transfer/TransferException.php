<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

/** A content archive that is unsafe, corrupt or does not fit this app. */
final class TransferException extends \RuntimeException {}
