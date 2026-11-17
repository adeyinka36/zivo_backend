<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table) {
            if (! Schema::hasColumn('creators', 'postcode')) {
                $table->string('postcode', 16)->nullable()->after('address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table) {
            if (Schema::hasColumn('creators', 'postcode')) {
                $table->dropColumn('postcode');
            }
        });
    }
};
