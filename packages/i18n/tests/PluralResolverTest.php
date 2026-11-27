<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\PluralResolver;

final class PluralResolverTest extends TestCase
{
    private PluralResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PluralResolver();
    }

    public function test_exact_match_zero(): void
    {
        $result = $this->resolver->resolve(
            '{0} No posts|{1} One post|[2,*] :count posts',
            0
        );

        $this->assertSame('No posts', $result);
    }

    public function test_exact_match_one(): void
    {
        $result = $this->resolver->resolve(
            '{0} No posts|{1} One post|[2,*] :count posts',
            1
        );

        $this->assertSame('One post', $result);
    }

    public function test_range_match(): void
    {
        $result = $this->resolver->resolve(
            '{0} No posts|{1} One post|[2,*] :count posts',
            5
        );

        $this->assertSame(':count posts', $result);
    }

    public function test_range_with_upper_bound(): void
    {
        $result = $this->resolver->resolve(
            '[0,1] Few|[2,10] Some|[11,*] Many',
            5
        );

        $this->assertSame('Some', $result);
    }

    public function test_simple_pipe_two_forms(): void
    {
        $result = $this->resolver->resolve('One item|:count items', 1);
        $this->assertSame('One item', $result);

        $result2 = $this->resolver->resolve('One item|:count items', 5);
        $this->assertSame(':count items', $result2);
    }

    public function test_no_plural_returns_string(): void
    {
        $result = $this->resolver->resolve('Hello World', 1);

        $this->assertSame('Hello World', $result);
    }

    public function test_range_boundary_inclusive(): void
    {
        $result = $this->resolver->resolve('[2,10] In range|[11,*] Out', 10);
        $this->assertSame('In range', $result);

        $result2 = $this->resolver->resolve('[2,10] In range|[11,*] Out', 11);
        $this->assertSame('Out', $result2);
    }

    public function test_zero_with_simple_forms(): void
    {
        $result = $this->resolver->resolve('One|Many', 0);

        // 0 uses the second form (plural)
        $this->assertSame('Many', $result);
    }

    /** @since 0.92.1 three plain forms by the language's rule: one|few|many. */
    public function test_three_forms_by_the_language(): void
    {
        $resolver = new PluralResolver();
        $text = 'one|few|many';

        foreach ([1 => 'one', 21 => 'one', 101 => 'one', 2 => 'few', 24 => 'few', 0 => 'many', 5 => 'many', 11 => 'many', 12 => 'many', 111 => 'many'] as $n => $form) {
            $this->assertSame($form, $resolver->resolve($text, $n, 'ru'), 'ru ' . $n);
        }
        foreach ([1 => 'one', 21 => 'many', 2 => 'few', 22 => 'few', 12 => 'many', 5 => 'many'] as $n => $form) {
            $this->assertSame($form, $resolver->resolve($text, $n, 'pl_PL'), 'pl ' . $n);
        }
        foreach ([1 => 'one', 3 => 'few', 22 => 'many'] as $n => $form) {
            $this->assertSame($form, $resolver->resolve($text, $n, 'cs'), 'cs ' . $n);
        }
        $this->assertSame('few', $resolver->resolve($text, 2, 'en'), 'a language with two forms: 1 against the rest');
        $this->assertSame('few', $resolver->resolve($text, 21, null));
        $this->assertSame('items', $resolver->resolve('item|items', 21, 'ru'), 'two forms keep their rule');
    }
}
