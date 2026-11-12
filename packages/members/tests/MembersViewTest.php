<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\OldInput;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Members\MembersView;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Testing\ArraySession;
use Psr\Http\Message\ResponseInterface;

/** The member pages: templates through the site's layout, site overrides first, copy through t(). */
final class MembersViewTest extends TestCase
{
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** A site with a minimal layout (what smart-stack's _site.twig is for the real one). */
    private function site(string $members = '[]', array $locales = ['en', 'de', 'fr'], bool $i18nConfig = true): void
    {
        $this->flow = null;
        $this->scaffold(locales: $locales);
        if (!$i18nConfig) {
            unlink($this->dir . '/config/i18n.php');
        }
        // The site names its layout (a site without one gets the package's @members/_layout.twig).
        $this->write('config/members.php', "<?php\nreturn {$members} + ['layout' => '@members/_site.twig'];\n");
        $layout = <<<'TWIG'
<!DOCTYPE html>
<html lang="{{ locale }}"><head>{% block meta %}<title>Site</title>{% endblock %}
{% for a in alternates %}<link rel="alternate" hreflang="{{ a.locale }}" href="{{ a.href }}">{% endfor %}</head>
<body data-layout="{{ layout }}"><header id="site-header">Site header</header><main>{% block content %}{% endblock %}</main></body></html>
TWIG;
        $this->write('resources/members/_site.twig', $layout);
        $this->write('resources/members/_other.twig', str_replace('Site header', 'Other header', $layout));
    }

    private function view(): MembersView
    {
        return $this->container()->get(MembersView::class);
    }

    private function render(string $template, array $vars = [], string $locale = 'en', string $uri = '/members/login', int $status = 200): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://site.test' . $uri)->withAttribute('locale', $locale);

        return $this->view()->render($template, $vars, $request, $status);
    }

    private function html(string $template, array $vars = [], string $locale = 'en', string $uri = '/members/login'): string
    {
        return (string) $this->render($template, $vars, $locale, $uri)->getBody();
    }

    /** @return array<string, array{string, array<string, mixed>, string, string}> template, vars, xpath, expected text */
    public static function pages(): array
    {
        return [
            'login' => ['login', ['error' => 'login', 'old_email' => 'a@b.test', 'next' => '/members/account'], '//div[@role="alert"]', 'Email or password is wrong.'],
            'forgot' => ['forgot', [], '//h1', 'Forgot your password?'],
            'forgot sent' => ['forgot', ['sent' => true], '//p[@role="status"]', 'If an account exists for this address, a link is on its way.'],
            'reset' => ['reset', ['token' => 'tok'], '//h1', 'Choose a new password'],
            'reset expired' => ['reset', ['error' => 'expired'], '//p[@role="alert"]', 'This link was already used or has expired. Ask for a new one.'],
            'confirm' => ['confirm', ['token' => 'tok'], '//h1', 'Confirm your email'],
            'confirm used' => ['confirm', ['used' => true], '//h1', 'This link no longer works'],
            'resend' => ['resend', ['email' => 'a@b.test'], '//h1', 'Please confirm your email first'],
            'check-inbox' => ['check-inbox', ['email' => 'a@b.test'], '//h1', 'Check your inbox'],
            'thanks' => ['thanks', [], '//h1', 'Thanks for your interest'],
            'closed' => ['closed', [], '//h1', 'Registration is closed'],
            'account' => ['account', ['name' => 'Ada', 'email' => 'a@b.test', 'notice' => 'Password changed.'], '//p[contains(@class,"account-who")]', 'Ada · a@b.test'],
            'register' => ['register', ['open' => true], '//form[contains(@class,"members-card")]//button', 'Create account'],
            'register closed' => ['register', ['open' => false], '//h2', 'Registration is closed'],
        ];
    }

