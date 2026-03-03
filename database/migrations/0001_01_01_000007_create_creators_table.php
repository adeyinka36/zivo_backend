<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('creators')) {
            Schema::create('creators', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('email');
                $table->string('password');
                $table->timestamp('email_verified_at')->nullable();
                $table->string('name');
                $table->string('business_name');
                $table->string('phone')->nullable();
                $table->string('address')->nullable();
                $table->string('secret');
                $table->string('crn')->nullable();
                $table->boolean('is_limited_company')->default(false);
                $table->string('proof_of_address_type')->nullable();
                $table->string('proof_of_address_path')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('creators');
    }
};
