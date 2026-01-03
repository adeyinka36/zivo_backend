<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS user_assignments_user_id_current_idx ON user_assignments (user_id, current)');
        
        DB::statement('CREATE INDEX IF NOT EXISTS user_assignments_user_id_idx ON user_assignments (user_id)');
        
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS user_assignments_one_current_per_user ON user_assignments (user_id) WHERE current = TRUE');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS user_assignments_one_current_per_user');
        DB::statement('DROP INDEX IF EXISTS user_assignments_user_id_idx');
        DB::statement('DROP INDEX IF EXISTS user_assignments_user_id_current_idx');
    }
};