    /** @param array<string, mixed> $vars */
    #[DataProvider('pages')]
    public function test_every_page_renders_in_the_site_layout_with_its_copy(string $template, array $vars, string $xpath, string $text): void
    {
        $this->site();
        $html = $this->html($template, $vars);

        $this->assertSame('Site header', Dom::text($html, '//header[@id="site-header"]'));
        $this->assertSame($text, Dom::text($html, $xpath));
        $this->assertSame('Members', explode(' | ', Dom::text($html, '//title'))[0]);
        $this->assertSame('noindex', Dom::first($html, '//meta[@name="robots"]')?->getAttribute('content'));
    }

    public function test_forms_post_to_the_member_pages_with_labelled_fields(): void
    {
        $this->site();
        $html = $this->html('login', ['next' => '/members/account', 'old_email' => 'a@b.test']);

        $form = Dom::first($html, '//form');
        $this->assertSame('/members/login', $form?->getAttribute('action'));
        $this->assertSame('post', $form->getAttribute('method'));
        $this->assertSame('/members/account', Dom::first($html, '//input[@name="next"]')?->getAttribute('value'));
        $this->assertSame('a@b.test', Dom::first($html, '//input[@name="email"]')?->getAttribute('value'));
        $this->assertSame('f-email', Dom::first($html, '//label[normalize-space()="Email"]')?->getAttribute('for'));
        $this->assertSame('/members/forgot', Dom::first($html, '//p[contains(@class,"members-switch")]/a')?->getAttribute('href'));
        $this->assertSame('/members/register', Dom::all($html, '//p[contains(@class,"members-switch")]/a')[1]->getAttribute('href'));
    }

    public function test_links_carry_the_locale_prefix_and_the_configured_path(): void
    {
        $this->site("['path' => '/konto', 'landing' => '/start']");
        $html = $this->html('account', ['name' => 'Ada', 'email' => 'a@b.test'], 'de', '/konto/account');

        $this->assertSame('/de/konto/account/password', Dom::all($html, '//form')[0]->getAttribute('action'));
        $this->assertSame('/de/konto/account/delete', Dom::all($html, '//form')[1]->getAttribute('action'));
        $this->assertSame('/de/start', Dom::first($html, '//p[contains(@class,"members-switch")]/a')?->getAttribute('href'));
        $this->assertSame('Ihr Konto', Dom::text($html, '//h1'));
        $this->assertSame('Zurück', Dom::text($html, '//p[contains(@class,"members-switch")]/a'));
    }

