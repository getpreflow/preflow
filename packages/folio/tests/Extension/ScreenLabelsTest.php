<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Preflow\Folio\Users\UserPreferences;

/**
 * A site extension's screen label (and the texts of its attributes) may be keys of the site's
 * own lang/{locale}/site.php; they follow each staff member's interface language.
 */
final class ScreenLabelsTest extends TestCase
{
    use StaffScaffold;

    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->staffSite();
        $this->write('config/models/contact.json', (string) json_encode([
            'key' => 'contact', 'table' => 'contact', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Contacts',
            'fields' => ['name' => ['type' => 'string']],
        ]));
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => ['crm' => ['disabled_parts' => []]]]));
        $this->write('app/Folio/Crm/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Crm;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'crm', label: 'CRM')]
            #[\Preflow\Folio\Extension\Attributes\DeclaresPermission('crm.queue', 'Queue')]
            #[\Preflow\Folio\Extension\Attributes\DeclaresPermission('crm.go', 'Go')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('app/Folio/Crm/Types/ContactAdmin.php', <<<'PHP'
            <?php
            namespace App\Folio\Crm\Types;
            use Preflow\Data\DynamicRecord;
            #[\Preflow\Folio\Extension\Attributes\FolioType('contact')]
            final class ContactAdmin
            {
                #[\Preflow\Folio\Extension\ListColumn('site.columns.kind')]
                public function kind(DynamicRecord $record): string { return 'VIP'; }

                #[\Preflow\Folio\Extension\FormPanel('site.panels.check', position: 'side')]
                public function check(DynamicRecord $record): string { return 'ok'; }

                #[\Preflow\Folio\Extension\RecordAction(name: 'go', label: 'site.actions.go', permission: 'crm.go')]
                public function go(DynamicRecord $record): null { return null; }
            }
            PHP);
        $this->write('app/Folio/Crm/templates/queue.twig', "{% extends '@folio/admin/_layout.twig' %}\n{% block content %}<h1>{{ screen.label }}</h1>{% endblock %}");
        $this->screen('site.screens.queue');
        $this->write('lang/en/site.php', "<?php return ['screens' => ['queue' => 'To review'], 'sections' => ['review' => 'Review']];");
        $this->write('lang/de/site.php', "<?php return [
            'screens' => ['queue' => 'Prüfen'], 'sections' => ['review' => 'Prüfung'],
            'columns' => ['kind' => 'Art'], 'panels' => ['check' => 'Kontrolle'], 'actions' => ['go' => 'Los geht\\'s'],
        ];");
        $this->write('lang/fr/site.php', "<?php return ['screens' => ['queue' => 'À vérifier'], 'sections' => ['review' => 'Contrôle']];");

        $root = $this->dir . '/app/Folio/';
        $this->autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeSite();
    }

    private function screen(string $label): void
    {
        $this->write('app/Folio/Crm/Screens/Queue.php', <<<PHP
            <?php
            namespace App\\Folio\\Crm\\Screens;
            use Preflow\\Folio\\Extension\\Attributes\\FolioScreen;
            use Preflow\\Folio\\Extension\\Screen\\ScreenView;
            use Preflow\\Routing\\Attributes\\Get;
            #[FolioScreen('queue', '{$label}', section: 'review', permission: 'crm.queue')]
            final class Queue
            {
                #[Get('/')]
                public function index(): ScreenView { return new ScreenView('@crm/queue.twig'); }
            }
            PHP);
    }

    private function signInAs(string $locale): void
    {
        $this->flow = $this->app();
        $ada = $this->makeStaff();
        $this->flow->container()->get(UserPreferences::class)->setLocale((string) $ada->getId(), $locale);
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD])->getHeaderLine('Location'));
        $this->dm()->saveType(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('contact'), ['uuid' => 'c1', 'name' => 'Ann']), false);
    }

    private static function nav(string $html): string
    {
        preg_match('~<nav[^>]*>.*?</nav>~s', $html, $m);

        return $m[0] ?? '';
    }

    /** @return array<string, array{string, string}> */
    public static function languages(): array
    {
        return ['german' => ['de', 'Prüfen'], 'french' => ['fr', 'À vérifier']];
    }

    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('languages')]
    public function test_a_screen_label_key_follows_the_interface_language(string $locale, string $label): void
    {
        $this->signInAs($locale);
        $this->assertStringContainsString("<span>{$label}</span>", self::nav($this->html('/folio')));
        $this->assertStringContainsString("<h1>{$label}</h1>", $this->html('/folio/queue'));
    }

    #[RunInSeparateProcess]
    public function test_the_extensions_screen_shows_the_screen_part_in_the_interface_language(): void
    {
        $this->signInAs('de');
        $this->assertStringContainsString('&emsp;Prüfen <code>screen:queue</code>', $this->html('/folio/extensions'));
    }

    #[RunInSeparateProcess]
    public function test_a_plain_screen_label_stays_as_written(): void
    {
        $this->screen('Queue');
        $this->signInAs('de');
        $this->assertStringContainsString('<span>Queue</span>', self::nav($this->html('/folio')));
        $this->assertStringContainsString('<h1>Queue</h1>', $this->html('/folio/queue'));
    }

    #[RunInSeparateProcess]
    public function test_a_screen_only_section_takes_site_sections_key(): void
    {
        $this->signInAs('de');
        $this->assertStringContainsString('Prüfung', self::nav($this->html('/folio')));
    }

    #[RunInSeparateProcess]
    public function test_project_extension_attribute_labels_take_site_keys(): void
    {
        $this->signInAs('de');
        $list = $this->html('/folio/contact');
        $this->assertMatchesRegularExpression('~<th[^>]*>.*?Art.*?</th>~s', $list);
        $edit = $this->html('/folio/contact/c1/edit');
        $this->assertStringContainsString('Kontrolle', $edit);
        $this->assertStringContainsString('Los geht&#039;s', str_replace(['&#39;', '&apos;'], '&#039;', $edit));
        $this->assertStringNotContainsString('site.panels.check', $edit);
    }

    #[RunInSeparateProcess]
    public function test_a_key_shaped_label_without_a_text_shows_the_key(): void
    {
        $this->screen('site.screens.missing');
        $this->signInAs('de');
        $this->assertStringContainsString('<span>site.screens.missing</span>', self::nav($this->html('/folio')));
        $this->assertStringContainsString('<h1>site.screens.missing</h1>', $this->html('/folio/queue'));
    }
}
