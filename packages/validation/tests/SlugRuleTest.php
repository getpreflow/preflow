<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\Rules\Slug;
use Preflow\Validation\ValidationContext;

final class SlugRuleTest extends TestCase
{
    private function check(mixed $value, bool $path = false): true|Message
    {
        return (new Slug($path ? 'path' : null))->validate($value, new ValidationContext('f', []));
    }

    public function test_accepts_lowercase_ascii_words_joined_by_hyphens(): void
    {
        foreach (['about', 'ueber-uns', 'a', '2024', 'top-10-games', 2024] as $value) {
            $this->assertTrue($this->check($value), var_export($value, true));
        }
    }

    public function test_empty_values_pass(): void
    {
        $this->assertTrue($this->check(null));
        $this->assertTrue($this->check(''));
    }

    public function test_refuses_umlauts_capitals_spaces_and_stray_hyphens(): void
    {
        foreach (['über-uns', 'Ueber-uns', 'ueber uns', 'ueber_uns', '-ueber', 'ueber-', 'ueber--uns', 'a/b', 'café', 'stra%C3%9Fe', 'a.html'] as $value) {
            $message = $this->check($value);
            $this->assertInstanceOf(Message::class, $message, $value);
            $this->assertSame('validation.slug.segment', $message->key);
        }
    }

    public function test_path_mode_allows_slashes_between_parts(): void
    {
        $this->assertTrue($this->check('docs/getting-started', true));
        $this->assertTrue($this->check('a/b/c', true));
        foreach (['/docs', 'docs/', 'docs//start', 'docs/Start', 'dokumente/über'] as $value) {
            $message = $this->check($value, true);
            $this->assertInstanceOf(Message::class, $message, $value);
            $this->assertSame('validation.slug.path', $message->key);
        }
    }

    public function test_refuses_non_scalar_values(): void
    {
        $this->assertInstanceOf(Message::class, $this->check(['a']));
    }

    public function test_alias(): void
    {
        $factory = new RuleFactory();
        $this->assertInstanceOf(Message::class, $factory->resolve('slug')->validate('a/b', new ValidationContext('f', [])));
        $this->assertTrue($factory->resolve('slug:path')->validate('a/b', new ValidationContext('f', [])));
    }

    public function test_an_unknown_mode_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Slug('paths');
    }
}
