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
        Schema::table('media', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            
            $table->foreignUuid('creator_id')->after('id')->constrained('creators')->cascadeOnDelete();
            $table->index('creator_id', 'media_creator_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropForeign(['creator_id']);
            $table->dropIndex('media_creator_id_index');
            $table->dropColumn('creator_id');
            
            $table->foreignUuid('user_id')->after('id')->constrained('users')->cascadeOnDelete();
            $table->index('user_id', 'media_user_id_index');
        });
    }
};
