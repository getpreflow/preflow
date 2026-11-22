<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Support\SeedActor;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Activity\ActivityActors;
use Preflow\I18n\Translator;
use Tests\Support\StarterApp;

/**
 * The seeders publish and import as "seed", no person. The marker is registered with core's
 * ActivityActors, so the activity log names it "Demo content", never "Former user".
 */
final class DemoActorTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    public function test_the_seed_marker_is_registered_with_a_label(): void
    {
        $this->assertSame('seed', SeedActor::MARKER);
        $this->assertSame(SeedActor::LABEL, $this->container()->get(ActivityActors::class)->label(SeedActor::MARKER));
    }

    public function test_the_label_reads_in_english_german_and_french(): void
    {
        // Each locale asked for by name: nothing to set or put back (the translator follows the render context).
        $translator = $this->container()->get(Translator::class);
        foreach (['en' => 'Demo content', 'de' => 'Demo-Inhalte', 'fr' => 'Contenu de démonstration'] as $locale => $text) {
            $this->assertSame($text, $translator->get(SeedActor::LABEL, [], $locale), $locale);
        }
    }

    public function test_recent_changes_and_all_activity_name_the_demo_content(): void
    {
        $cookies = ['Cookie' => $this->cookieHeader($this->signInStaff('admin@example.com', 'demo-admin'))];

        // The dashboard loads its Recent changes after the page (an htmx request for part=recent).
        foreach (['/folio?part=recent' => ['HX-Request' => 'true'], '/folio/activity' => []] as $path => $headers) {
            $body = $this->get($path, $cookies + $headers)->assertOk()->body();
            $this->assertStringContainsString('Demo content', $body, $path);
            $this->assertStringNotContainsString('Former user', $body, $path);
        }
    }
}
