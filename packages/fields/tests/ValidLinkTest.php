<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Fields\LinkOptions;
use Preflow\Fields\Rules\ValidLink;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidatorFactory;

/** The link field's check (0.91): a value of an offered kind, with a target that is valid for it. */
final class ValidLinkTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function check(mixed $value, array $config = []): true|Message
    {
        return (new ValidLink(LinkOptions::fromConfig($config)))->validate($value, new ValidationContext('cta', []));
    }

    public function test_valid_links_of_every_kind_pass(): void
    {
        foreach ([
            ['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'p1']],
            ['kind' => 'url', 'target' => 'https://example.org/a'],
            ['kind' => 'url', 'target' => '/contact'],
            ['kind' => 'email', 'target' => 'team@example.org'],
            ['kind' => 'phone', 'target' => '+41 44 123 45 67'],
            ['kind' => 'asset', 'target' => 'a0f3', 'label' => ['en' => 'Prices'], 'new_tab' => true],
            '{"kind":"url","target":"https://example.org"}',
        ] as $value) {
            $this->assertTrue($this->check($value), json_encode($value));
        }
    }

    public function test_an_empty_link_passes_so_required_decides(): void
    {
        $this->assertTrue($this->check(null));
        $this->assertTrue($this->check(''));
        $this->assertTrue($this->check([]));
    }

    public function test_invalid_url_email_and_phone_are_refused_with_what_to_do_instead(): void
    {
        $this->assertSame('validation.link.url', $this->key(['kind' => 'url', 'target' => 'javascript:alert(1)']));
        $this->assertSame('validation.link.url', $this->key(['kind' => 'url', 'target' => 'example.org']));
        $this->assertSame('validation.email', $this->key(['kind' => 'email', 'target' => 'no address']));
        $this->assertSame('validation.phone', $this->key(['kind' => 'phone', 'target' => 'call me']));
        $this->assertSame(
            'Must be a web address starting with https:// or http://, or a path on this site starting with /.',
            (string) $this->check(['kind' => 'url', 'target' => 'example.org']),
        );
    }

    public function test_a_link_without_a_kind_or_target_names_what_is_missing(): void
    {
        $this->assertSame('validation.link.kind', $this->key(['target' => 'https://example.org']));
        $this->assertSame('validation.link.kind', $this->key('https://example.org'), 'text is no link');
        $this->assertSame('validation.link.target', $this->key(['kind' => 'url', 'target' => '']));
        $this->assertSame('validation.link.target', $this->key(['kind' => 'record', 'target' => ['type' => 'page']]));
        $this->assertSame('Choose what the link points to: record, url, email, phone, asset.', (string) $this->check(['kind' => 'ftp', 'target' => 'x']));
    }

    public function test_the_fields_kinds_and_types_limit_what_it_takes(): void
    {
        $config = ['link' => ['kinds' => ['record', 'url'], 'types' => ['page', 'post']]];

        $this->assertTrue($this->check(['kind' => 'record', 'target' => ['type' => 'post', 'id' => 'x']], $config));
        $refused = $this->check(['kind' => 'email', 'target' => 'team@example.org'], $config);
        $this->assertSame('Choose what the link points to: record, url.', (string) $refused);
        $type = $this->check(['kind' => 'record', 'target' => ['type' => 'author', 'id' => 'a1']], $config);
        $this->assertSame('This link cannot point to a record of type "author"; choose one of: page, post.', (string) $type);
    }

    public function test_messages_are_keyed_by_the_field_and_translated(): void
    {
        $factory = new ValidatorFactory(new RuleFactory());
        $result = $factory->make(['cta' => [new ValidLink(LinkOptions::fromConfig([]))]], ['cta' => ['kind' => 'email', 'target' => 'x']])->validate();

        $this->assertSame(['cta' => ['Must be a valid email address.']], $result->errors());
    }

    public function test_config_mistakes_name_the_key_and_what_to_write(): void
    {
        foreach ([
            [['link' => 'page'], '"link" must be an object such as {"types": ["page"]}'],
            [['link' => ['kinds' => ['url', 'ftp']]], '"link.kinds" lists "ftp"; use record, url, email, phone or asset'],
            [['link' => ['kinds' => []]], '"link.kinds" lists no kind; leave it out to offer every kind'],
            [['link' => ['types' => 'page']], '"link.types" must be a list of type keys, e.g. ["page"]'],
            [['link' => ['types' => ['page', '']]], '"link.types" must be a list of type keys, e.g. ["page"]'],
            [['link' => ['kinds' => ['record'], 'types' => []]], '"link.types" lists no type; name the types a record link may point to'],
            [['link' => ['new_tab' => 'yes']], '"link" takes "kinds" and "types"; "new_tab" is not one'],
            [['translatable' => true], 'A link field cannot be translatable; its label is translated in the link itself'],
        ] as [$config, $message]) {
            try {
                LinkOptions::check($config);
                $this->fail('accepted ' . json_encode($config));
            } catch (\LogicException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        LinkOptions::check(['link' => ['kinds' => ['url'], 'types' => ['page']]]);
        LinkOptions::check([]);
        $this->addToAssertionCount(1);
    }

    private function key(mixed $value): ?string
    {
        $result = $this->check($value);

        return $result instanceof Message ? $result->key : null;
    }
}
