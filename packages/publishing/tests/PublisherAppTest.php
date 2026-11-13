<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\Data\DynamicRecord;
use Preflow\Publishing\LanguageChecks;
use Preflow\Publishing\LiveContent;
use Preflow\Publishing\NoRecordAddresses;
use Preflow\Publishing\PreviewLinks;
use Preflow\Publishing\PublishedModels;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\PublishingConfig;
use Preflow\Publishing\PublishingTypes;
use Preflow\Publishing\PublishState;
use Preflow\Publishing\SchemaPublishingTypes;
use Preflow\Publishing\Tests\Fixtures\Job;
use Preflow\Publishing\VersionConflict;
use Preflow\Validation\ValidationException;

/** Publishing a type without Folio: its schema's "publishing" flag is all it takes. */
final class PublisherAppTest extends PublishingAppTestCase
{
    public function test_the_schema_flag_decides_which_types_publish(): void
    {
        $types = $this->app()->container()->get(PublishingTypes::class);

        $this->assertInstanceOf(SchemaPublishingTypes::class, $types);
        $this->assertTrue($types->publishes('post'));
        $this->assertTrue($types->publishes('job'), 'a class-backed type, through its model file');
        $this->assertFalse($types->publishes('note'));
        $this->assertFalse($types->publishes('nope'));
        $this->assertSame(['job', 'post'], $types->publishing());
        $this->assertInstanceOf(NoRecordAddresses::class, $this->app()->container()->get(\Preflow\Publishing\RecordAddresses::class));
    }

    public function test_drafts_are_saved_and_published(): void
    {
        $p = $this->publisher();
        $draft = DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('post'), ['uuid' => 'p1', 'title' => ['en' => 'Hello']]);
        $p->saveDraft($draft, null);

        $this->assertSame(PublishState::Draft, $p->state($this->stored('post', 'p1')));
        $this->assertNull($p->live('post', 'p1'));
        $this->assertSame(1, $this->stored('post', 'p1')->get('_version'));

        $p->publish($this->stored('post', 'p1'), 'ana');
        $this->assertSame(PublishState::Published, $p->state($this->stored('post', 'p1')));
        $this->assertSame(['en' => 'Hello'], $p->live('post', 'p1')?->get('title'));
        $this->assertSame('ana', $p->snapshot('post', 'p1')?->get('_published_by'));

        $changed = $this->stored('post', 'p1');
        $changed->set('title', ['en' => 'Hello again']);
        $p->saveDraft($changed, 1);
        $this->assertSame(PublishState::Changed, $p->state($this->stored('post', 'p1')));
        $this->assertSame(['en' => 'Hello'], $p->live('post', 'p1')?->get('title'), 'the site still sees the published copy');

