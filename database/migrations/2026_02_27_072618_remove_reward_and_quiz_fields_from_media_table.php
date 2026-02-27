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
            // Remove legacy reward / quiz columns as we now use
            // a simple “watch → quiz → email offer” model.
            if (Schema::hasColumn('media', 'reward')) {
                $table->dropColumn('reward');
            }

            if (Schema::hasColumn('media', 'has_reward')) {
                $table->dropColumn('has_reward');
            }

            if (Schema::hasColumn('media', 'quiz_played')) {
                $table->dropColumn('quiz_played');
            }

            if (Schema::hasColumn('media', 'quiz_number')) {
                $table->dropColumn('quiz_number');
            }

            if (Schema::hasColumn('media', 'prize_won')) {
                $table->dropColumn('prize_won');
            }

            if (Schema::hasColumn('media', 'prize_distributed')) {
                $table->dropColumn('prize_distributed');
            }

            if (Schema::hasColumn('media', 'radius')) {
                $table->dropColumn('radius');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // Restore legacy columns with the same types/defaults
            // as originally defined in 2024_03_21_000000_create_media_table.
            if (!Schema::hasColumn('media', 'reward')) {
                $table->bigInteger('reward')->default(0);
            }

            if (!Schema::hasColumn('media', 'has_reward')) {
                $table->boolean('has_reward')->default(true);
            }

            if (!Schema::hasColumn('media', 'quiz_played')) {
                $table->boolean('quiz_played')->default(false);
            }

            if (!Schema::hasColumn('media', 'quiz_number')) {
                $table->integer('quiz_number')->default(10);
            }

            if (!Schema::hasColumn('media', 'prize_won')) {
                $table->boolean('prize_won')->default(false);
            }

            if (!Schema::hasColumn('media', 'prize_distributed')) {
                $table->boolean('prize_distributed')->default(false);
            }

            if (!Schema::hasColumn('media', 'radius')) {
                $table->integer('radius')->nullable();
            }
        });
    }
};
