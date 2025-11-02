<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->index(['created_at', 'size', 'quiz_played'], 'media_performance_index');
            $table->index('user_id', 'media_user_id_index');
            $table->index('quiz_played', 'media_quiz_played_index');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex('media_performance_index');
            $table->dropIndex('media_user_id_index');
            $table->dropIndex('media_quiz_played_index');
        });
    }
};