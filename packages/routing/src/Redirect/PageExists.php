<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/**
 * What only the site knows: whether a page holds an address. The redirect rules' name for core's
 * contract ({@see \Preflow\Core\Routing\PageExists}), so a lookup bound under either answers both.
 */
interface PageExists extends \Preflow\Core\Routing\PageExists
{
}
