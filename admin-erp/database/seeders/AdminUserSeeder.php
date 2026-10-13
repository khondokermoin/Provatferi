<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates exactly the one required initial Super Admin account.
 *
 * Never hardcodes a password (a prior version did — `change-me-immediately`
 * — which is exactly the kind of committed-secret pattern this project no
 * longer allows). Instead a random password is generated at seed time and
 * printed once to this command's own output, so whoever runs the seeder
 * (a one-time deployment cron job, not a checked-in value) is the only one
 * who sees it. Idempotent — does nothing if the account already exists, so
 * re-running this seeder can never reset a real admin's password.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // The internal administration mailbox (config/institution.php) — an
        // internal login, never published.
        $email = (string) config('institution.email.internal');

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->info('Super Admin already exists — skipping.');

            return;
        }

        $password = Str::password(24);

        $user = User::query()->create([
            'name' => 'মেহেদী হাসান রনি',
            'email' => $email,
            'password' => Hash::make($password),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $superAdmin = Role::query()->where('slug', 'super_admin')->first();
        if ($superAdmin) {
            $user->roles()->attach($superAdmin->id);
        }

        $this->command?->info("SUPER_ADMIN_EMAIL={$email}");
        $this->command?->info("SUPER_ADMIN_PASSWORD={$password}");
    }
}
