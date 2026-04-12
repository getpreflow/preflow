<?php

declare(strict_types=1);

namespace App\Seeds;

use App\Models\User;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Data\DataManager;

final class UserSeeder
{
    public function run(DataManager $data): void
    {
        $hasher = new NativePasswordHasher();

        $user = new User();
        $user->uuid = 'user-admin';
        $user->email = 'admin@preflow.dev';
        $user->passwordHash = $hasher->hash('password');
        $user->roles = ['admin'];
        $user->createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $data->save($user);
    }
}
