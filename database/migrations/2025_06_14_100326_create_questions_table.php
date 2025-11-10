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
        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('question');
            $table->string('answer', 19);
            $table->foreignUuid('media_id')->constrained('media')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Drop the check constraint if it exists (for PostgreSQL)
        DB::statement('ALTER TABLE questions DROP CONSTRAINT IF EXISTS questions_answer_check;');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
