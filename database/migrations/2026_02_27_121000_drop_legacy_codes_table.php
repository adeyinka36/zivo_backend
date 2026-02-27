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
        // Drop legacy 'codes' table if it still exists.
        if (Schema::hasTable('codes')) {
            Schema::table('codes', function (Blueprint $table) {
                // Drop foreign keys first where possible to avoid constraint errors.
                if (Schema::hasColumn('codes', 'creator_id')) {
                    $table->dropForeign(['creator_id']);
                }
                if (Schema::hasColumn('codes', 'winner_id')) {
                    $table->dropForeign(['winner_id']);
                }
            });

            Schema::drop('codes');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left empty. The legacy 'codes' table is deprecated
        // and should not be recreated in new environments.
    }
};

