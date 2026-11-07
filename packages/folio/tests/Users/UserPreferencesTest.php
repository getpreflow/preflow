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

    private function prefs(): UserPreferences
    {
        return new UserPreferences(new DataManager(['default' => new SqliteDriver(new \PDO('sqlite::memory:'))]));
    }

    public function test_nav_sections_are_stored_per_auth_id(): void
    {
        $prefs = $this->prefs();
        $this->assertSame([], $prefs->navSections('u1'), 'no table yet');

        $prefs->setNavSection('u1', 'library', true);
        $prefs->setNavSection('u1', 'settings', false);

        $this->assertSame(['library' => true, 'settings' => false], $prefs->navSections('u1'));
        $this->assertSame([], $prefs->navSections('u2'));
    }

    public function test_numeric_section_names_keep_their_keys(): void
    {
        $prefs = $this->prefs();
        $prefs->setNavSection('u1', '2024', false);
        $prefs->setNavSection('u1', '2025', true);
        $prefs->setNavSection('u1', 'library', false);

        $this->assertSame(['2024' => false, '2025' => true, 'library' => false], $prefs->navSections('u1'));
    }

    public function test_saving_one_section_keeps_the_others_and_the_locale(): void
    {
        $prefs = $this->prefs();
        $prefs->setLocale('u1', 'de');
        $prefs->setNavSection('u1', 'library', true);
        $prefs->setNavSection('u1', 'settings', false);
        $prefs->setNavSection('u1', 'library', false);

        $this->assertSame(['library' => false, 'settings' => false], $prefs->navSections('u1'));
        $this->assertSame('de', $prefs->locale('u1'));
    }

    public function test_two_tabs_saving_different_sections_both_end_up_saved(): void
    {
        $dm = new DataManager(['default' => new SqliteDriver(new \PDO('sqlite::memory:'))]);
        $tabA = new UserPreferences($dm);
        $tabB = new UserPreferences($dm);
        $tabA->setNavSection('u1', 'library', true);
        $tabB->setNavSection('u1', 'settings', true);

        $this->assertSame(['library' => true, 'settings' => true], $tabA->navSections('u1'));
    }

    public function test_clearing_the_locale_keeps_nav_sections(): void
    {
        $prefs = $this->prefs();
        $prefs->setNavSection('u1', 'library', true);
        $prefs->setLocale('u1', 'fr');
        $prefs->setLocale('u1', null);

        $this->assertNull($prefs->locale('u1'));
        $this->assertSame(['library' => true], $prefs->navSections('u1'));
    }

    public function test_the_row_goes_when_nothing_is_left(): void
    {
        $dm = new DataManager(['default' => new SqliteDriver(new \PDO('sqlite::memory:'))]);
        $prefs = new UserPreferences($dm);
        $prefs->setLocale('u1', 'de');
        $this->assertNotNull($dm->findIn($prefs->definition(), 'u1'));
        $prefs->setLocale('u1', null);

        $this->assertNull($dm->findIn($prefs->definition(), 'u1'));
    }

    public function test_nav_sections_read_from_an_old_table_without_the_column(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE folio_user_preferences (id TEXT PRIMARY KEY, locale TEXT)');
        $pdo->exec("INSERT INTO folio_user_preferences (id, locale) VALUES ('u1', 'de')");
        $prefs = new UserPreferences(new DataManager(['default' => new SqliteDriver($pdo)]));

        $this->assertSame([], $prefs->navSections('u1'));
        $this->assertSame('de', $prefs->locale('u1'));
    }

    public function test_non_bool_entries_are_ignored_when_reading(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $prefs = new UserPreferences(new DataManager(['default' => new SqliteDriver($pdo)]));
        $prefs->setNavSection('u1', 'library', true);
        $pdo->exec('UPDATE folio_user_preferences SET nav = \'{"library":true,"x":"yes","y":1}\'');

        $this->assertSame(['library' => true], $prefs->navSections('u1'));
    }
}
