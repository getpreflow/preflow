<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\FolioRecordAddresses;
use Preflow\Folio\Publishing\FolioRecordTypes;
use Preflow\Folio\Redirects\RemovalTarget;

/** Folio's answers to RecordWriter and RecordDeleter: orderable listings, and where a delete's old links lead. */
final class WriterAdaptersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_writer_adapters_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        foreach (['song' => ['list' => ['orderable' => true]], 'album' => ['list' => ['orderable' => 'yes']], 'band' => []] as $key => $model) {
            file_put_contents($this->dir . "/models/{$key}.json", (string) json_encode(['key' => $key, 'fields' => ['title' => ['type' => 'string']]] + $model));
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', (array) glob($this->dir . '/models/*.json'));
        rmdir($this->dir . '/models');
        rmdir($this->dir);
    }

    public function test_a_type_is_orderable_when_its_list_says_so(): void
    {
        $types = new FolioRecordTypes(new TypeCatalog($this->dir . '/models'));

        $this->assertTrue($types->orderable('song'));
        $this->assertFalse($types->orderable('album'), 'only true');
        $this->assertFalse($types->orderable('band'));
        $this->assertFalse($types->orderable('nope'));
    }

    public function test_a_delete_target_among_the_deleted_records_is_no_target(): void
    {
        $addresses = new FolioRecordAddresses(null, null, null);
        $target = RemovalTarget::record('song', 's2');

        $this->assertNull($addresses->finalTarget($target, ['song' => ['s1', 's2']]));
        $this->assertSame($target, $addresses->finalTarget($target, ['song' => ['s1'], 'band' => ['s2']]));
        $url = RemovalTarget::parse('/elsewhere');
        $this->assertSame($url, $addresses->finalTarget($url, ['song' => ['s2']]));
        $this->assertNull($addresses->finalTarget(new class implements \Preflow\Publishing\RemovalTarget {}, []), 'not a target Folio knows');
    }
}
