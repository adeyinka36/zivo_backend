<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('creator_media')) {
            Schema::create('creator_media', function (Blueprint $table) {
                $table->id();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_media');
    }
};
