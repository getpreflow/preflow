<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

/**
 * On top of PublishingScaffold: the layouts "landing" (hero, main), "standard" (main) and "home"
 * (top), and a "page" type opting in ("allowed" landing and standard, default standard, the page
 * "home" pinned to "home"), routed at /p/{slug}, with the layouts' templates. The scaffold's "post"
 * type stays without "layouts".
 */
trait PageLayoutsScaffold
{
    use PublishingScaffold;

    private const string HERO = 'a1a1a1a1a1a1a1a1';
    private const string PROSE = 'b2b2b2b2b2b2b2b2';
    private const string OTHER = 'c3c3c3c3c3c3c3c3';

    private function scaffoldLayouts(string $storage = 'json', bool $publishing = false): void
    {
        $this->scaffoldPublishing($storage);
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => ["
            . "\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\HeroBlock\\HeroBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\ProseBlock\\ProseBlock::class, "
            . "\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\QuoteBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\GalleryBlock::class, "
            . "\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\LinkBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\TradeoffNoteBlock::class], "
            . "'layouts' => [\\Preflow\\Folio\\Tests\\Fixtures\\Layouts\\Landing::class, \\Preflow\\Folio\\Tests\\Fixtures\\Layouts\\Standard::class, \\Preflow\\Folio\\Tests\\Fixtures\\Layouts\\Home::class]];\n");
        $this->writeModel('page', [
            'label' => 'Pages',
            'publishing' => $publishing,
            'frontend' => ['route' => '/p/{slug}'],
            'layouts' => ['allowed' => ['landing', 'standard'], 'default' => 'standard', 'pinned' => ['home' => 'home']],
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true, 'validate' => ['required']],
                'slug' => ['type' => 'string', 'validate' => ['required']],
                'status' => ['type' => 'string'],
            ],
        ]);
        $this->write('layouts/landing.twig', '<html><body><main class="landing" data-layout="{{ layout.key }}">'
            . '<div class="hero">{{ blocks(areas.hero, preview|default(false)) }}</div><div class="main">{{ blocks(areas.main, preview|default(false)) }}</div></main></body></html>');
        $this->write('layouts/standard.twig', '<html><body><main class="standard" data-layout="{{ layout.key }}">'
            . '<div class="main">{{ blocks(areas.main, preview|default(false)) }}</div></main></body></html>');
        $this->write('layouts/home.twig', '<html><body><main class="home" data-layout="{{ layout.key }}" data-pinned="{{ layout.pinned ? 1 : 0 }}">'
            . '<div class="top">{{ blocks(areas.top) }}</div></main></body></html>');
    }

    /** @return array<string, mixed> a hero block */
    private static function hero(string $uid, string $headline): array
    {
        return ['_uid' => $uid, '_block' => 'hero', 'headline' => ['en' => $headline], 'intro' => ['en' => ''], 'tradeoff' => []];
    }

    /**
     * @param array<string, string> $caption by locale
     * @return array<string, mixed> a prose block
     */
    private static function prose(string $uid, array $caption): array
    {
        return ['_uid' => $uid, '_block' => 'prose', 'body' => ['en' => ''], 'caption' => $caption, 'quotes' => []];
    }

    /**
     * A stored page, as given (no rules).
     *
     * @param array<string, list<array<string, mixed>>> $areas
     * @param array<string, mixed>                      $more
     */
    private function page(string $id, string $slug, ?string $layout, array $areas, array $more = []): void
    {
        $this->working('page', $id, ['title' => ['en' => ucfirst($slug)], 'slug' => $slug, 'status' => 'published']
            + ($layout !== null ? ['_layout' => $layout] : []) + ['_areas' => $areas] + $more);
    }
}
