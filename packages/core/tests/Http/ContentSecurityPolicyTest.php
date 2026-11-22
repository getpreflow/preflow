<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\ContentSecurityPolicy;

final class ContentSecurityPolicyTest extends TestCase
{
    public function test_parses_directives_case_insensitively_and_keeps_the_first_occurrence(): void
    {
        $csp = ContentSecurityPolicy::parse("Default-Src 'self';  SCRIPT-SRC 'self' https://cdn.example ; script-src 'none';");

        $this->assertSame([['default-src' => ["'self'"], 'script-src' => ["'self'", 'https://cdn.example']]], $csp->policies());
        $this->assertSame(["'self'", 'https://cdn.example'], $csp->policies()[0]['script-src'], 'a repeated directive is ignored, as browsers do');
    }

    public function test_a_comma_joined_list_is_several_policies_and_trailing_commas_add_none(): void
    {
        $csp = ContentSecurityPolicy::parse("default-src 'self', frame-ancestors 'none',", "script-src 'nonce-abc',  ,");

        $this->assertSame([
            ['default-src' => ["'self'"]],
            ['frame-ancestors' => ["'none'"]],
            ['script-src' => ["'nonce-abc'"]],
        ], $csp->policies());
    }

    public function test_the_script_nonce_comes_from_script_src_elem_then_script_src_then_default_src(): void
    {
        $this->assertSame('elem', ContentSecurityPolicy::parse("default-src 'nonce-def'; script-src 'nonce-src'; script-src-elem 'nonce-elem'")->nonceFor('script'));
        $this->assertSame('src', ContentSecurityPolicy::parse("default-src 'nonce-def'; script-src 'nonce-src'")->nonceFor('script'));
        $this->assertSame('def', ContentSecurityPolicy::parse("default-src 'self' 'nonce-def'")->nonceFor('script'));
        $this->assertSame('sty', ContentSecurityPolicy::parse("script-src 'nonce-src'; style-src-elem 'nonce-sty'")->nonceFor('style'));
        $this->assertSame('css', ContentSecurityPolicy::parse("default-src 'nonce-def'; style-src 'nonce-css'")->nonceFor('style'));
    }

    /** The directive that governs scripts has no nonce: a nonce of default-src would not let a script run. */
    public function test_a_governing_directive_without_a_nonce_gives_none(): void
    {
        $this->assertNull(ContentSecurityPolicy::parse("default-src 'nonce-def'; script-src 'self'")->nonceFor('script'));
        $this->assertNull(ContentSecurityPolicy::parse("img-src 'self'")->nonceFor('script'));
        $this->assertNull(ContentSecurityPolicy::parse('')->nonceFor('script'));
    }

    public function test_the_nonce_keyword_matches_in_any_case(): void
    {
        $this->assertSame('AbC123', ContentSecurityPolicy::parse("script-src 'NONCE-AbC123'")->nonceFor('script'), 'the keyword in any case, the value as written');
    }

    public function test_a_policy_that_governs_no_scripts_leaves_the_nonce_to_the_others(): void
    {
        $this->assertSame('two', ContentSecurityPolicy::parse("img-src 'self', script-src 'nonce-two'")->nonceFor('script'));
    }

    /** Every policy of a list is enforced: an element needs a nonce each of them allows. */
    public function test_a_policy_whose_governing_directive_names_no_nonce_leaves_none(): void
    {
        $this->assertNull(ContentSecurityPolicy::parse("script-src 'nonce-one', script-src 'self'")->nonceFor('script'), 'the second policy allows no inline script');
        $this->assertNull(ContentSecurityPolicy::parse("default-src 'self'", "script-src 'nonce-one'")->nonceFor('script'), 'also across header values');
        $this->assertSame('one', ContentSecurityPolicy::parse("script-src 'nonce-one', default-src 'nonce-one'")->nonceFor('script'), 'a nonce every policy allows');
    }

    public function test_policies_naming_different_nonces_leave_none(): void
    {
        $this->assertNull(ContentSecurityPolicy::parse("script-src 'nonce-one', script-src 'nonce-two'")->nonceFor('script'));
        $this->assertNull(ContentSecurityPolicy::parse("style-src 'nonce-one'", "default-src 'nonce-two'")->nonceFor('style'));
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentSecurityPolicy::parse("default-src 'self'")->nonceFor('img');
    }

    public function test_of_a_response_the_enforced_policy_wins_over_report_only(): void
    {
        $both = new Response(200, [
            'Content-Security-Policy-Report-Only' => "script-src 'nonce-trial'",
            'Content-Security-Policy' => "script-src 'nonce-enforced'",
        ]);
        $this->assertSame('enforced', ContentSecurityPolicy::ofResponse($both)?->nonceFor('script'));

        $trial = new Response(200, ['Content-Security-Policy-Report-Only' => "script-src 'nonce-trial'"]);
        $this->assertSame('trial', ContentSecurityPolicy::ofResponse($trial)?->nonceFor('script'));

        $this->assertNull(ContentSecurityPolicy::ofResponse(new Response(200)), 'no policy');
    }
}
