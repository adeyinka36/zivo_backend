<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('region_name');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('country');
            $table->string('country_code', 2)->nullable();
            $table->string('continent');
            $table->string('timezone');
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
            $table->index('region_name');
            $table->index('country');
            $table->index('country_code');
            $table->index('continent');
            $table->unique(['country', 'region_name'], 'locations_country_region_unique');
            $table->index(['region_name', 'country'], 'locations_region_country_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
