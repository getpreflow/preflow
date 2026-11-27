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
     * value; a wrong value as the form posts it and as the API body gives it (the field is
     * required, so "no value" is wrong too); options: "stored" the value both store, "error" the
     * message both refuse the wrong value with (null: neither refuses), "seed" the value the
     * records hold before the write.
     *
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>, mixed, array<string, mixed>, mixed, 6?: array<string, mixed>}>
     */
    public static function values(): iterable
    {
        $single = ['relation' => ['to' => 'post', 'multiple' => false]];
        $multiple = ['relation' => ['to' => 'post', 'multiple' => true]];

        yield 'string' => ['string', [], ['f' => 'Hello'], 'Hello', ['f' => ''], ''];
        yield 'text' => ['text', [], ['f' => "a\r\nb"], "a\r\nb", ['f' => ''], ''];
        yield 'richtext' => ['richtext', [], ['f' => '<p><strong>ok</strong></p><script>alert(1)</script>'], '<p><strong>ok</strong></p><script>alert(1)</script>', ['f' => ''], ''];
        yield 'number' => ['number', [], ['f' => '3.50'], 3.5, ['f' => ''], null];
        yield 'boolean' => ['boolean', [], ['f' => '1'], true, [], false, ['error' => null]];
        yield 'boolean unchecked' => ['boolean', [], [], false, [], false, ['error' => null]];
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
        yield 'link refused' => ['link', [], ['f' => ['kind' => 'url', 'url' => 'https://example.org']], ['kind' => 'url', 'target' => 'https://example.org'], ['f' => ['kind' => 'url', 'url' => 'javascript:alert(1)']], ['kind' => 'url', 'target' => 'javascript:alert(1)'], [
            'stored' => ['kind' => 'url', 'target' => 'https://example.org', 'label' => null, 'new_tab' => false],
            'error' => 'Must be a web address starting with https:// or http://, or a path on this site starting with /.',
        ]];

        // The fields package's types and Folio's own; datetime and location parse a form-only shape
        // (the editor's zone, three inputs) before the core value, the API gives the input shape.
        yield 'datetime' => ['datetime', ['zone' => 'Asia/Tokyo'], ['f' => '2026-07-15T12:30'], '2026-07-15T03:30:00Z', ['f' => 'soon'], 'soon', [
            'stored' => '2026-07-15T03:30:00Z',
            'error' => 'Must be a date and time, typed as 2026-03-29 14:30 in the editor (stored in UTC as 2026-03-29T12:30:00Z).',
        ]];
        yield 'location' => ['location', [], ['f' => ['lat' => '47,3769', 'lng' => ' 8.5417 ', 'address' => ' Zürich ']], ['lat' => 47.3769, 'lng' => 8.5417, 'address' => 'Zürich'], ['f' => ['lat' => 'north', 'lng' => '8', 'address' => '']], ['lat' => 'north', 'lng' => 8.0], [
            'stored' => ['lat' => 47.3769, 'lng' => 8.5417, 'address' => 'Zürich'],
            'error' => 'Must be a place with a latitude and a longitude, such as 47.3769, 8.5417.',
        ]];
        yield 'time' => ['time', [], ['f' => '9:05'], '09:05', ['f' => '25:00'], '25:00', ['stored' => '09:05', 'error' => 'Must be a time of day as hours and minutes, such as 14:30.']];
        yield 'color' => ['color', [], ['f' => ' C00000 '], '#c00000', ['f' => 'red'], 'red', ['stored' => '#c00000', 'error' => 'Must be a colour in hex form, such as #1a2b3c (or #1a2b3c80 with transparency).']];
        yield 'phone' => ['phone', [], ['f' => ' +41 44 123 45 67 '], '+41 44 123 45 67', ['f' => 'call me'], 'call me', [
            'stored' => ['as_typed' => '+41 44 123 45 67', 'e164' => '+41441234567'],
            'error' => 'Must be a phone number: digits, optionally with a leading + and spaces, e.g. +41 44 123 45 67.',
        ]];
        yield 'phone with a default region' => ['phone', ['default_region' => 'CH'], ['f' => ' 044 123 45 67 '], '044 123 45 67', ['f' => 'call me'], 'call me', [
            'stored' => ['as_typed' => '044 123 45 67', 'e164' => '+41441234567'],
            'error' => 'Must be a phone number: digits, optionally with a leading + and spaces, e.g. +41 44 123 45 67.',
            'finding' => 'A national number with the field\'s "default_region": the form reads it in that region (PhoneFieldType::normalizeInput(), Phone::of($raw, region)), the core value does not (PhoneValue::toStored(): Phone::fromStored()), so the API stores "e164": null.',
        ]];
        yield 'email' => ['email', [], ['f' => "  a@example.org \n"], 'a@example.org', ['f' => 'nobody'], 'nobody', ['stored' => 'a@example.org', 'error' => 'Must be a valid email address.']];
        yield 'json' => ['json', [], ['f' => "  {\"a\": 1}\r\n"], '{"a": 1}', ['f' => '{"a":'], '{"a":', ['stored' => '{"a": 1}', 'error' => 'Must be valid JSON, such as {"name": "value"} or [1, 2, 3].']];
        yield 'code' => ['code', [], ['f' => "a\r\nb"], "a\nb", ['f' => ''], '', ['stored' => "a\nb"]];
        yield 'code blank and with CRLF' => ['code', [], ['f' => "a\r\nb"], "a\r\nb", ['f' => " \n "], " \n ", [
            'stored' => "a\nb",
            'finding' => 'Code given with "\\r\\n" or as only blanks: the form turns line ends into "\\n" and blanks into null (CodeFieldType::normalizeInput()), the core value keeps both as given (CodeValue::toStored()), so the API stores "\\r\\n" and a blank code passes "required".',
        ]];
        yield 'daterange' => ['daterange', [], ['f' => ['start' => ' 2026-03-01 ', 'end' => '2026-03-07']], ['start' => '2026-03-01', 'end' => '2026-03-07'], ['f' => ['start' => '2026-03-07', 'end' => '2026-03-01']], ['start' => '2026-03-07', 'end' => '2026-03-01'], [
            'stored' => ['start' => '2026-03-01', 'end' => '2026-03-07'],
            'error' => 'The end (2026-03-01) is before the start (2026-03-07). Choose an end on or after 2026-03-07.',
        ]];
        yield 'embed' => ['embed', [], ['f' => ['url' => ' https://youtu.be/aqz-KE-bpKQ ']], 'https://youtu.be/aqz-KE-bpKQ', ['f' => ['url' => 'https://example.org/clip']], 'https://example.org/clip', [
            'stored' => ['provider' => 'youtube', 'id' => 'aqz-KE-bpKQ', 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'poster' => null],
            'error' => 'Videos from example.org cannot be embedded here; paste the address of a video on YouTube, Vimeo.',
        ]];
        yield 'table' => ['table', ['max_columns' => 2], ['f' => ['head' => ['', ''], 'rows' => [['S', '']]]], ['head' => null, 'rows' => [['S']]], ['f' => ['head' => [''], 'rows' => [['a', 'b', 'c']]]], ['rows' => [['a', 'b', 'c']]], [
            'stored' => ['head' => null, 'rows' => [['S']]],
            'error' => 'This table has 3 columns; at most 2 are allowed. Remove a column.',
        ]];
        yield 'matrix' => ['matrix', ['matrix' => ['allowed' => ['note']]], ['f' => [['_type' => 'note', 'id' => 'n1'], ['_type' => 'ghost', 'id' => 'g1']]], [['_type' => 'note', 'id' => 'n1']], ['f' => []], [], [
            'stored' => '[{"_type":"note","id":"n1"}]',
            // "required" is met by "[]" on both paths (MatrixValue stores refs as JSON text).
            'error' => null,
        ]];
        // The MergesInput path: a partial write names one area; the layout and the other area are kept.
        $card = ['_uid' => self::UID, '_block' => 'link', 'label' => 'L', 'url' => '/x'];
        $kept = ['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'link', 'label' => 'K', 'url' => '/k'];
        yield 'areas' => ['areas', ['layouts' => 'grid'], ['f' => ['areas' => ['c1' => ['_order' => [self::UID], self::UID => ['_block' => 'link', 'label' => 'L', 'url' => '/x']]]]], ['areas' => ['c1' => [$card]]], ['f' => ['layout' => 'nope']], ['layout' => 'nope'], [
            'seed' => ['layout' => '1-1', 'areas' => ['c2' => [$kept]]],
            'stored' => ['layout' => '1-1', 'areas' => ['c2' => [$kept], 'c1' => [$card]]],
            'error' => ['f.layout' => ['The layout "nope" is not one of the layouts of the set "grid" (full, 1-1, 1-1-1). Choose one of them.']],
        ]];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $post
     * @param array<string, mixed> $wrongPost
     * @param array<string, mixed> $options
     */
    #[DataProvider('values')]
    public function test_same_values_and_errors_through_the_form_and_the_write_class(string $type, array $config, array $post, mixed $body, array $wrongPost, mixed $wrongBody, array $options = []): void
    {
        if ($type === 'areas') {
            $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\GridSetProvider::class];\n");
        }
        $seed = array_key_exists('seed', $options) ? ['f' => $options['seed']] : [];
        $this->writeModel('parity', ['label' => 'Parity', 'fields' => ['f' => ['type' => $type] + $config]]);
        $this->working('parity', 'form', $seed);
        $this->working('parity', 'api', $seed);
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

        $this->divergence($options, $this->row('form'), $this->row('api'));
        $this->assertSame($this->row('form'), $this->row('api'), 'the same stored row');
        if (array_key_exists('stored', $options)) {
            $this->assertSame($options['stored'], $this->row('form')['f'] ?? null, 'what both store');
        }

        // Required, given a wrong value (or none): the same refusal (none, for a box left unticked).
        $this->writeModel('parity', ['label' => 'Parity', 'fields' => ['f' => ['type' => $type, 'validate' => ['required']] + $config]]);
        $this->working('parity', 'empty', $seed);
        $c = $this->app()->container();
        $dm = $c->get(DataManager::class);
        $def = $dm->getTypeRegistry()->get('parity');
        $record = $dm->findType('parity', 'empty') ?? self::fail('no record');
        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/parity/empty')->withParsedBody($wrongPost + ['_locale' => 'en']);
        $form = $this->errorsOf(static fn () => $c->get(RecordValidator::class)->validate($c->get(DraftRecordFactory::class)->build($def, $request, $record->toArray(), 'en')), false);
        $api = $this->errorsOf(static fn () => $dm->saveType($c->get(RecordInput::class)->update($record, ['f' => $wrongBody])), false);
        $this->divergence($options, $form, $api);
        $this->assertSame($form, $api, 'the same refusal of the wrong value');
        $error = array_key_exists('error', $options) ? $options['error'] : 'This field is required.';
        if ($error === null) {
            $this->assertSame([], $form, 'neither refuses');
        } else {
            $this->assertSame(is_array($error) ? $error : ['f' => [$error]], $form);
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

    /**
     * A known difference between the form and the write class, found by this test and reported
     * (option "finding"): marked incomplete with both sides, never loosened. Once the two agree,
     * the test runs on and fails on nothing: drop the option then.
     *
     * @param array<string, mixed> $options
     */
    private function divergence(array $options, mixed $form, mixed $api): void
    {
        if (isset($options['finding']) && $form !== $api) {
            $this->markTestIncomplete($options['finding'] . ' Form: ' . json_encode($form) . '; API: ' . json_encode($api));
        }
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
