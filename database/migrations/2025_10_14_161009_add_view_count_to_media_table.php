<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->integer('view_count')->default(0)->after('quiz_number');
        });

        DB::statement('
            UPDATE media 
            SET view_count = (
                SELECT COUNT(*) 
                FROM media_users_watched 
                WHERE media_users_watched.media_id = media.id
            )
        ');
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('view_count');
        });
    }
};