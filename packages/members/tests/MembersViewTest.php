<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Members\MembersView;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MembersScaffold;
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
        $this->assertSame(['name', 'email', 'password', 'code', 'company', 'consent'], $names);
        $this->assertSame('Ada', Dom::first($site, '//input[@name="name"]')?->getAttribute('value'));
        $this->assertSame('Please check the highlighted fields.', Dom::text($site, '//div[@role="alert"]'));
        $this->assertSame('Please enter your name.', Dom::text($site, '//p[contains(@class,"field-error")]'));
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
