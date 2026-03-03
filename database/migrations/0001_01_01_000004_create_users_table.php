<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->integer('zivos')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('push_token')->nullable();
            $table->boolean('push_notifications')->default(true);
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();

            $table->index('location_id');
            $table->index(['latitude', 'longitude']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
