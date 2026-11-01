<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Seo\SecurityTxt;

final class SecurityTxtTest extends TestCase
{
    public function test_renders_with_policy_and_bare_email(): void
    {
        $now = new \DateTimeImmutable('2026-11-01 12:00:00', new \DateTimeZone('UTC'));

        $this->assertSame(
            "Contact: mailto:security@example.com\nExpires: 2027-04-30T12:00:00Z\nPreferred-Languages: en, de\n"
            . "Canonical: https://example.com/.well-known/security.txt\nPolicy: https://example.com/security\n",
            SecurityTxt::render('security@example.com', 'https://example.com/security', ['en', 'de'], 'https://example.com/', $now),
        );
    }

    public function test_url_contact_kept_and_no_policy_line(): void
    {
        $now = new \DateTimeImmutable('2026-11-01T12:00:00+02:00');
        $text = SecurityTxt::render('https://example.com/contact', null, [], 'https://example.com', $now);

        $this->assertStringStartsWith("Contact: https://example.com/contact\nExpires: 2027-04-30T10:00:00Z\n", $text);
        $this->assertStringNotContainsString('Policy:', $text);
    }

    public function test_preferred_languages_follow_the_given_locales(): void
    {
        $now = new \DateTimeImmutable('2026-11-01 12:00:00', new \DateTimeZone('UTC'));

        $this->assertStringContainsString("Expires: 2027-04-30T12:00:00Z\nPreferred-Languages: fr, it\nCanonical:", SecurityTxt::render('a@b.c', null, ['fr', 'it'], 'https://x.y', $now));
        $this->assertStringNotContainsString('Preferred-Languages', SecurityTxt::render('a@b.c', null, [], 'https://x.y', $now));
    }
}
