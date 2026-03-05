<?php

namespace Database\Seeders;

use App\Models\Creator;
use Illuminate\Database\Seeder;

/**
 * Sets the creator identified by ADMIN_CREATOR_EMAIL (in .env) as an admin.
 * Run once after deployment to grant the first admin, or set is_admin manually in the DB.
 */
class AdminCreatorSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $email = env('ADMIN_CREATOR_EMAIL');

        if (empty($email)) {
            return;
        }

        $creator = Creator::where('email', $email)->first();

        if (!$creator) {
            $this->command->warn("AdminCreatorSeeder: No creator found with email [{$email}]. Skip.");

            return;
        }

        $creator->update(['is_admin' => true]);
        $this->command->info("AdminCreatorSeeder: Set creator [{$email}] as admin.");
    }
}
