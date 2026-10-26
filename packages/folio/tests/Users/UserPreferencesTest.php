<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Users;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Folio\Users\UserPreferences;

/** A Folio user's interface language, per auth id, in Folio's own table. */
final class UserPreferencesTest extends TestCase
{
    public function test_a_locale_is_stored_per_auth_id_and_can_be_cleared(): void
    {
        $prefs = new UserPreferences(new DataManager(['default' => new SqliteDriver(new \PDO('sqlite::memory:'))]));

        $this->assertNull($prefs->locale('ada'), 'nothing stored, and no table yet');

        $prefs->setLocale('ada', 'fr');
        $prefs->setLocale('hi@site.test', 'de');
        $this->assertSame('fr', $prefs->locale('ada'), 'the table is created on the first save');
        $this->assertSame('de', $prefs->locale('hi@site.test'));

        $prefs->setLocale('ada', 'de');
        $this->assertSame('de', $prefs->locale('ada'));

        $prefs->setLocale('ada', null);
        $this->assertNull($prefs->locale('ada'));
        $this->assertSame('de', $prefs->locale('hi@site.test'));
        $this->assertSame('folio_user_preferences', $prefs->definition()->table);
    }
}
