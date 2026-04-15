<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_codes') && ! Schema::hasColumn('campaign_codes', 'claimed_at')) {
            Schema::table('campaign_codes', function (Blueprint $table): void {
                $table->timestamp('claimed_at')->nullable()->after('assigned_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('campaign_codes') && Schema::hasColumn('campaign_codes', 'claimed_at')) {
            Schema::table('campaign_codes', function (Blueprint $table): void {
                $table->dropColumn('claimed_at');
            });
        }
    }
};
