<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Publishing\RecordAddressFields;
use Preflow\Publishing\RecordTypes;
use Preflow\Publishing\RecordWriter;

/** Folio's RecordTypes binding names each type's address fields from its route, so a copy takes a free address. */
final class FolioRecordAddressFieldsTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('doc', ['frontend' => ['route' => '/docs/{...path}'], 'fields' => ['title' => ['type' => 'string'], 'path' => ['type' => 'string']]]);
        $this->writeModel('member_card', ['frontend' => ['route' => '/team/{handle}'], 'fields' => ['handle' => ['type' => 'string']]]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_the_route_parameters_are_the_address_fields(): void
    {
        $types = $this->app()->container()->get(RecordTypes::class);

        $this->assertInstanceOf(RecordAddressFields::class, $types);
        $this->assertSame(['slug' => false], $types->addressFields('post'));
        $this->assertSame(['path' => true], $types->addressFields('doc'));
        $this->assertSame(['handle' => false], $types->addressFields('member_card'));
    }

    public function test_a_copy_takes_a_free_address(): void
    {
        $this->working('doc', 'd1', ['title' => 'Über uns', 'path' => 'docs/ueber-uns']);
        $this->working('member_card', 'm1', ['handle' => 'ada']);
        $writer = $this->app()->container()->get(RecordWriter::class);

        $doc = $writer->duplicate('doc', 'd1')->record;
        $card = $writer->duplicate('member_card', 'm1')->record;

        $this->assertSame('docs/ueber-uns-2', $this->stored('doc', (string) $doc->getId())->get('path'));
        $this->assertSame('ada-2', $this->stored('member_card', (string) $card->getId())->get('handle'));
    }
}
