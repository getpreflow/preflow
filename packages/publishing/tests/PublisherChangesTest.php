<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

/** Publisher::changes(): what a publish of the working copy would change. */
final class PublisherChangesTest extends PublishingAppTestCase
{
    public function test_a_draft_never_published_has_no_changes(): void
    {
        $working = $this->working('post', 'p1', ['title' => ['en' => 'Hello']]);

        $this->assertSame([], $this->publisher()->changes($working));
    }

    public function test_a_published_record_unchanged_has_none(): void
    {
        $working = $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'body' => ['en' => 'Text']]);
        $this->publisher()->publish($working, 'ana');

        $this->assertSame([], $this->publisher()->changes($this->stored('post', 'p1')));
    }

    public function test_one_field_edited_is_named(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'body' => ['en' => 'Text']]), 'ana');
        $working = $this->stored('post', 'p1');
        $working->set('title', ['en' => 'Hello', 'de' => 'Hallo']);
        $this->publisher()->saveDraft($working, null);

        $this->assertSame(['title'], $this->publisher()->changes($this->stored('post', 'p1')));
    }

    public function test_the_id_and_system_keys_never_count(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello']]), 'ana');
        $working = $this->stored('post', 'p1');
        foreach (['_version' => 7, '_publish_at' => '2031-01-01T10:00', '_share_token' => 'abc', '_order' => 40, '_published_by' => 'bo'] as $key => $value) {
            $working->set($key, $value);
        }
        $working->set('body', ''); // empty against missing: no change either

        $this->assertSame([], $this->publisher()->changes($working));
    }

    public function test_visible_in_alone_is_named(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello'], '_locales' => ['en']]), 'ana');
        $working = $this->stored('post', 'p1');
        $working->set('_locales', ['en', 'de']);

        $this->assertSame(['_locales'], $this->publisher()->changes($working));
        $this->assertSame(\Preflow\Publishing\PublishState::Changed, $this->publisher()->state($working));
    }

    public function test_seo_alone_is_named_after_the_fields(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello']]), 'ana');
        $working = $this->stored('post', 'p1');
        $working->set('_seo', ['en' => ['title' => 'Hello there']]);

        $this->assertSame(['_seo'], $this->publisher()->changes($working));

        $working->set('body', ['en' => 'New']);
        $this->assertSame(['body', '_seo'], $this->publisher()->changes($working), 'fields first, then the other keys');
    }

    public function test_values_are_compared_as_stored(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello'], '_seo' => ['en' => ['noindex' => true]]]), 'ana');
        $working = $this->stored('post', 'p1');
        $working->set('_seo', (string) json_encode(['en' => ['noindex' => 1]]));

        $this->assertSame([], $this->publisher()->changes($working), 'JSON text and numbers as SQL returns them');
    }
}
