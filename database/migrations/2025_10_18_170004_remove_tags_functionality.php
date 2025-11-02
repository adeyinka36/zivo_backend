<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the media_tag pivot table
        Schema::dropIfExists('media_tag');
        
        // Drop the tags table
        Schema::dropIfExists('tags');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate tags table
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();

            $table->index('name');
            $table->index('slug');
        });

        // Recreate media_tag pivot table
        Schema::create('media_tag', function (Blueprint $table) {
            $table->uuid('media_id');
            $table->uuid('tag_id');
            $table->timestamps();

            $table->foreign('media_id')
                ->references('id')
                ->on('media')
                ->onDelete('cascade');

            $table->foreign('tag_id')
                ->references('id')
                ->on('tags')
                ->onDelete('cascade');

            $table->primary(['media_id', 'tag_id']);
        });
    }
};