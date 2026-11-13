<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Extension\Badge;

final class BadgeTest extends TestCase
{
    public function test_known_tones_including_scheduled_and_failed(): void
    {
        foreach (['published', 'draft', 'changed', 'scheduled', 'failed', 'incomplete'] as $tone) {
            $this->assertSame($tone, (new Badge('x', $tone))->tone());
        }
        $this->assertSame('', (new Badge('x', 'nonsense'))->tone());
        $this->assertSame('', (new Badge('x'))->tone());
    }
}
