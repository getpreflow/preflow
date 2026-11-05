<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\Finding;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\Target;

final class FindingTest extends TestCase
{
    public function test_a_finding_with_a_target_round_trips_through_an_array(): void
    {
        $finding = new Finding(
            'title.missing',
            Severity::Critical,
            'The page has no <title>. Give it one.',
            'https://site.test/de/über',
            'de',
            new Target('record', 'page', '42'),
        );

        $array = $finding->toArray();

        $this->assertSame([
            'check' => 'title.missing',
            'severity' => 'critical',
            'message' => 'The page has no <title>. Give it one.',
            'url' => 'https://site.test/de/über',
            'locale' => 'de',
            'target' => ['kind' => 'record', 'type' => 'page', 'id' => '42'],
        ], $array);
        $this->assertEquals($finding, Finding::fromArray($array));
        $this->assertEquals($finding, Finding::fromArray(json_decode((string) json_encode($array), true)));
    }

    public function test_a_site_finding_without_url_or_target_round_trips(): void
    {
        $finding = new Finding('robots.missing', Severity::Notice, 'robots.txt does not answer.');

        $array = $finding->toArray();

        $this->assertSame('', $array['url']);
        $this->assertSame('', $array['locale']);
        $this->assertNull($array['target']);
        $this->assertEquals($finding, Finding::fromArray($array));
    }

    public function test_severities_are_exactly_critical_warning_notice(): void
    {
        $this->assertSame(['critical', 'warning', 'notice'], array_map(
            static fn (Severity $s): string => $s->value,
            Severity::cases(),
        ));
    }
}