        $this->expectException(VersionConflict::class);
        $p->saveDraft($changed, 1);
    }

    public function test_a_type_without_the_flag_is_its_own_live_copy(): void
    {
        $this->working('note', 'n1', ['title' => ['en' => 'Note']]);

        $this->assertFalse($this->publisher()->enabled('note'));
        $this->assertSame(['en' => 'Note'], $this->publisher()->live('note', 'n1')?->get('title'));
        $this->assertSame(['en' => 'Note'], $this->app()->container()->get(LiveContent::class)->find('note', 'n1')?->get('title'));
    }

    public function test_keeps_the_configured_revisions_restores_and_discards(): void
    {
        $this->write('config/publishing.php', "<?php\nreturn ['revisions' => 2];\n");
        $p = $this->publisher();
        foreach (['One', 'Two', 'Three'] as $title) {
            $p->publish($this->working('post', 'p1', ['title' => ['en' => $title]]), null);
        }

        $revisions = $p->revisions('post', 'p1');
        $this->assertCount(2, $revisions);
        $this->assertSame([3, 2], array_map(static fn ($r): int => $r->seq, $revisions));
        $this->assertSame(['en' => 'Two'], $revisions[1]->data['title']);

        $p->restore('post', 'p1', $revisions[1]->id);
        $this->assertSame(['en' => 'Two'], $this->stored('post', 'p1')->get('title'));
        $this->assertSame(PublishState::Changed, $p->state($this->stored('post', 'p1')));
        $this->assertSame(['en' => 'Three'], $p->live('post', 'p1')?->get('title'), 'a restore goes live only when published');

        $p->discard('post', 'p1');
        $this->assertSame(['en' => 'Three'], $this->stored('post', 'p1')->get('title'));
        $this->assertSame(PublishState::Published, $p->state($this->stored('post', 'p1')));
    }

    public function test_previews_by_token(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Draft'], '_share_token' => 'nonce1']);
        $links = $this->app()->container()->get(PreviewLinks::class);

        $this->assertTrue($links->enabled());
        $this->assertSame(PublishingConfig::DEFAULT_PREVIEW_TTL, $links->ttl());
        $target = $links->verify($links->create('post', 'p1', 'nonce1'));
        $this->assertSame(['post', 'p1', 'nonce1'], [$target?->type, $target?->id, $target?->nonce]);
        $this->assertNull($links->verify($links->create('post', 'p1', 'nonce1') . 'x'));

        // A preview reads the working copy the token points at; a public read sees nothing yet.
        $live = $this->app()->container()->get(LiveContent::class);
        $this->assertNull($live->find('post', 'p1'));
        $live->showWorkingCopies();
        $this->assertSame(['en' => 'Draft'], $live->find('post', 'p1')?->get('title'));
    }

    public function test_preview_ttl_comes_from_the_config(): void
    {
        $this->write('config/publishing.php', "<?php\nreturn ['preview_ttl' => 3600];\n");

        $this->assertSame(3600, $this->app()->container()->get(PreviewLinks::class)->ttl());
    }

    public function test_published_models_read_snapshots_outside_a_preview_and_working_copies_inside(): void
    {
        $dm = $this->dm();
        $job = new Job();
        $job->uuid = 'j1';
        $job->title = 'Carpenter';
        $dm->save($job);
        $this->publisher()->publish($this->stored('job', 'j1'), null);
        $draft = $dm->find(Job::class, 'j1');
        $draft->title = 'Draft title';
        $dm->save($draft);

        $published = $this->app()->container()->get(PublishedModels::class);
        $this->assertSame('Carpenter', $published->find(Job::class, 'j1')?->title);
        $this->assertSame(['Carpenter'], array_map(static fn (Job $j): string => $j->title, $published->query(Job::class)->get()->items()));

        $this->app()->container()->get(LiveContent::class)->showWorkingCopies();
        $this->assertSame('Draft title', $published->find(Job::class, 'j1')?->title);
    }

    public function test_complete_languages_refuses_and_names_the_locale(): void
    {
        $this->write('config/publishing.php', "<?php\nreturn ['complete_languages' => true];\n");
        $this->app()->container()->singleton(LanguageChecks::class, static fn (): LanguageChecks => new class implements LanguageChecks {
            public function clearMachineMarks(DynamicRecord $record): void
            {
                $record->set('_machine_translated', []);
            }

            public function incomplete(DynamicRecord $record): array
            {
                return ($record->get('body')['de'] ?? '') === '' ? ['_translations.de' => ['German lacks Body']] : [];
            }

            public function stateKeys(): array
            {
                return ['_machine_translated'];
            }
        });
        $p = $this->publisher();
        $record = $this->working('post', 'p1', ['title' => ['en' => 'Hi', 'de' => 'Hallo'], 'body' => ['en' => 'Text'], '_locales' => ['en', 'de'], '_machine_translated' => ['de' => ['title']]]);

        try {
            $p->publish($record, null);
            $this->fail('published with German incomplete');
        } catch (ValidationException $e) {
            $this->assertSame(['_translations.de' => ['German lacks Body']], $e->errors());
        }
        $this->assertNull($p->snapshot('post', 'p1'));
        $this->assertSame(['de' => ['title']], $record->get('_machine_translated'), 'a refused publish clears no marks');

        $record->set('body', ['en' => 'Text', 'de' => 'Text']);
        $p->publish($record, null);
        $this->assertSame([], $p->snapshot('post', 'p1')?->get('_machine_translated'), 'publishing clears the marks');
    }

    public function test_a_failed_publish_puts_the_marks_back(): void
    {
        $this->app()->container()->singleton(LanguageChecks::class, static fn (): LanguageChecks => new class implements LanguageChecks {
            public function clearMachineMarks(DynamicRecord $record): void
            {
                $record->remove('_machine_translated');
            }

            public function incomplete(DynamicRecord $record): array
            {
                return [];
            }

            public function stateKeys(): array
            {
                return [];
            }
        });
        $p = $this->publisher();
        $record = $this->working('post', 'p1', ['title' => ['en' => 'Hi'], '_machine_translated' => ['de' => ['title']]]);

        try {
            $p->publish($record, null, 7);
            $this->fail('published with a stale version');
        } catch (VersionConflict) {
        }
        $this->assertSame(['de' => ['title']], $record->get('_machine_translated'));
    }

    public function test_revisions_must_not_be_negative(): void
    {
        $this->write('config/publishing.php', "<?php\nreturn ['revisions' => -1];\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->publisher();
    }

    public function test_publisher_is_bound_once_with_the_defaults(): void
    {
        $this->assertSame($this->publisher(), $this->publisher());
        $this->assertSame(Publisher::class, $this->publisher()::class);
    }
}
