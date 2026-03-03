<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('questions')) {
            Schema::create('questions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->text('question');
                $table->string('answer', 19);
                $table->string('option_a', 255)->nullable();
                $table->string('option_b', 255)->nullable();
                $table->string('option_c', 255)->nullable();
                $table->string('option_d', 255)->nullable();
                $table->foreignUuid('media_id')->constrained('media')->cascadeOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
