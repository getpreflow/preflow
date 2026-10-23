<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\AssetFieldType;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\Folio\Tests\Fixtures\Blocks\HeroBlock\HeroBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\TradeoffNoteBlock;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

final class BlocksFieldTypeTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const C = 'cccccccccccccccc';

    private string $uploads;

    private Locales $bilingual;

    protected function setUp(): void
    {
        $this->uploads = sys_get_temp_dir() . '/bft_' . bin2hex(random_bytes(4));
        mkdir($this->uploads, 0777, true);
        $this->bilingual = new Locales(['en', 'de'], 'en');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->uploads, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->uploads);
    }

    private function type(int $maxDepth = 4): BlocksFieldType
    {
        $fields = new FieldTypeRegistry();
        $fields->register(new StringFieldType());
        $fields->register(new TextFieldType());
        $fields->register(new AssetFieldType($this->uploads, '/folio/_uploads'));
        $blocks = new BlocksFieldType(
            new BlockRegistry([HeroBlock::class, LinkBlock::class, TradeoffNoteBlock::class]),
            $fields,
            $maxDepth,
            new ValidatorFactory(new RuleFactory()),
        );
        $fields->register($blocks);

        return $blocks;
    }

    private function ctx(?Locales $locales = null, string $locale = 'en', bool $writeUploads = true): BuildContext
    {
        return new BuildContext($locales ?? $this->bilingual, $locale, $writeUploads);
    }

    public function test_admin_display_is_a_block_count(): void
    {
        $t = $this->type();
        $this->assertSame('2 blocks', $t->renderAdmin([['_block' => 'hero'], ['_block' => 'link']], []));
        $this->assertSame('1 block', $t->renderAdmin(json_encode([['_block' => 'hero']]), []));
        $this->assertSame('', $t->renderAdmin([], []));
    }

    public function test_builds_ordered_blocks_with_uids(): void
    {
        $value = $this->type()->buildValue([
            '_order' => [self::A, self::B],
            self::A => ['_block' => 'hero', 'headline' => 'Hi', 'intro' => 'I'],
            self::B => ['_block' => 'link', 'label' => 'L', 'url' => '/x'],
        ], null, [], $this->ctx());

        $this->assertSame([
            ['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Hi'], 'intro' => ['en' => 'I'], 'tradeoff' => []],
            ['_uid' => self::B, '_block' => 'link', 'label' => ['en' => 'L'], 'url' => '/x'],
        ], $value);
    }

    public function test_order_follows_order_inputs_not_post_order(): void
    {
        $value = $this->type()->buildValue([
            self::A => ['_block' => 'hero', 'headline' => 'First posted', 'tradeoff' => [
                '_order' => [self::C],
                self::C => ['_block' => 'tradeoff_note', 'chose' => 'c'],
            ]],
            self::B => ['_block' => 'link', 'label' => 'L', 'url' => '/x'],
            '_order' => [self::B, self::A],
        ], null, [], $this->ctx(Locales::single('en')));

        $this->assertSame([self::B, self::A], array_column($value, '_uid'));
        $this->assertSame(self::C, $value[1]['tradeoff'][0]['_uid']);
    }

    public function test_locale_merge_by_uid(): void
    {
        $existing = [['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'H'], 'intro' => ['en' => 'I'], 'tradeoff' => []]];

        $value = $this->type()->buildValue([
            '_order' => [self::A, self::C],
            self::A => ['_block' => 'hero', 'headline' => 'Ü', 'intro' => ''],
            self::C => ['_block' => 'hero', 'headline' => 'Neu'],
        ], $existing, [], $this->ctx(locale: 'de'));

        $this->assertSame(['en' => 'H', 'de' => 'Ü'], $value[0]['headline']);
        $this->assertSame(['en' => 'I', 'de' => ''], $value[0]['intro']);
        // A block created while editing German starts with the same text in English,
        // so a required field never blocks the save (translate it later).
        $this->assertSame(['de' => 'Neu', 'en' => 'Neu'], $value[1]['headline']);
    }

    public function test_unknown_block_survives_save_untouched(): void
    {
        $gone = ['_uid' => self::A, '_block' => 'gone', 'foo' => ['x' => 1]];

        $value = $this->type()->buildValue([
            '_order' => [self::A],
            self::A => ['_block' => 'gone', '_raw' => json_encode($gone)],
        ], [$gone], [], $this->ctx());

        $this->assertSame([$gone], $value);
    }

    public function test_nested_upload_lands_in_right_block(): void
    {
        $factory = new Psr17Factory();
        $file = $factory->createUploadedFile($factory->createStream('png'), 3, UPLOAD_ERR_OK, 'c.png', 'image/png');
        $input = [
            '_order' => [self::A],
            self::A => ['_block' => 'hero', 'headline' => 'H', 'tradeoff' => [
                '_order' => [self::C],
                self::C => ['_block' => 'tradeoff_note', 'chose' => 'x'],
            ]],
        ];
        $files = [self::A => ['tradeoff' => [self::C => ['cover' => $file]]]];

        $preview = $this->type()->buildValue($input, null, $files, $this->ctx(writeUploads: false));
        $this->assertSame('', $preview[0]['tradeoff'][0]['cover']);

        $saved = $this->type()->buildValue($input, null, $files, $this->ctx());
        $this->assertStringEndsWith('.png', $saved[0]['tradeoff'][0]['cover']);
        $this->assertFileExists($this->uploads . '/' . $saved[0]['tradeoff'][0]['cover']);
    }

    public function test_malformed_input_is_ignored(): void
    {
        $value = $this->type(maxDepth: 1)->buildValue([
            '_order' => [self::A, 'not-a-uid', self::B, self::C, ['x']],
            self::A => ['headline' => 'missing _block'],
            'not-a-uid' => ['_block' => 'hero'],
            self::B => ['_block' => 'hero', 'headline' => 'ok', 'tradeoff' => '_order-is-not-an-array'],
            self::C => 'not an array',
        ], [['_uid' => self::B, '_block' => 'hero', 'tradeoff' => [['_uid' => self::C, '_block' => 'tradeoff_note']]]], [], $this->ctx(Locales::single('en')));

        $this->assertSame([self::B], array_column($value, '_uid'));
        // Depth limit 1: the nested field is not editable, existing children are kept.
        $this->assertSame([['_uid' => self::C, '_block' => 'tradeoff_note']], $value[0]['tradeoff']);

        $this->assertSame([], $this->type()->buildValue(['_order' => 'oops'], null, [], $this->ctx()));
        $this->assertSame([], $this->type()->buildValue('garbage', null, [], $this->ctx()));
    }

    public function test_validate_blocks_per_instance_and_min_max(): void
    {
        $value = [
            ['_uid' => self::A, '_block' => 'hero', 'headline' => ['de' => 'nur deutsch'], 'tradeoff' => [
                ['_uid' => self::B, '_block' => 'tradeoff_note', 'chose' => 'x'],
                ['_uid' => self::C, '_block' => 'tradeoff_note', 'chose' => 'y'],
            ]],
        ];

        $errors = $this->type()->validateBlocks($value, ['max' => 0], $this->bilingual);

        $this->assertArrayHasKey('', $errors);                   // field-level max 0
        $this->assertArrayHasKey(self::A . '.headline', $errors); // required binds the default locale
        $this->assertArrayHasKey(self::A . '.tradeoff', $errors); // nested max 1
    }

    public function test_single_locale_stores_plain_leaves(): void
    {
        $value = $this->type()->buildValue([
            '_order' => [self::A],
            self::A => ['_block' => 'hero', 'headline' => 'Plain'],
        ], null, [], $this->ctx(Locales::single('en')));

        $this->assertSame('Plain', $value[0]['headline']);
    }

    public function test_storage_round_trip(): void
    {
        $type = $this->type();
        $list = [['_uid' => self::A, '_block' => 'link', 'label' => 'L', 'url' => '/x']];

        $this->assertSame($list, $type->fromStorage(json_encode($list)));
        $this->assertSame($list, $type->fromStorage($type->toStorage($list)));
        $this->assertSame([], $type->fromStorage(null));
    }

    public function test_raw_cannot_smuggle_a_known_block(): void
    {
        $value = $this->type()->buildValue([
            '_order' => [self::A],
            self::A => ['_block' => 'gone', '_raw' => json_encode(['_uid' => self::A, '_block' => 'hero', 'headline' => '<script>x</script>'])],
        ], null, [], $this->ctx());

        $this->assertSame([], $value);
    }

    public function test_malformed_stored_uids_are_kept_with_a_stable_uid(): void
    {
        $type = $this->type();
        $stored = [['_uid' => 'LEGACY-1', '_block' => 'link', 'label' => 'Old', 'url' => '/old'], ['_block' => 'link', 'label' => 'No uid', 'url' => '/n']];

        $first = $type->fromStorage($stored);
        $second = $type->fromStorage($stored);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $first[0]['_uid']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $first[1]['_uid']);
        $this->assertSame(array_column($first, '_uid'), array_column($second, '_uid'));
        $this->assertNotSame($first[0]['_uid'], $first[1]['_uid']);

        // The editor posts the normalized uids back; the blocks survive.
        $uids = array_column($first, '_uid');
        $value = $type->buildValue(['_order' => $uids, $uids[0] => ['_block' => 'link', 'label' => 'Old', 'url' => '/old'], $uids[1] => ['_block' => 'link', 'label' => 'No uid', 'url' => '/n']], $stored, [], $this->ctx(Locales::single('en')));
        $this->assertSame(['Old', 'No uid'], array_column($value, 'label'));
    }

    public function test_allowed_is_enforced_on_the_server(): void
    {
        $value = $this->type()->buildValue([
            '_order' => [self::A, self::B],
            self::A => ['_block' => 'hero', 'headline' => 'H'],
            self::B => ['_block' => 'link', 'label' => 'L', 'url' => '/x'],
        ], [['_uid' => self::B, '_block' => 'link', 'label' => 'kept', 'url' => '/k']], [], $this->ctx(Locales::single('en')), ['allowed' => ['hero']]);

        // A disallowed new block is dropped; a stored one is kept (allowed lists change).
        $this->assertSame([self::A, self::B], array_column($value, '_uid'));
        $this->assertSame('kept', $value[1]['label']);

        $none = $this->type()->buildValue(['_order' => [self::C], self::C => ['_block' => 'link', 'label' => 'L']], null, [], $this->ctx(Locales::single('en')), ['allowed' => ['hero']]);
        $this->assertSame([], $none);
    }

    public function test_truncated_post_keeps_stored_blocks(): void
    {
        // PHP's max_input_vars cut the request: uids are listed but their data is missing.
        $stored = [['_uid' => self::A, '_block' => 'link', 'label' => 'First', 'url' => '/1'], ['_uid' => self::B, '_block' => 'link', 'label' => 'Second', 'url' => '/2']];

        $value = $this->type()->buildValue([
            '_order' => [self::A, self::B],
            self::A => ['_block' => 'link', 'label' => 'First edited', 'url' => '/1'],
        ], $stored, [], $this->ctx(Locales::single('en')));

        $this->assertSame(['First edited', 'Second'], array_column($value, 'label'));
    }

    public function test_blocks_asset_uses_selection(): void
    {
        $path = '2026/10/' . str_repeat('a', 32) . '.png';
        @mkdir($this->uploads . '/2026/10', 0777, true);
        touch($this->uploads . '/' . $path);
        $stored = [['_uid' => self::A, '_block' => 'hero', 'headline' => 'H', 'tradeoff' => [
            ['_uid' => self::C, '_block' => 'tradeoff_note', 'chose' => 'x', 'cover' => $path],
        ]]];

        // No selection posted (e.g. a truncated form): the stored file stays; an empty selection clears it.
        $kept = $this->type()->buildValue(['_order' => [self::A], self::A => ['_block' => 'hero', 'headline' => 'H', 'tradeoff' => [
            '_order' => [self::C], self::C => ['_block' => 'tradeoff_note', 'chose' => 'x'],
        ]]], $stored, [], $this->ctx(Locales::single('en')));
        $this->assertSame($path, $kept[0]['tradeoff'][0]['cover']);

        $cleared = $this->type()->buildValue(['_order' => [self::A], self::A => ['_block' => 'hero', 'headline' => 'H', 'tradeoff' => [
            '_order' => [self::C], self::C => ['_block' => 'tradeoff_note', 'chose' => 'x', 'cover_ids' => ['']],
        ]]], $stored, [], $this->ctx(Locales::single('en')));
        $this->assertSame('', $cleared[0]['tradeoff'][0]['cover']);
    }

    public function test_new_block_keeps_valid_posted_upload_paths_only(): void
    {
        $path = '2026/10/' . str_repeat('a', 32) . '.png';
        @mkdir($this->uploads . '/2026/10', 0777, true);
        touch($this->uploads . '/' . $path);

        $value = $this->type()->buildValue([
            '_order' => [self::A],
            self::A => ['_block' => 'hero', 'headline' => 'H', 'tradeoff' => [
                '_order' => [self::C],
                self::C => ['_block' => 'tradeoff_note', 'chose' => 'x', 'cover_ids' => ['', $path, '../../etc/passwd', '2026/10/' . str_repeat('b', 32) . '.png']],
            ]],
        ], null, [], $this->ctx(Locales::single('en')));

        // Duplicates carry their files; only existing uploads in the expected format are accepted.
        $this->assertSame($path, $value[0]['tradeoff'][0]['cover']);
    }
}
