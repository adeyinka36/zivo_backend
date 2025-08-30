<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type');
            $table->string('media_type')->nullable();
            $table->bigInteger('reward');
            $table->unsignedBigInteger('size');
            $table->string('path');
            $table->string('disk');
            $table->text('description')->nullable();
            $table->boolean('has_reward')->default(true);
            $table->boolean('quiz_played')->default(false);
            $table->integer('quiz_number');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
