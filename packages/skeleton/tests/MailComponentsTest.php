<?php

declare(strict_types=1);

namespace Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\SystemClock;
use Preflow\Notify\Mail\MailContent;
use Preflow\Notify\Mail\Mailer;

/**
 * A mail renders in a context without a request (its recipient's). The skeleton's Navigation and
 * LocaleSwitcher read the request's path; in a mail they render with none (no item active, the
 * switcher linking each language's home page).
 */
final class MailComponentsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/skeleton_mail_' . bin2hex(random_bytes(4));
        $skeleton = dirname(__DIR__);
        mkdir($this->dir . '/storage', 0777, true);
        foreach (['app', 'config', 'lang', 'public'] as $folder) {
            exec('cp -R ' . escapeshellarg($skeleton . '/' . $folder) . ' ' . escapeshellarg($this->dir . '/' . $folder));
        }
        unset($_SESSION);
        $_COOKIE = $_GET = $_POST = $_REQUEST = $_FILES = [];
        foreach (['APP_NAME', 'APP_TIMEZONE', 'APP_LOCALE', 'APP_ENGINE', 'APP_SECURE', 'DB_PATH'] as $name) {
            putenv($name);
        }
        putenv('APP_DEBUG=0');
        putenv('APP_KEY=skeleton-mail-components-test-key-0123456789');
        putenv('APP_URL=http://localhost:8080');
        putenv('DB_DRIVER=json');
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
        putenv('APP_KEY');
        putenv('APP_URL');
        putenv('DB_DRIVER');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_a_mail_layout_renders_the_navigation_and_locale_switcher(): void
    {
        file_put_contents($this->dir . '/mail.twig', "{{ component('Navigation', { brand: 'Site', items: [{ path: '/', label: 'Home' }, { path: '/about', label: 'About' }] }) }}{{ component('LocaleSwitcher', { locales: ['en', 'de'] }) }}");
        $app = Application::create($this->dir);
        $app->boot();
        $mailer = $app->container()->get(Mailer::class);
        $visit = RenderContext::fromRequest(new ServerRequest('GET', 'http://localhost:8080/about'), $app->site(), new SystemClock());

        $html = Contexts::run($visit, fn (): string => (string) $mailer->compose(new MailContent('Hi', 'Text', 'de'), 'reader@site.test', template: $this->dir . '/mail.twig')->html);

        $this->assertStringContainsString('nav-brand', $html, 'the navigation rendered');
        $this->assertStringNotContainsString('nav-link--active', $html, 'a mail has no current page: no item is active');
        $this->assertStringContainsString('href="/en"', $html, 'the switcher links each language\'s home page');
        $this->assertStringContainsString('locale-btn--active">DE<', $html, 'the reader\'s language is the active one');
    }
}
