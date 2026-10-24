<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Tests\Support\StaffScaffold;

/**
 * The revisions panel names who made a revision: the account's name for the id of a record user,
 * an e-mail (config era) as is, anything else (a deleted account) as the raw value.
 */
final class RevisionAuthorTest extends TestCase
{
    use StaffScaffold;

    protected function setUp(): void
    {
        $this->staffSite();
        $this->write('config/models/post.json', (string) json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Posts', 'publishing' => true,
            'fields' => ['title' => ['type' => 'string']],
        ]));
        $this->flow = $this->app();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
    }

    /** @return list<string> the "by" cells of the panel, newest first */
    private function authors(string ...$by): array
    {
        $dm = $this->dm();
        $publisher = $this->flow->container()->get(Publisher::class);
        $record = DynamicRecord::fromArray($dm->getTypeRegistry()->get('post'), ['uuid' => 'p1', 'title' => 'T']);
        $dm->saveType($record, false);
        foreach ($by as $i => $who) {
            $record->set('title', "T{$i}");
            $publisher->publish($record, $who);
        }
        $this->post('/folio/login', ['email' => self::CONFIG_EMAIL, 'password' => self::PASSWORD]);
        preg_match_all('~<span class="folio-revision-by">([^<]*)</span>~', $this->html('/folio/post/p1/edit'), $m);

        return array_map(static fn (string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_HTML5), $m[1]);
    }

    #[RunInSeparateProcess]
    public function test_a_record_id_shows_the_account_name_an_email_and_unknown_ids_show_as_stored(): void
    {
        $ada = $this->makeStaff();

        $this->assertSame(['ghost-id', 'old@site.test', 'Ada Lovelace'], $this->authors((string) $ada->getId(), 'old@site.test', 'ghost-id'));
    }

    #[RunInSeparateProcess]
    public function test_an_account_without_a_name_shows_its_email(): void
    {
        $nameless = $this->makeStaff('nameless@site.test', data: ['name' => '']);

        $this->assertSame(['nameless@site.test'], $this->authors((string) $nameless->getId()));
    }
}
