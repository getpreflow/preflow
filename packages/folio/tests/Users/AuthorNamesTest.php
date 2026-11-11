<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Users;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\DataManager;
use Preflow\Folio\Users\AuthorNames;

/** Staff that are no record users (a model behind DataManagerUserProvider, as in preflow/starter-folio). */
final class AuthorNamesTest extends TestCase
{
    private function provider(): UserProviderInterface
    {
        return new class implements UserProviderInterface {
            public function findById(string $id): ?Authenticatable
            {
                return match ($id) {
                    'u1' => new class implements Authenticatable {
                        public string $uuid = 'u1';
                        public ?string $name = 'Grace Hopper';
                        public string $email = 'grace@site.test';
                        public function getAuthId(): string { return $this->uuid; }
                        public function getPasswordHash(): string { return ''; }
                        public function getRoles(): array { return []; }
                        public function hasRole(string $role): bool { return false; }
                    },
                    'u2' => new \Preflow\Auth\ArrayUser('u2', 'nameless@site.test', '', []),
                    default => null,
                };
            }

            public function findByCredentials(array $credentials): ?Authenticatable
            {
                return null;
            }
        };
    }

    public function test_the_staff_guard_s_provider_names_a_user_that_is_no_record(): void
    {
        $names = new AuthorNames(new DataManager([]), static fn (): array => [], fn (): UserProviderInterface => $this->provider());

        $this->assertSame('Grace Hopper', $names->label('u1'), 'the name');
        $this->assertSame('nameless@site.test', $names->label('u2'), 'else the e-mail');
        $this->assertSame('gone', $names->label('gone'), 'unknown: as stored');
        $this->assertSame('old@site.test', $names->label('old@site.test'));
    }

    public function test_without_a_provider_ids_show_as_stored(): void
    {
        $this->assertSame('u1', (new AuthorNames(new DataManager([]), static fn (): array => []))->label('u1'));
    }
}
