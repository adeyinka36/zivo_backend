<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the check constraint if it exists
        DB::statement('ALTER TABLE questions DROP CONSTRAINT IF EXISTS questions_answer_check;');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the check constraint (if needed for rollback)
        DB::statement('ALTER TABLE questions ADD CONSTRAINT questions_answer_check CHECK (answer IN (\'A\', \'B\', \'C\', \'D\'));');
    }
};