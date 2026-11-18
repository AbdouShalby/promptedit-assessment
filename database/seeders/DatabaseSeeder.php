<?php

namespace Database\Seeders;

use App\Billing\CreditLedger;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Creates the reviewer accounts from config/demo.php. Safe to run more than once. */
    public function run(): void
    {
        $demo = $this->seedAccount(config('demo.user'), 'Demo User', isAdmin: false);
        $this->seedAccount(config('demo.admin'), 'Admin', isAdmin: true);

        if ($demo !== null) {
            // A fixed key per user: seeding again does not add the starting credit again.
            $key = (string) preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', md5('demo-starting-credit-'.$demo->id));

            app(CreditLedger::class)->purchase($demo, config('demo.user.credit_nano'), $key);
        }
    }

    /** @param  array<string, mixed>  $account */
    private function seedAccount(array $account, string $name, bool $isAdmin): ?User
    {
        if (empty($account['email']) || empty($account['password'])) {
            $this->command?->warn("Skipped {$name}: its email or password is not set in the environment.");

            return null;
        }

        $user = User::firstOrNew(['email' => $account['email']]);

        $user->forceFill([
            'name' => $name,
            'password' => $account['password'],   // hashed by the model cast
            'email_verified_at' => $user->email_verified_at ?? now(),
            'is_admin' => $isAdmin,
        ])->save();

        return $user;
    }
}
