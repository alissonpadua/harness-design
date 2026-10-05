<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Org\CreatePersonalWorkspaceAction;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesSeeder::class);

        // Idempotent: harness/init.sh re-runs this every session (constitution #4).
        // Raw password on purpose — the `hashed` cast owns hashing (argon2id, spec 001).
        // Demo sign-in for client-demo: test@example.com / Str0ng!Passw0rd
        $user = User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'Str0ng!Passw0rd',
                'email_verified_at' => now(),
            ],
        );

        app(CreatePersonalWorkspaceAction::class)->handle($user);
    }
}
