<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\MediaLibrary;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\AssetFieldType;
use Preflow\Folio\Tests\Support\AssetFixtures;

/** Asset fields with the library: values are asset ids; the posted selection ({name}_ids[]) is authoritative. */
#[RequiresPhpExtension('gd')]
final class AssetFieldLibraryTest extends TestCase
{
    use AssetFixtures;

    private const array SINGLE = ['asset' => ['multiple' => false, 'accept' => 'image/*']];
    private const array MULTIPLE = ['asset' => ['multiple' => true]];

    private MediaLibrary $lib;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        mkdir($this->assetRoot . '/uploads', 0777, true);
        $this->lib = $this->library();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    public function test_upload_goes_into_library_and_stores_id(): void
    {
        $value = $this->field()->resolve(null, null, [$this->upload($this->png(20, 10), 'pic.png')], self::SINGLE, true, 'u@x');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $value);
        $asset = $this->lib->find($value);
        $this->assertNotNull($asset);
        $this->assertSame('u@x', $asset->createdBy);
        $this->assertFileExists($this->assetRoot . '/media/' . $asset->path);
    }

    public function test_selection_order_is_kept(): void
    {
        [$a, $b] = [$this->asset(), $this->asset()];

        $value = $this->field()->resolve(['', $b, $a], null, [], self::MULTIPLE, true, null);

        $this->assertSame([$b, $a], $this->field()->fromStorage($value));
    }

    public function test_missing_selection_keeps_stored_value(): void
    {
        $a = $this->asset();

        $this->assertSame($a, $this->field()->resolve(null, $a, [], self::SINGLE, true, null));
        $this->assertSame('', $this->field()->resolve([''], $a, [], self::SINGLE, true, null));
    }

    public function test_selection_accepts_known_ids_and_stored_paths_only(): void
    {
        $legacy = '2026/10/' . str_repeat('a', 32) . '.png';
        mkdir($this->assetRoot . '/uploads/2026/10', 0777, true);
        touch($this->assetRoot . '/uploads/' . $legacy);

        $value = $this->field()->resolve(
            ['', str_repeat('c', 32), '../../etc/passwd', '2026/10/' . str_repeat('b', 32) . '.png', $legacy, ['nested']],
            null,
            [],
            self::MULTIPLE,
            true,
            null,
        );

        $this->assertSame([$legacy], $this->field()->fromStorage($value));
    }

    public function test_single_field_keeps_the_last_value(): void
    {
        [$a, $b] = [$this->asset(), $this->asset()];

        $this->assertSame($b, $this->field()->resolve(['', $a, $b], null, [], self::SINGLE, true, null));

        $uploaded = $this->field()->resolve(['', $a], null, [$this->upload($this->png(5, 5), 'new.png')], self::SINGLE, true, null);
        $this->assertNotSame($a, $uploaded);
        $this->assertNotNull($this->lib->find($uploaded));
    }

    public function test_upload_of_the_wrong_kind_is_dropped(): void
    {
        $value = $this->field()->resolve(null, null, [$this->upload("notes\n", 'notes.txt')], self::SINGLE, true, null);

        $this->assertSame('', $value);
        $this->assertSame([], $this->lib->all());
    }

    public function test_accept_tokens_match_kinds_types_and_extensions(): void
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        $field = $this->field();

        $this->assertNotSame('', $field->resolve(null, null, [$this->upload($jpeg, 'a.jpeg')], ['asset' => ['accept' => '.jpeg']], true, null));
        $this->assertNotSame('', $field->resolve(null, null, [$this->upload($this->png(5, 5), 'a.png')], ['asset' => ['accept' => 'image/*,video/*']], true, null));
        $this->assertNotSame('', $field->resolve(null, null, [$this->upload($this->png(5, 5), 'a.png')], ['asset' => ['accept' => 'image/png']], true, null));
        $this->assertSame('', $field->resolve(null, null, [$this->upload($this->png(5, 5), 'a.png')], ['asset' => ['accept' => '.pdf']], true, null));
    }

    public function test_refused_uploads_are_reported(): void
    {
        $field = $this->field();
        $field->resolve(null, null, [$this->upload("notes\n", 'notes.txt'), $this->upload('<?php echo 1;', 'x.php')], self::SINGLE, true, null);

        $this->assertSame([
            'notes.txt: this field only accepts image/*.',
            'x.php: files of type text/x-php are not allowed.',
        ], $field->drainErrors());
        $this->assertSame([], $field->drainErrors());
    }

    public function test_a_refused_upload_is_discarded_without_asking_usage_sources(): void
    {
        // A source that reports every asset as used: delete() would refuse, discard() must not ask.
        $usage = new \Preflow\Media\Usage\AssetUsage();
        $usage->register(new class implements \Preflow\Media\Usage\AssetReferenceSource {
            public function name(): string
            {
                return 'everything';
            }

            public function usagesOf(string $assetId): array
            {
                return [new \Preflow\Media\Usage\AssetUsageEntry('Everywhere', null, 'everything')];
            }
        });
        $this->lib = $this->library(usage: $usage);

        $field = $this->field();
        $field->resolve(null, null, [$this->upload("notes\n", 'notes.txt')], self::SINGLE, true, null);

        $this->assertSame(['notes.txt: this field only accepts image/*.'], $field->drainErrors());
        $this->assertSame([], $this->lib->all());
        $this->assertSame([], glob($this->assetRoot . '/media/*/*/*') ?: []);
    }

    public function test_no_files_are_written_without_write_uploads(): void
    {
        $this->assertSame('', $this->field()->resolve(null, null, [$this->upload($this->png(5, 5), 'a.png')], self::SINGLE, false, null));
        $this->assertSame([], $this->lib->all());
    }

    public function test_editor_lists_selection_and_picker(): void
    {
        $a = $this->asset();

        $html = $this->field()->renderEditor(new FieldContext(name: 'cover', label: 'Cover', value: $a, config: self::SINGLE));

        $this->assertStringContainsString('<input type="hidden" name="cover_ids[]" value="">', $html);
        $this->assertStringContainsString('name="cover_ids[]" value="' . $a . '"', $html);
        $this->assertStringContainsString('data-folio-asset-choose', $html);
        $this->assertStringContainsString('data-picker-url="/folio/assets?picker=1&amp;multiple=0&amp;private=0"', $html);
        $this->assertStringContainsString('data-selection-name="cover_ids[]"', $html);
        $this->assertStringContainsString('src="' . $this->lib->thumbnailUrl($this->lib->find($a)) . '"', $html);
        $this->assertStringContainsString('data-folio-asset-remove', $html);
        $this->assertStringContainsString('type="file" name="cover" accept="image/*"', $html);
    }

    public function test_nested_selection_name(): void
    {
        $this->assertSame('a[b][cover_ids]', AssetFieldType::selectionName('a[b][cover]'));
        $this->assertSame('cover_ids', AssetFieldType::selectionName('cover'));
    }

    public function test_legacy_value_renders_in_editor_and_frontend(): void
    {
        $legacy = '2026/10/' . str_repeat('d', 32) . '.png';

        $editor = $this->field()->renderEditor(new FieldContext(name: 'cover', value: $legacy, config: self::SINGLE));
        $this->assertStringContainsString('src="/folio/_uploads/' . $legacy . '"', $editor);
        $this->assertStringContainsString('name="cover_ids[]" value="' . $legacy . '"', $editor);

        $this->assertStringContainsString('<img src="/folio/_uploads/' . $legacy . '"', $this->field()->renderFrontend($legacy, self::SINGLE));
    }

    public function test_admin_display_is_the_library_label_or_the_path(): void
    {
        $id = $this->asset();
        $this->assertSame('x.png', $this->field()->renderAdmin($id, self::SINGLE));
        $this->assertSame('a/&lt;b&gt;.png, c.pdf', $this->field()->renderAdmin(['a/<b>.png', 'c.pdf'], self::SINGLE));
        $this->assertSame('', $this->field()->renderAdmin(null, self::SINGLE));
    }

    public function test_a_binned_asset_stays_selected(): void
    {
        $this->lib = $this->library(['bin_dir' => $this->assetRoot . '/bin', 'private_dir' => $this->assetRoot . '/private']);
        $public = $this->asset();
        $private = $this->lib->upload($this->upload($this->png(10, 10), 'p.png'), null, private: true)->id;
        $this->lib->delete($public);
        $this->lib->delete($private);
        $field = $this->field();

        // Restoring it from the bin brings it back on the record, so a save keeps the id.
        $this->assertSame($public, $field->resolve(['', $public], null, [], self::SINGLE, true, null));
        $this->assertSame($private, $field->resolve(['', $private], null, [], ['asset' => ['multiple' => false, 'private' => true]], true, null));
        $this->assertSame([], $field->drainErrors());
    }

    public function test_editor_and_admin_show_a_binned_asset_as_in_the_bin(): void
    {
        $this->lib = $this->library(['bin_dir' => $this->assetRoot . '/bin']);
        $id = $this->asset();
        $this->lib->delete($id);

        $html = $this->field()->renderEditor(new FieldContext(name: 'cover', label: 'Cover', value: $id, config: self::SINGLE));

        $this->assertStringContainsString('<a href="/folio/assets/bin">x.png (in the bin)</a>', $html);
        $this->assertStringContainsString('name="cover_ids[]" value="' . $id . '"', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertSame('x.png (in the bin)', $this->field()->renderAdmin($id, self::SINGLE));
    }

    private function asset(): string
    {
        return $this->lib->upload($this->upload($this->png(10, 10), 'x.png'), null)->id;
    }

    private function field(): AssetFieldType
    {
        return new AssetFieldType($this->assetRoot . '/uploads', '/folio/_uploads', null, $this->lib, '/folio');
    }
}
