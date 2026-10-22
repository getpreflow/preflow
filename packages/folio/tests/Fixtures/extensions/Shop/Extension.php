<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\extensions\Shop;

use Preflow\Folio\Extension\Attributes\FolioExtension;

/** Package extension fixture (named by extra.folio.extension in a temp installed.json). */
#[FolioExtension(name: 'shop', label: 'Shop')]
final class Extension extends \Preflow\Folio\Extension\Extension
{
}