    public function test_alternates_are_the_same_page_in_every_locale(): void
    {
        $this->site();
        $html = $this->html('login', [], 'de', '/members/login');

        $links = [];
        foreach (Dom::all($html, '//link[@rel="alternate"]') as $link) {
            $links[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }
        $this->assertSame([
            'en' => 'https://site.test/members/login',
            'de' => 'https://site.test/de/members/login',
            'fr' => 'https://site.test/fr/members/login',
        ], $links);
        $this->assertSame('de', Dom::first($html, '//html')?->getAttribute('lang'));
    }

    public function test_answers_are_private_and_never_cached(): void
    {
        $this->site();
        $response = $this->render('login', [], status: 422);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<style', (string) $response->getBody(), 'the page css is injected');
    }

    public function test_redirects(): void
    {
        $this->site();

        $hx = $this->view()->hxRedirect('/members');
        $this->assertSame(200, $hx->getStatusCode());
        $this->assertSame('/members', $hx->getHeaderLine('HX-Redirect'));
        $this->assertSame('private, no-store', $hx->getHeaderLine('Cache-Control'));

        // navigate(): the client that sent the request is told (htmx: HX-Redirect), a browser gets a 303.
        $request = (new Psr17Factory())->createServerRequest('POST', 'https://site.test/members/login');
        $told = $this->view()->navigate($request->withHeader('HX-Request', 'true'), '/members');
        $this->assertSame('/members', $told->getHeaderLine('HX-Redirect'));
        $this->assertSame('private, no-store', $told->getHeaderLine('Cache-Control'));
        $this->assertSame(303, $this->view()->navigate($request, '/members')->getStatusCode());

        $see = $this->view()->redirect('/members/login');
        $this->assertSame(303, $see->getStatusCode());
        $this->assertSame('/members/login', $see->getHeaderLine('Location'));
        $this->assertSame('private, no-store', $see->getHeaderLine('Cache-Control'));
    }

    public function test_the_default_layout_is_neutral(): void
    {
        // No site's colour: every colour of the package's own page layout is a grey.
        preg_match_all('/#([0-9a-f]{6})\b/i', (string) file_get_contents(__DIR__ . '/../src/templates/_layout.twig'), $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $hex) {
            $rgb = array_map(hexdec(...), str_split($hex, 2));
            $this->assertLessThanOrEqual(8, max($rgb) - min($rgb), "#{$hex} is no grey");
        }
    }

    /** The page's inline css: what is inside its <style> elements. */
    private function css(string $html): string
    {
        preg_match_all('~<style[^>]*>(.*?)</style>~s', $html, $m);

        // The framework's own runtime script also mentions "<style"; the page css is what styles the members pages.
        return implode("\n", array_filter($m[1], static fn (string $css): bool => str_contains($css, 'members') || str_contains($css, '.only-') || str_starts_with(trim($css), '.x-')));
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function stylePages(): array
    {
        return [
            'login' => ['login', []],
            'forgot' => ['forgot', []],
            'confirm' => ['confirm', ['token' => 'tok']],
            'thanks' => ['thanks', []],
            'account' => ['account', ['name' => 'Ada', 'email' => 'a@b.test']],
            'register' => ['register', ['open' => true]],
            'register closed' => ['register', ['open' => false]],
        ];
    }

    /** @param array<string, mixed> $vars */
    #[DataProvider('stylePages')]
    public function test_pages_use_no_site_tokens(string $template, array $vars): void
    {
        $this->site();
        $css = $this->css($this->html($template, $vars));

        $this->assertStringContainsString('--members-', $css);
        foreach (['--paper', '--rule', '--ink', '--font-mono', '--font-display', '--accent', 'section-inner', 'members-code', 'members-or', '#d42a34', 'uppercase'] as $token) {
            $this->assertStringNotContainsString($token, $css, "{$template}: {$token}");
        }
        $this->assertDoesNotMatchRegularExpression('~(?<![\d.])8rem~', $css, 'no display-size paddings');
        // Every member custom property carries its neutral fallback, and only the documented ones are used.
        preg_match_all('~var\((--[a-z-]+)(,?)~', $css, $uses, PREG_SET_ORDER);
        $this->assertNotEmpty($uses);
        foreach ($uses as [, $name, $comma]) {
            $this->assertContains($name, ['--members-bg', '--members-ink', '--members-muted', '--members-line', '--members-accent', '--members-radius', '--members-font']);
            $this->assertSame(',', $comma, "{$name} has no fallback");
        }
        $this->assertStringContainsString('outline:2px solid var(--members-accent,light-dark(#1a1a1a,', $css, 'a visible focus ring uses the accent');
        $this->assertStringContainsString(':focus-visible', $css);
    }

    /** @param array<string, mixed> $vars */
    #[DataProvider('stylePages')]
    public function test_pages_text_colours_have_contrast_in_light_and_dark(string $template, array $vars): void
    {
        $this->site();
        $css = $this->css($this->html($template, $vars));

        $fallback = static function (string $token) use ($css): array {
            preg_match('~var\(--members-' . $token . ',\s*light-dark\((#[0-9a-f]{6}),\s*(#[0-9a-f]{6})\)\)~', $css, $m);

            return [$m[1], $m[2]];
        };
        $luminance = static function (string $hex): float {
            $c = array_map(static fn (string $h): float => ($v = hexdec($h) / 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        foreach ([0, 1] as $mode) {
            $bg = $luminance($fallback('bg')[$mode]);
            foreach (['ink', 'muted', 'accent'] as $fg) {
                $l = $luminance($fallback($fg)[$mode]);
                $ratio = (max($bg, $l) + 0.05) / (min($bg, $l) + 0.05);
                $this->assertGreaterThanOrEqual(4.5, $ratio, ($mode ? 'dark ' : 'light ') . $fg);
            }
        }
    }

    public function test_styles_block_can_be_replaced(): void
    {
        $this->site();
        // (A css block is emitted once per process, so each page's replacement differs.)
        foreach (['login', 'register', 'account'] as $page) {
            $this->write("resources/members/{$page}.twig", "{% extends '@members-package/{$page}.twig' %}{% block members_styles %}.x-{$page}{color:red}{% endblock %}");
        }
        $this->flow = null;

        foreach ([['login', []], ['register', ['open' => true]], ['account', ['name' => 'Ada', 'email' => 'a@b.test']]] as [$page, $vars]) {
            $css = $this->css($this->html($page, $vars));
            // (The bots' trap, included by the register form, brings its own rule.)
            $this->assertStringContainsString(".x-{$page}{color:red}", $css, $page);
            $this->assertStringNotContainsString('--members-', $css, $page);
            $this->assertStringNotContainsString('.members-status', $css, $page);
            $this->assertStringNotContainsString('.members-card', $css, $page);
        }
    }

    public function test_only_the_package_layout_declares_the_color_scheme(): void
    {
        $this->site();
        foreach (['login', 'register'] as $page) {
            $this->assertStringNotContainsString('color-scheme', $this->css($this->html($page, ['open' => true])), "{$page} in a site layout");
        }

        $this->site("['layout' => '@members/_layout.twig']");
        $this->assertStringContainsString('color-scheme:light dark', $this->css($this->html('thanks')));
    }

    public function test_the_layout_styles_are_a_block_of_their_own(): void
    {
        $this->site("['layout' => '@members/_layout.twig']");
        $this->write('resources/members/thanks.twig', "{% extends '@members-package/thanks.twig' %}{% block members_styles %}.only-card{color:red}{% endblock %}");
        $this->write('resources/members/closed.twig', "{% extends '@members-package/closed.twig' %}{% block members_layout_styles %}.only-layout{color:red}{% endblock %}");
        $this->flow = null;

        $card = $this->css($this->html('thanks'));
        $this->assertStringContainsString('.only-card{color:red}', $card);
        $this->assertStringContainsString('body.members-page', $card, 'the layout styles stay');
        $this->assertStringNotContainsString('.members-status__card', $card);

        $layout = $this->css($this->html('closed'));
        $this->assertStringContainsString('.only-layout{color:red}', $layout);
        $this->assertStringContainsString('.members-status__card', $layout, 'the card styles stay');
        $this->assertStringNotContainsString('body.members-page', $layout);
    }

    public function test_the_account_styles_extend_the_card_styles(): void
    {
        $this->site();
        $css = $this->css($this->html('account', ['name' => 'Ada', 'email' => 'a@b.test']));

        $this->assertStringContainsString('.members-status__card', $css);
        $this->assertStringContainsString('.account-section', $css);
    }

    public function test_pages_are_usable_at_375px_markup(): void
    {
        $this->site();
        $html = $this->html('register', ['open' => true, 'errors' => ['email' => true]]);

        foreach (Dom::all($html, '//form//input[@id]') as $input) {
            $this->assertNotNull(Dom::first($html, '//label[@for="' . $input->getAttribute('id') . '"]'), $input->getAttribute('id'));
        }
        $this->assertSame('Please check the highlighted fields.', Dom::text($html, '//div[@role="alert"]'));
        // One column by default: the two-column grid only exists inside a min-width query.
        $this->assertMatchesRegularExpression('~\.members-row\s*\{[^}]*grid-template-columns:\s*1fr;~', $this->css($html));
    }

    public function test_the_configured_layout_is_extended(): void
    {
        $this->site("['layout' => '@members/_other.twig']");
        $html = $this->html('login');

        $this->assertSame('Other header', Dom::text($html, '//header'));
        $this->assertSame('Log in', Dom::text($html, '//h1'));
        $this->assertSame('Other header', Dom::text($this->html('register', ['open' => true]), '//header'));
    }

    public function test_a_site_template_in_resources_members_wins_over_the_package(): void
    {
        $this->site();
        $this->assertSame('Log in', Dom::text($this->html('login'), '//h1'));

        $this->write('resources/members/login.twig', "{% extends '@members/_card.twig' %}{% block card %}<h1>My login</h1>{% endblock %}");
        $this->flow = null; // a fresh request boots the site dir
        $html = $this->html('login');
        $this->assertSame('My login', Dom::text($html, '//h1'));
        $this->assertSame('Site header', Dom::text($html, '//header'), 'the override still gets the layout through _card');
        $this->assertSame('Choose a new password', Dom::text($this->html('reset'), '//h1'), 'other pages stay the package');
    }

    public function test_a_site_card_frame_applies_to_every_status_page(): void
    {
        $this->site();
        $this->write('resources/members/_card.twig', "{% extends layout %}{% block content %}<div id=\"my-frame\">{% block card %}{% endblock %}</div>{% endblock %}");
        $this->flow = null;

        $this->assertNotNull(Dom::first($this->html('thanks'), '//div[@id="my-frame"]/h1'));
    }

    public function test_the_register_page_has_blocks_for_admission_and_extra_fields_and_the_privacy_link(): void
    {
        $this->site();
        $html = $this->html('register', ['open' => true]);
        $this->assertNull(Dom::first($html, '//input[@name="code"]'), 'no admission fields by default');
        $this->assertNull(Dom::first($html, '//label[@for="f-consent"]//a'), 'no privacy link without privacy_url');
        $this->assertSame('I agree that my data is used for this account (privacy policy).', Dom::text($html, '//label[@for="f-consent"]/span'), 'the consent still reads, unlinked');
        $this->assertSame('10', Dom::first($html, '//input[@name="password"]')?->getAttribute('minlength'));

        $with = $this->html('register', ['open' => true, 'privacy_url' => '/privacy?a=1&b="2"']);
        $link = Dom::first($with, '//label[@for="f-consent"]//a');
        $this->assertSame('/privacy?a=1&b="2"', $link?->getAttribute('href'));
        $this->assertSame('_blank', $link->getAttribute('target'));

        $this->write('resources/members/register.twig', <<<'TWIG'
{% extends '@members-package/register.twig' %}
{% block intro %}<h1 id="intro">Join us</h1>{% endblock %}
{% block admission_fields %}<div class="field"><input name="code"></div>{% endblock %}
{% block extra_fields %}<div class="field"><input name="company"></div>{% endblock %}
TWIG);
        $this->flow = null;
        $site = $this->html('register', ['open' => true, 'errors' => ['name' => true], 'old' => ['name' => 'Ada']]);
        $this->assertSame('Join us', Dom::text($site, '//h1[@id="intro"]'));
        $names = array_map(static fn (\DOMElement $e): string => $e->getAttribute('name'), Dom::all($site, '//form//input'));
        // Rendered without a session: csrf_field() prints the stateless token.
        $this->assertSame(['_csrf_token', 'name', 'email', 'password', 'code', 'company', 'members_website', 'consent'], $names);
        $this->assertSame('Ada', Dom::first($site, '//input[@name="name"]')?->getAttribute('value'));
        $this->assertSame('Please check the highlighted fields.', Dom::text($site, '//div[@role="alert"]'));
        $this->assertSame('Please enter your name.', Dom::text($site, '//p[contains(@class,"field-error")]'));
    }

    public function test_register_form_shows_flashed_input(): void
    {
        $this->site();
        // A POST whose session had expired kept its fields for the page it was sent back to.
        $session = new ArraySession();
        $session->start();
        OldInput::keep($session, ['name' => 'Ada', 'email' => 'ada@b.test', 'password' => 'secret', 'consent' => '1']);
        $session->ageFlash();
        $this->container()->instance(SessionInterface::class, $session);

        $html = $this->html('register', ['open' => true]);
        $this->assertSame('Ada', Dom::first($html, '//input[@name="name"]')?->getAttribute('value'));
        $this->assertSame('ada@b.test', Dom::first($html, '//input[@name="email"]')?->getAttribute('value'));
        $this->assertSame('', (string) Dom::first($html, '//input[@name="password"]')?->getAttribute('value'));
        $this->assertTrue((bool) Dom::first($html, '//input[@name="consent"]')?->hasAttribute('checked'));
        $this->assertSame('ada@b.test', Dom::first($this->html('login'), '//input[@name="email"]')?->getAttribute('value'));

        // The controller's own input wins.
        $own = $this->html('register', ['open' => true, 'old' => ['name' => 'Grace']]);
        $this->assertSame('Grace', Dom::first($own, '//input[@name="name"]')?->getAttribute('value'));
        $this->assertSame('', (string) Dom::first($own, '//input[@name="email"]')?->getAttribute('value'));
    }

    public function test_the_privacy_link_comes_from_the_config_in_the_page_locale(): void
    {
        $this->site("['privacy_url' => ['en' => '/privacy', 'de' => '/de/datenschutz']]");

        foreach (['en' => '/privacy', 'de' => '/de/datenschutz', 'fr' => '/privacy'] as $locale => $href) {
            $link = Dom::first($this->html('register', ['open' => true], $locale), '//label[@for="f-consent"]//a');
            $this->assertSame($href, $link?->getAttribute('href'), $locale);
        }
        $this->assertSame('/elsewhere', Dom::first($this->html('register', ['open' => true, 'privacy_url' => '/elsewhere']), '//label[@for="f-consent"]//a')?->getAttribute('href'), 'a page\'s own value wins');
    }

    public function test_the_check_inbox_hint_shows_the_configured_validity_in_the_page_language(): void
    {
        $this->site("['confirm_ttl' => 7200]");

        $this->assertStringStartsWith('The link works for 2 hours.', Dom::text($this->html('check-inbox', ['email' => 'a@b.test']), '//p[2]'));
        $this->assertStringContainsString('2 Stunden', Dom::text($this->html('check-inbox', ['email' => 'a@b.test'], 'de'), '//p[2]'));
        $this->assertStringContainsString('<strong>a@b.test</strong>', $this->html('check-inbox', ['email' => 'a@b.test']));
    }

    public function test_user_data_is_escaped(): void
    {
        $this->site();

        $html = $this->html('check-inbox', ['email' => '<script>x</script>@b.test']);
        $this->assertStringNotContainsString('<script>x', $html);
        $account = $this->html('account', ['name' => '<script>', 'email' => 'x']);
        $this->assertStringContainsString('<strong>&lt;script&gt;</strong>', $account);
        $this->assertStringContainsString('<strong>&lt;script&gt;x&lt;/script&gt;@b.test</strong>', $html);
    }

    public function test_copy_works_without_an_i18n_config(): void
    {
        $this->site(locales: ['en'], i18nConfig: false);

        $html = $this->html('login');
        $this->assertSame('Log in', Dom::text($html, '//h1'));
        $this->assertSame('The link works for 24 hours. Nothing there? Look in your spam folder.', Dom::text($this->html('check-inbox', ['email' => 'a@b.test']), '//p[2]'));
        $this->assertSame('/members/login', Dom::first($html, '//form')?->getAttribute('action'));
    }

    public function test_a_site_lang_file_overrides_single_keys(): void
    {
        $this->site();
        $this->write('lang/en/members.php', "<?php\nreturn ['login' => ['title' => 'Sign in here']];\n");
        $this->flow = null;

        $this->assertSame('Sign in here', Dom::text($this->html('login'), '//h1'));
        $this->assertSame('Anmelden', Dom::text($this->html('login', [], 'de'), '//h1'));
    }
}
