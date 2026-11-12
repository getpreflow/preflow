<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect;

use PHPUnit\Framework\TestCase;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\NotFoundList;
use Preflow\Routing\Redirect\PageExists;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectRules;
use Preflow\Routing\Redirect\SiteAddresses;
use Preflow\Routing\Redirect\TargetIsLive;
use Preflow\Routing\Tests\Redirect\Fixtures\InMemoryRedirectStore;

final class RedirectRulesTest extends TestCase
{
    /** @var list<string> */
    private array $pages = [];

    /** @var list<string> "type:id" of the live records */
    private array $live = ['post:1'];

    /** @var list<string> */
    private array $forgotten = [];

    /** @param list<Redirect> $entries */
    private function rules(array $entries = []): RedirectRules
    {
        $pages = $this->pages;
        $live = $this->live;
        $forgotten = &$this->forgotten;

        return new RedirectRules(
            new InMemoryRedirectStore($entries),
            new class ($pages) implements PageExists {
                /** @param list<string> $pages */
                public function __construct(private readonly array $pages) {}

                public function exists(string $locale, string $path): bool
                {
                    return in_array($path, $this->pages, true);
                }
            },
            new class ($live) implements TargetIsLive {
                /** @param list<string> $live */
                public function __construct(private readonly array $live) {}

                public function isLive(string $type, string $id): bool
                {
                    return in_array($type . ':' . $id, $this->live, true);
                }
            },
            new class implements SiteAddresses {
                public function address(string $url, string $host = ''): ?array
                {
                    $path = str_starts_with($url, '/') ? parse_url($url, PHP_URL_PATH) : (parse_url($url, PHP_URL_HOST) === 'example.test' ? parse_url($url, PHP_URL_PATH) : null);

                    return is_string($path) ? ['', $path] : null;
                }

                public function lookupLocale(string $locale): string
                {
                    return $locale !== '' ? $locale : 'en';
                }
            },
            new class ($forgotten) implements NotFoundList {
                /** @param list<string> $forgotten */
                public function __construct(private array &$forgotten) {}

                public function forget(string $locale, string $path): void
                {
                    $this->forgotten[] = "one:{$path}";
                }

                public function forgetUnder(string $locale, string $area): void
                {
                    $this->forgotten[] = "area:{$area}";
                }
            },
        );
    }

    private static function exact(string $path, ?string $url = null, string $locale = ''): Redirect
    {
        return new Redirect(Redirect::id('exact', $locale, $path), $path, $locale, false, AreaMode::One, null, null, $url, 301);
    }

    private static function area(string $path, ?string $url = null, AreaMode $mode = AreaMode::One): Redirect
    {
        return new Redirect(Redirect::id('area', '', $path), $path, '', true, $mode, null, null, $url, 301);
    }

    /** @return array<string, mixed> */
    private static function entry(string $path, string $target = '/new', bool $area = false, string $mode = 'one'): array
    {
        return ['path' => $path, 'locale' => '', 'area' => $area, 'mode' => $mode, 'target_url' => $target];
    }

    public function test_a_plain_entry_is_accepted(): void
    {
        self::assertTrue($this->rules()->check(self::entry('/old'), null)->isEmpty());
    }

    public function test_an_address_must_be_in_normal_form(): void
    {
        self::assertSame('address_invalid', $this->rules()->check(self::entry('/old/'), null)->get('address'));
        self::assertSame('address_invalid', $this->rules()->check(self::entry(''), null)->get('address'));
    }

    public function test_no_area_over_the_whole_site(): void
    {
        self::assertSame('whole_site', $this->rules()->check(self::entry('/', '/new', true), null)->get('address'));
    }

    public function test_a_page_wins_over_an_exact_entry_but_not_over_an_area(): void
    {
        $this->pages = ['/about'];

        self::assertSame('page_wins', $this->rules()->check(self::entry('/about'), null)->get('address'));
        self::assertTrue($this->rules()->check(self::entry('/about', '/new', true), null)->isEmpty());
    }

    public function test_an_address_another_entry_holds_is_taken_unless_it_is_the_one_edited(): void
    {
        $taken = self::exact('/old', '/x');
        $rules = $this->rules([$taken]);

        self::assertSame('taken', $rules->check(self::entry('/old'), null)->get('address'));
        self::assertTrue($rules->check(self::entry('/old'), ['id' => $taken->id])->isEmpty());
    }

