<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Records\RecordInput;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Media\MediaLibrary;
use Preflow\Validation\ValidationException;

/**
 * No second set of rules (0.93): Folio's form and the one write class (RecordInput, then
 * DataManager::saveType()) are two adapters of the same core. For each of Folio's core field
 * types the form's post and the API body of the same value store the same row, and the same wrong
 * values are refused with the same keys and messages.
 */
final class ApiFormParityTest extends TestCase
{
    use PublishingScaffold;

    private const string UID = 'aaaaaaaaaaaaaaaa';

    /** Stands for the id of an asset the test imports into the media library. */
    private const string ASSET = '{asset}';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        // One language: a link's label is one text, as the API body gives it.
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\LinkBlock::class]];\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /**
     * Folio type key, field config, the form's post (its field names => values), the API body's
     * value; the form's post and the API body's value of no value (refused as the field is required).
     *
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>, mixed, array<string, mixed>, mixed}>
     */
    public static function values(): iterable
    {
        $single = ['relation' => ['to' => 'post', 'multiple' => false]];
        $multiple = ['relation' => ['to' => 'post', 'multiple' => true]];

        yield 'string' => ['string', [], ['f' => 'Hello'], 'Hello', ['f' => ''], ''];
        yield 'text' => ['text', [], ['f' => "a\r\nb"], "a\r\nb", ['f' => ''], ''];
        yield 'richtext' => ['richtext', [], ['f' => '<p><strong>ok</strong></p><script>alert(1)</script>'], '<p><strong>ok</strong></p><script>alert(1)</script>', ['f' => ''], ''];
        yield 'number' => ['number', [], ['f' => '3.50'], 3.5, ['f' => ''], null];
        yield 'boolean' => ['boolean', [], ['f' => '1'], true, [], false];
        yield 'boolean unchecked' => ['boolean', [], [], false, [], false];
        yield 'date' => ['date', [], ['f' => '2026-11-27'], '2026-11-27', ['f' => ''], null];
        yield 'date with time' => ['date', ['time' => true], ['f' => '2026-11-27T09:30'], '2026-11-27T09:30', ['f' => ''], null];
        yield 'select' => ['select', ['options' => ['a', 'b']], ['f' => 'b'], 'b', ['f' => ''], null];
        yield 'tags' => ['tags', [], ['f' => 'red, green,, red'], ['red', 'green'], ['f' => ''], []];
        yield 'relation' => ['relation', $single, ['f' => 'p1'], 'p1', ['f' => ''], null];
        yield 'relation multiple' => ['relation', $multiple, ['f' => ['', 'p2', 'p1']], ['p2', 'p1'], ['f' => ['']], []];
        yield 'asset' => ['asset', [], ['f_ids' => [self::ASSET]], self::ASSET, ['f_ids' => []], null];
        yield 'asset multiple' => ['asset', ['asset' => ['multiple' => true]], ['f_ids' => [self::ASSET]], [self::ASSET], ['f_ids' => []], []];
        yield 'blocks' => ['blocks', [], ['f' => ['_order' => [self::UID], self::UID => ['_block' => 'link', 'label' => 'L', 'url' => '/x']]], [['_uid' => self::UID, '_block' => 'link', 'label' => 'L', 'url' => '/x']], ['f' => []], []];
        yield 'link' => ['link', [], ['f' => ['kind' => 'url', 'url' => 'https://example.org', 'label' => 'Example']], ['kind' => 'url', 'target' => 'https://example.org', 'label' => 'Example'], ['f' => ['kind' => '']], null];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $post
     * @param array<string, mixed> $emptyPost
     */
    #[DataProvider('values')]
    public function test_same_values_and_errors_through_the_form_and_the_write_class(string $type, array $config, array $post, mixed $body, array $emptyPost, mixed $emptyBody): void
    {
        $this->writeModel('parity', ['label' => 'Parity', 'fields' => ['f' => ['type' => $type] + $config]]);
        $this->working('parity', 'form', []);
        $this->working('parity', 'api', []);
        if (str_contains((string) json_encode([$post, $body]), self::ASSET)) {
            $file = $this->dir . '/red.png';
            file_put_contents($file, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
            $asset = $this->app()->container()->get(MediaLibrary::class)->importFile($file, 'red.png', null)->id;
            $post = self::withAsset($post, $asset);
            $body = self::withAsset($body, $asset);
        }

        $response = $this->post('/folio/parity/form', $post + ['_locale' => 'en']);
        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        $app = $this->app();
        $dm = $app->container()->get(DataManager::class);
        $dm->saveType($app->container()->get(RecordInput::class)->update($dm->findType('parity', 'api') ?? self::fail('no record'), ['f' => $body]));

        $this->assertSame($this->row('form'), $this->row('api'), 'the same stored row');

        // Required, given no value: the same refusal (or none, for a box left unticked).
        $this->writeModel('parity', ['label' => 'Parity', 'fields' => ['f' => ['type' => $type, 'validate' => ['required']] + $config]]);
        $this->working('parity', 'empty', []);
        $c = $this->app()->container();
        $dm = $c->get(DataManager::class);
        $def = $dm->getTypeRegistry()->get('parity');
        $record = $dm->findType('parity', 'empty') ?? self::fail('no record');
        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/parity/empty')->withParsedBody($emptyPost + ['_locale' => 'en']);
        $form = $this->errorsOf(static fn () => $c->get(RecordValidator::class)->validate($c->get(DraftRecordFactory::class)->build($def, $request, $record->toArray(), 'en')), false);
        $api = $this->errorsOf(static fn () => $dm->saveType($c->get(RecordInput::class)->update($record, ['f' => $emptyBody])), false);
        $this->assertSame($form, $api, 'the same refusal of no value');
        if ($type !== 'boolean') {
            $this->assertSame(['f' => ['This field is required.']], $form);
        }
    }

    public function test_same_errors_through_the_form_and_the_write_class(): void
    {
        $this->writeModel('checked', ['label' => 'Checked', 'fields' => [
            'title' => ['type' => 'string', 'validate' => ['required']],
            'day' => ['type' => 'date'],
            'kind' => ['type' => 'select', 'options' => ['a', 'b']],
            'people' => ['type' => 'relation', 'relation' => ['to' => 'post', 'multiple' => true], 'validate' => ['required']],
            'target' => ['type' => 'link'],
        ]]);
        $this->working('checked', 'c1', ['title' => 'Kept']);
        $post = ['title' => '', 'day' => '26.11.2026', 'kind' => 'c', 'people' => [''], 'target' => ['kind' => 'url', 'url' => 'javascript:alert(1)']];
        $body = ['title' => '', 'day' => '26.11.2026', 'kind' => 'c', 'people' => [], 'target' => ['kind' => 'url', 'target' => 'javascript:alert(1)']];

        $app = $this->app();
        $c = $app->container();
        $dm = $c->get(DataManager::class);
        $def = $dm->getTypeRegistry()->get('checked');
        $stored = $dm->findType('checked', 'c1') ?? self::fail('no record');

        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/checked/c1')->withParsedBody($post + ['_locale' => 'en']);
        $form = $this->errorsOf(static fn () => $c->get(RecordValidator::class)->validate($c->get(DraftRecordFactory::class)->build($def, $request, $stored->toArray(), 'en')));
        $api = $this->errorsOf(static fn () => $dm->saveType($c->get(RecordInput::class)->update($stored, $body)));

        ksort($form);
        ksort($api);
        $this->assertSame(['day', 'kind', 'people', 'target', 'title'], array_keys($form), 'every wrong value refused by the form');
        $this->assertSame($form, $api, 'the same keys and messages');
        $this->assertSame(422, $this->post('/folio/checked/c1', $post + ['_locale' => 'en'])->getStatusCode(), 'the form\'s save refuses it');
        $this->assertSame('Kept', $this->stored('checked', 'c1')->get('title'));
    }

    /** @return array<string, list<string>> what $write was refused with ([] when $refused is false and it was not) */
    private function errorsOf(\Closure $write, bool $refused = true): array
    {
        try {
            $write();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        if ($refused) {
            self::fail('not refused');
        }

        return [];
    }

    /** @return array<string, mixed> the stored row as the driver holds it, without its id and change date */
    private function row(string $id): array
    {
        $row = $this->dm()->driver('json')->findOne('parity', $id, 'uuid') ?? self::fail("no row {$id}");
        unset($row['uuid'], $row['_updated_at']);

        return $row;
    }

    private static function withAsset(mixed $value, string $asset): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $one): mixed => self::withAsset($one, $asset), $value);
        }

        return $value === self::ASSET ? $asset : $value;
    }
}
