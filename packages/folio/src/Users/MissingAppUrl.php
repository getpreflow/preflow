<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

/** No sign-in link can be mailed: config/app.php has no `url` to build it from (no token was issued). */
final class MissingAppUrl extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('app.url is not set: sign-in links cannot be built.');
    }
}
