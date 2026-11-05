<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit\Checks;

require_once __DIR__ . '/CheckFixtures.php';

use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\Checks\DescriptionDuplicate;
use Preflow\View\Audit\Checks\TitleDuplicate;

/** The run checks for titles and descriptions that several pages share. */
final class DuplicateChecksTest extends TestCase
{
    use CheckFixtures;

    public function test_title_duplicate_passes_distinct_titles_and_the_same_title_in_another_language(): void
    {
        $check = new TitleDuplicate($this->registry());
        $check->restore([]);

        $this->assertSame('title.duplicate', $check->id());
        $check->collect($this->page($this->html('<title>Über uns</title>'), 'https://site.test/de/%C3%BCber', 'de'));
        $check->collect($this->page($this->html('<title>Über uns</title>'), 'https://site.test/fr/a-propos', 'fr'));
        $check->collect($this->page($this->html('<title>Spiele</title>'), 'https://site.test/de/spiele', 'de'));
        $check->collect($this->page($this->html(''), 'https://site.test/de/leer', 'de'));
        $check->collect($this->page($this->html(''), 'https://site.test/de/leer2', 'de'));

        $this->assertSame([], $check->finish());
    }

    public function test_title_duplicate_reports_each_page_sharing_a_title_in_one_language(): void
    {
        $check = new TitleDuplicate($this->registry());
        $check->restore([]);

        $check->collect($this->page($this->html('<title>Über uns</title>'), 'https://site.test/de/%C3%BCber', 'de'));
        $check->collect($this->page($this->html('<title>Spiele</title>'), 'https://site.test/de/spiele', 'de'));
        $check->collect($this->page($this->html("<title>  über\n uns </title>"), 'https://site.test/de/team', 'de'));
        $check->collect($this->page($this->html('<title>Über uns</title>'), 'https://site.test/fr/a-propos', 'fr'));

        $findings = $check->finish();

        $this->assertSame([
            'title.duplicate|warning|https://site.test/de/%C3%BCber|de',
            'title.duplicate|warning|https://site.test/de/team|de',
        ], $this->summary($findings));
        $this->assertStringContainsString('Über uns', $findings[0]->message);
        $this->assertStringContainsString('https://site.test/de/team', $findings[0]->message);
        $this->assertStringContainsString('https://site.test/de/%C3%BCber', $findings[1]->message);
        $this->assertTargetsNamePages($findings);
    }

    public function test_title_duplicate_continues_from_its_state(): void
    {
        $first = new TitleDuplicate($this->registry());
        $first->restore([]);
        $first->collect($this->page($this->html('<title>Spiele</title>'), 'https://site.test/de/a', 'de'));
        $state = json_decode((string) json_encode($first->state()), true);

        $second = new TitleDuplicate($this->registry());
        $second->restore($state);
        $second->collect($this->page($this->html('<title>Spiele</title>'), 'https://site.test/de/b', 'de'));

        $this->assertSame([
            'title.duplicate|warning|https://site.test/de/a|de',
            'title.duplicate|warning|https://site.test/de/b|de',
        ], $this->summary($second->finish()));
    }

    public function test_description_duplicate(): void
    {
        $check = new DescriptionDuplicate($this->registry());
        $check->restore([]);

        $this->assertSame('description.duplicate', $check->id());
        $check->collect($this->page($this->html('<title>A</title><meta name="description" content="Brettspiele in Bern.">'), 'https://site.test/a'));
        $check->collect($this->page($this->html('<title>B</title><meta name="description" content="Brettspiele in Bern.">'), 'https://site.test/b'));
        $check->collect($this->page($this->html('<title>C</title><meta name="description" content="Something else.">'), 'https://site.test/c'));
        $check->collect($this->page($this->html('<title>D</title>'), 'https://site.test/d'));
        $check->collect($this->page($this->html('<title>E</title>'), 'https://site.test/e'));

        $findings = $check->finish();

        $this->assertSame([
            'description.duplicate|notice|https://site.test/a|en',
            'description.duplicate|notice|https://site.test/b|en',
        ], $this->summary($findings));
        $this->assertTargetsNamePages($findings);
    }
}
