<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/**
 * schema.org nodes for the JSON-LD graph. Nodes point at each other by
 * `@id`: the site is `{siteUrl}/#website`, its owner `{siteUrl}/#owner`,
 * a page `{url}#webpage`. Empty values are left out.
 */
final class Schema
{
    public static function webSite(string $url, string $name, string $inLanguage, ?string $publisherId = null): array
    {
        $base = rtrim($url, '/');

        return self::filter([
            '@type' => 'WebSite',
            '@id' => $base . '/#website',
            'url' => $base . '/',
            'name' => $name,
            'inLanguage' => $inLanguage,
            'publisher' => $publisherId === null ? null : ['@id' => $publisherId],
        ]);
    }

    /** @param array{type?: string, name: string, url?: ?string, logo?: ?string, same_as?: list<string>} $owner the `owner` config shape */
    public static function owner(array $owner, string $siteUrl): array
    {
        $person = ($owner['type'] ?? null) === 'Person';
        $logo = $owner['logo'] ?? null;

        return self::filter([
            '@type' => $person ? 'Person' : 'Organization',
            '@id' => rtrim($siteUrl, '/') . '/#owner',
            'name' => $owner['name'] ?? '',
            'url' => $owner['url'] ?? null,
            // A person has a picture, an organisation a logo.
            $person ? 'image' : 'logo' => $logo,
            'sameAs' => array_values($owner['same_as'] ?? []),
        ]);
    }

    /** @param ?array{url: string, width?: int, height?: int, alt?: string} $image */
    /** @param ?string $dateModified ISO 8601, when the page's content last changed (since 0.55) */
    public static function webPage(string $url, string $name, string $description, string $inLanguage, string $siteUrl, ?array $image = null, ?string $dateModified = null): array
    {
        return self::filter([
            '@type' => 'WebPage',
            '@id' => $url . '#webpage',
            'url' => $url,
            'name' => $name,
            'description' => $description,
            'inLanguage' => $inLanguage,
            'isPartOf' => ['@id' => rtrim($siteUrl, '/') . '/#website'],
            'primaryImageOfPage' => $image === null ? null : self::imageObject($image),
            'dateModified' => $dateModified,
        ]);
    }

    /** @param list<array{name: string, url: string}> $items from the home page down */
    public static function breadcrumbList(array $items): array
    {
        $elements = [];
        foreach (array_values($items) as $i => $item) {
            $elements[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $item['name'], 'item' => $item['url']];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $elements];
    }

    /** @param ?array{url: string, width?: int, height?: int, alt?: string} $image */
    public static function article(
        string $url,
        string $headline,
        string $description,
        string $inLanguage,
        ?string $datePublished = null,
        ?array $image = null,
        ?string $authorId = null,
    ): array {
        return self::filter([
            '@type' => 'Article',
            '@id' => $url . '#article',
            'headline' => $headline,
            'description' => $description,
            'inLanguage' => $inLanguage,
            'mainEntityOfPage' => ['@id' => $url . '#webpage'],
            'datePublished' => $datePublished,
            'image' => $image === null ? null : self::imageObject($image),
            'author' => $authorId === null ? null : ['@id' => $authorId],
        ]);
    }

    /** @param array{url: string, width?: int, height?: int, alt?: string} $image MediaLibrary::shareImage() shape */
    public static function imageObject(array $image): array
    {
        return self::filter([
            '@type' => 'ImageObject',
            'url' => $image['url'],
            'width' => $image['width'] ?? null,
            'height' => $image['height'] ?? null,
            'caption' => $image['alt'] ?? null,
        ]);
    }

    private static function filter(array $node): array
    {
        return array_filter($node, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
