<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_codes')) {
            Schema::create('campaign_codes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('media_id');
                $table->uuid('user_id')->nullable();
                $table->string('code', 32)->unique();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamps();

                $table->foreign('media_id')->references('id')->on('media')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
                $table->index(['media_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_codes');
    }
};
