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
}