    public function test_no_chains_an_address_other_entries_send_visitors_to_is_refused(): void
    {
        $rules = $this->rules([self::exact('/a', '/b')]);

        self::assertSame('targeted', $rules->check(self::entry('/b', '/c'), null)->get('address'));
        // Where a page answers the entry above never sends on.
        $this->pages = ['/b'];
        self::assertNull($this->rules([self::exact('/a', '/b')])->address(self::entry('/b', '/c', true), null)->get('address'));
    }

    public function test_keep_the_rest_covers_everything_below_its_target(): void
    {
        $rules = $this->rules([self::area('/docs', '/guide', AreaMode::Prefix)]);

        self::assertSame('targeted', $rules->check(self::entry('/guide/intro', '/x'), null)->get('address'));
        self::assertSame('targeted', $rules->check(self::entry('/guide/sub', '/x', true), null)->get('address'));
        // An area being saved that holds the target counts too.
        self::assertSame('targeted', $rules->check(self::entry('/guide', '/x', true), null)->get('address'));
    }

    public function test_a_target_is_needed_and_must_be_a_url_or_a_live_record(): void
    {
        $rules = $this->rules();

        self::assertSame('target_required', $rules->check(['path' => '/old'], null)->get('target'));
        self::assertSame('target_invalid', $rules->check(self::entry('/old', '//evil.test'), null)->get('target'));
        self::assertSame('target_invalid', $rules->check(self::entry('/old', 'javascript:alert(1)'), null)->get('target'));
        self::assertTrue($rules->check(['path' => '/old', 'target_type' => 'post', 'target_id' => '1'], null)->isEmpty());
        self::assertSame('target_not_live', $rules->check(['path' => '/old', 'target_type' => 'post', 'target_id' => '2'], null)->get('target'));
    }

    public function test_keep_the_rest_needs_a_url_without_query(): void
    {
        $rules = $this->rules();

        self::assertSame('prefix_needs_url', $rules->check(['path' => '/old', 'area' => true, 'mode' => 'prefix', 'target_type' => 'post', 'target_id' => '1'], null)->get('target'));
        self::assertSame('prefix_no_query', $rules->check(self::entry('/old', '/new?x=1', true, 'prefix'), null)->get('target'));
        self::assertTrue($rules->check(self::entry('/old', '/new', true, 'prefix'), null)->isEmpty());
    }

    public function test_a_target_is_not_the_address_nor_inside_its_own_area(): void
    {
        $rules = $this->rules();

        self::assertSame('target_is_address', $rules->check(self::entry('/old', '/old'), null)->get('target'));
        self::assertSame('target_in_area', $rules->check(self::entry('/blog', '/blog/archive', true), null)->get('target'));
        self::assertTrue($rules->check(self::entry('/blog', '/blogger', true), null)->isEmpty());
        self::assertTrue($rules->check(self::entry('/old', 'https://other.test/old'), null)->isEmpty());
    }

    public function test_a_target_another_entry_redirects_is_refused_unless_a_page_answers_there(): void
    {
        $entries = [self::exact('/b', '/c'), self::area('/zone', '/elsewhere')];

        self::assertSame('target_redirects', $this->rules($entries)->check(self::entry('/a', '/b'), null)->get('target'));
        self::assertSame('target_redirects', $this->rules($entries)->check(self::entry('/a', '/zone/x'), null)->get('target'));
        self::assertTrue($this->rules($entries)->check(self::entry('/a', '/b'), ['id' => $entries[0]->id])->isEmpty());

        $this->pages = ['/b'];
        self::assertTrue($this->rules($entries)->check(self::entry('/a', '/b'), null)->isEmpty());
    }

    public function test_keep_the_rest_leaves_no_entry_below_its_target(): void
    {
        $rules = $this->rules([self::exact('/new/deep', '/x')]);

        self::assertSame('target_redirects', $rules->check(self::entry('/old', '/new', true, 'prefix'), null)->get('target'));
    }

    public function test_the_target_rules_run_without_a_path(): void
    {
        $rules = $this->rules();

        self::assertSame('prefix_no_query', $rules->target(self::entry('', '/new?x', true, 'prefix'), null)->get('target'));
        self::assertTrue($rules->target(self::entry('', '/new'), null)->isEmpty());
    }

    public function test_a_saved_entry_leaves_the_not_found_list(): void
    {
        $rules = $this->rules();
        $rules->saved(self::entry('/old'));
        $rules->saved(self::entry('/zone', '/new', true));

        self::assertSame(['one:/old', 'area:/zone'], $this->forgotten);
    }
}
