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
        Schema::table('questions', function (Blueprint $table) {
            $table->string('option_a', 255)->nullable()->after('answer');
            $table->string('option_b', 255)->nullable()->after('option_a');
            $table->string('option_c', 255)->nullable()->after('option_b');
            $table->string('option_d', 255)->nullable()->after('option_c');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['option_a', 'option_b', 'option_c', 'option_d']);
        });
    }
};
