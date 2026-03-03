<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('media_id')->constrained()->cascadeOnDelete();
            $table->boolean('current')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'media_id']);
            $table->index('user_id');
            $table->index('media_id');
        });

        DB::statement('CREATE INDEX user_assignments_user_id_current_idx ON user_assignments (user_id, current)');
        DB::statement('CREATE INDEX user_assignments_user_id_idx ON user_assignments (user_id)');
        DB::statement('CREATE UNIQUE INDEX user_assignments_one_current_per_user ON user_assignments (user_id) WHERE current = TRUE');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS user_assignments_one_current_per_user');
        DB::statement('DROP INDEX IF EXISTS user_assignments_user_id_idx');
        DB::statement('DROP INDEX IF EXISTS user_assignments_user_id_current_idx');
        Schema::dropIfExists('user_assignments');
    }
};
