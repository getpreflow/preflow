<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Fields\Tests\Support\FakeAddresses;
use Preflow\Fields\Tests\Support\RecordsOnly;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\LinkKind;

/** The link value (0.91): what a link field stores, and the href and label a page shows. */
final class LinkTest extends TestCase
{
    /** @return array<string, array{Link, array<string, mixed>}> */
    public static function kinds(): array
    {
        return [
            'record' => [Link::toRecord('page', 'p1', ['en' => 'About us', 'de' => 'Über uns']), ['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'p1'], 'label' => ['en' => 'About us', 'de' => 'Über uns'], 'new_tab' => false]],
            'url' => [Link::toUrl('https://example.org/a?b=1', 'Example', newTab: true), ['kind' => 'url', 'target' => 'https://example.org/a?b=1', 'label' => 'Example', 'new_tab' => true]],
            'email' => [Link::toEmail('team@example.org'), ['kind' => 'email', 'target' => 'team@example.org', 'label' => null, 'new_tab' => false]],
            'phone' => [Link::toPhone('+41 44 123 45 67', ['en' => 'Call us']), ['kind' => 'phone', 'target' => '+41 44 123 45 67', 'label' => ['en' => 'Call us'], 'new_tab' => false]],
            'asset' => [Link::toAsset('a0f3', 'Price list (PDF)', newTab: true), ['kind' => 'asset', 'target' => 'a0f3', 'label' => 'Price list (PDF)', 'new_tab' => true]],
        ];
    }

    /** @param array<string, mixed> $stored */
    #[DataProvider('kinds')]
    public function test_each_kind_round_trips_through_its_stored_shape(Link $link, array $stored): void
    {
        $this->assertSame($stored, $link->toStored());
        $this->assertEquals($link, Link::fromStored($stored));
        // SQL storage gives the value back as JSON text, with its keys in any order.
        $this->assertEquals($link, Link::fromStored((string) json_encode(array_reverse($stored, true))));
    }

    public function test_nothing_stored_is_no_link(): void
    {
        foreach ([null, '', '  ', [], '[]', 'null'] as $empty) {
            $this->assertNull(Link::fromStored($empty), var_export($empty, true));
        }
    }

    public function test_a_stored_value_that_is_no_link_reads_as_none(): void
    {
        foreach ([
            'text' => 'https://example.org',
            'unknown kind' => ['kind' => 'ftp', 'target' => 'x'],
            'no target' => ['kind' => 'url', 'target' => ''],
            'record without id' => ['kind' => 'record', 'target' => ['type' => 'page']],
            'record target as text' => ['kind' => 'record', 'target' => 'p1'],
            'list' => [['kind' => 'url', 'target' => 'https://example.org']],
            'broken json' => '{"kind": "url"',
        ] as $case => $stored) {
            $this->assertNull(Link::fromStored($stored), $case);
        }
    }

    public function test_a_number_id_and_loose_flags_are_read(): void
    {
        $link = Link::fromStored(['kind' => 'record', 'target' => ['type' => 'page', 'id' => 7], 'new_tab' => '1']);
        $this->assertSame(['type' => 'page', 'id' => '7'], $link?->target);
        $this->assertTrue($link->newTab);
        $this->assertSame(LinkKind::Record, $link->kind);
    }

    public function test_the_constructor_refuses_a_target_that_does_not_fit_its_kind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A record link needs its target as ["type" => …, "id" => …]');
        new Link(LinkKind::Record, 'p1');
    }

    public function test_href_per_kind(): void
    {
        $addresses = new FakeAddresses(['page/p1/en' => '/about', 'page/p1/de' => '/de/ueber-uns'], ['a0f3' => '/uploads/prices.pdf']);

        $this->assertSame('/about', Link::toRecord('page', 'p1')->href($addresses, 'en'));
        $this->assertSame('/de/ueber-uns', Link::toRecord('page', 'p1')->href($addresses, 'de'), 'the record\'s address in the page\'s locale');
        $this->assertSame('https://example.org/a?b=1', Link::toUrl('https://example.org/a?b=1')->href(null, 'en'));
        $this->assertSame('/contact#form', Link::toUrl('/contact#form')->href(null, 'en'), 'a path on the site');
        $this->assertSame('mailto:team@example.org', Link::toEmail('team@example.org')->href(null, 'en'));
        $this->assertSame('tel:+41441234567', Link::toPhone('+41 44 123 45 67')->href(null, 'en'), 'tel: dials the digits');
        $this->assertSame('/uploads/prices.pdf', Link::toAsset('a0f3')->href($addresses, 'en'));
    }

    public function test_link_to_missing_record_has_no_href(): void
    {
        // The site's addresses answer null for a record that is unpublished or deleted.
        $addresses = new FakeAddresses(['page/p1/en' => '/about']);

        $this->assertNull(Link::toRecord('page', 'unpublished')->href($addresses, 'en'), 'unpublished');
        $this->assertNull(Link::toRecord('page', 'deleted')->href($addresses, 'en'), 'deleted');
        $this->assertNull(Link::toRecord('page', 'p1')->href(null, 'en'), 'no RecordAddresses bound');
        $this->assertNull(Link::toAsset('a0f3')->href($addresses, 'en'), 'an asset that is gone');
        $this->assertNull(Link::toAsset('a0f3')->href(new RecordsOnly(), 'en'), 'addresses that know no assets');
    }

    public function test_an_address_that_could_run_script_or_is_no_address_has_no_href(): void
    {
        foreach (['javascript:alert(1)', 'javascript://%0aalert(1)', 'data:text/html,x', '//evil.example/a', 'ftp://example.org/f', 'example.org', 'https://'] as $url) {
            $this->assertNull(Link::toUrl($url)->href(null, 'en'), $url);
        }
        $this->assertNull(Link::toEmail('no address')->href(null, 'en'));
        $this->assertNull(Link::toPhone('call me')->href(null, 'en'));
    }

    public function test_label_translatable_per_locale(): void
    {
        $locales = new Locales(['en', 'de', 'fr'], 'en', 'de');
        $link = Link::toRecord('page', 'p1', ['en' => 'About us', 'de' => 'Über uns', 'fr' => '']);

        $this->assertSame('About us', $link->label('en', $locales));
        $this->assertSame('Über uns', $link->label('de', $locales));
        $this->assertSame('Über uns', $link->label('fr', $locales), 'an empty text follows the fallback chain');
        $this->assertNull($link->label('fr'), 'without the locales: only the locale\'s own text');
        $this->assertSame('Example', Link::toUrl('https://example.org', 'Example')->label('de', $locales), 'a plain label is every locale\'s');
        $this->assertNull(Link::toUrl('https://example.org')->label('en', $locales));
    }

    public function test_attributes_open_a_new_tab_safely(): void
    {
        $this->assertSame(['href' => 'https://example.org'], Link::toUrl('https://example.org')->attributes(null, 'en'));
        $this->assertSame(['href' => 'https://example.org', 'target' => '_blank', 'rel' => 'noopener noreferrer'], Link::toUrl('https://example.org', newTab: true)->attributes(null, 'en'));
        $this->assertSame([], Link::toRecord('page', 'gone', newTab: true)->attributes(null, 'en'), 'no href, no target either');
    }
}
