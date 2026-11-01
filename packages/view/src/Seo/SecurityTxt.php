<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/** /.well-known/security.txt (RFC 9116). */
final class SecurityTxt
{
    /** @param list<string> $locales reserved: Preferred-Languages is fixed at "en, de" for now */
    public static function render(string $contact, ?string $policy, array $locales, string $origin, \DateTimeImmutable $now): string
    {
        $contact = preg_match('#^[a-z][a-z0-9+.-]*:#i', $contact) === 1 ? $contact : 'mailto:' . $contact;
        $expires = $now->setTimezone(new \DateTimeZone('UTC'))->modify('+180 days')->format('Y-m-d\TH:i:s\Z');

        $lines = [
            'Contact: ' . $contact,
            'Expires: ' . $expires,
            'Preferred-Languages: en, de',
            'Canonical: ' . rtrim($origin, '/') . '/.well-known/security.txt',
        ];
        if ($policy !== null && $policy !== '') {
            $lines[] = 'Policy: ' . $policy;
        }

        return implode("\n", $lines) . "\n";
    }
}
