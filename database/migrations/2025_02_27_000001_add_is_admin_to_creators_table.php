<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add is_admin column to creators table. Only creators with is_admin true
     * may use the impersonation API to log in as another creator.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table) {
            if (!Schema::hasColumn('creators', 'is_admin')) {
                $table->boolean('is_admin')->default(false)->after('proof_of_address_path');
            }
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table) {
            if (Schema::hasColumn('creators', 'is_admin')) {
                $table->dropColumn('is_admin');
            }
        });
    }
};
