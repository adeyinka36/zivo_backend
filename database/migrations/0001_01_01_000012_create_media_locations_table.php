<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_locations', function (Blueprint $table) {
            $table->uuid('media_id');
            $table->unsignedBigInteger('location_id');
            $table->timestamps();

            $table->foreign('media_id')->references('id')->on('media')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            $table->primary(['media_id', 'location_id']);
            $table->index('media_id');
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_locations');
    }
};
