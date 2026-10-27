<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Members\ConfigMembersSettings;
use Preflow\Members\MembersConfig;
use Preflow\Members\MembersSettings;
use Preflow\Members\Tests\Support\MembersScaffold;

/** Without Folio's Settings → Members, the owner's settings come from config/members.php. */
final class ConfigMembersSettingsTest extends TestCase
{
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_defaults(): void
    {
        $settings = new ConfigMembersSettings(new MembersConfig([]));

        $this->assertTrue($settings->registrationOpen());
        $this->assertNull($settings->noticeEmail());
        $this->assertNull($settings->acceptMessage('en'));
        $this->assertNull($settings->declineMessage('de'));
    }

    public function test_values_from_config(): void
    {
        $settings = new ConfigMembersSettings(new MembersConfig([
            'registration_open' => false,
            'notice_email' => ' owner@site.test ',
            'accept_message' => ['en' => 'Welcome, {name}.', 'de' => 'Willkommen, {name}.', 'fr' => '  '],
            'decline_message' => 'Sorry, {name}.',
        ]));

        $this->assertFalse($settings->registrationOpen());
        $this->assertSame('owner@site.test', $settings->noticeEmail());
        $this->assertSame('Welcome, {name}.', $settings->acceptMessage('en'));
        $this->assertSame('Willkommen, {name}.', $settings->acceptMessage('de'));
        $this->assertNull($settings->acceptMessage('fr'), 'a blank text: the lang files word it');
        $this->assertNull($settings->acceptMessage('it'), 'exactly the locale asked for');
        $this->assertSame('Sorry, {name}.', $settings->declineMessage('fr'), 'one text for every locale');
    }

    public function test_a_blank_notice_address_is_none(): void
    {
        $this->assertNull((new ConfigMembersSettings(new MembersConfig(['notice_email' => ' '])))->noticeEmail());
    }

    public function test_the_provider_binds_it_when_nothing_else_is(): void
    {
        $this->scaffold();
        $this->write('config/members.php', "<?php\nreturn ['registration_open' => false];\n");

        $settings = $this->app()->container()->get(MembersSettings::class);

        $this->assertInstanceOf(ConfigMembersSettings::class, $settings);
        $this->assertFalse($settings->registrationOpen());
    }
}
