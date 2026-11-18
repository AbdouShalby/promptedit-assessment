<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Creates the reviewer accounts from config/demo.php. Safe to run more than once. */
    public function run(): void
    {
        $this->seedAccount(config('demo.user'), 'Demo User', isAdmin: false);
        $this->seedAccount(config('demo.admin'), 'Admin', isAdmin: true);
    }

    /** @param  array{email: ?string, password: ?string}  $account */
    private function seedAccount(array $account, string $name, bool $isAdmin): void
    {
        if (empty($account['email']) || empty($account['password'])) {
            $this->command?->warn("Skipped {$name}: its email or password is not set in the environment.");

            return;
        }

        $user = User::firstOrNew(['email' => $account['email']]);

        $user->forceFill([
            'name' => $name,
            'password' => $account['password'],   // hashed by the model cast
            'email_verified_at' => $user->email_verified_at ?? now(),
            'is_admin' => $isAdmin,
        ])->save();
    }
}
