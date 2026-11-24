<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\State\ShortTally;

use Preflow\Core\Tests\Fixtures\State\StatefulTally\StatefulTally;

/** StatefulTally with a button that bumps it through the short call (hd.post('bump')). */
final class ShortTally extends StatefulTally
{
    public function getTemplatePath(string $extension = 'twig'): string
    {
        return __DIR__ . '/ShortTally.' . $extension;
    }
}
