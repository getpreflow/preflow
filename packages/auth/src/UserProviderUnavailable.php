<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * Thrown by a provider whose user source is gone (Folio's user type after its extension was
 * disabled). LazyUserProvider turns only this into "resolves nobody"; other setup errors still throw.
 */
final class UserProviderUnavailable extends \RuntimeException {}
