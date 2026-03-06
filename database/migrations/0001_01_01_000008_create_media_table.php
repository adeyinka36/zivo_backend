<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media')) {
            Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('creator_id')->constrained('creators')->cascadeOnDelete();
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type');
            $table->string('media_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->string('path');
            $table->integer('rounds')->default(1);
            $table->string('temp_file_path')->nullable();
            $table->string('disk');
            $table->string('thumbnail')->nullable();
            $table->text('description')->nullable();
            $table->string('offer')->nullable();
            $table->timestamp('from_date')->nullable();
            $table->timestamp('to_date')->nullable();
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->decimal('amount_paid', 10, 2)->nullable();
            $table->json('location_selections')->nullable();
            $table->integer('view_count')->default(0);
            $table->integer('rounds_completed')->default(0);
            $table->boolean('campaign_completed')->default(false);
            $table->boolean('active')->default(false);
            $table->timestamp('target_reached_at')->nullable();
            $table->timestamps();

            $table->index('creator_id', 'media_creator_id_index');
            $table->index(['created_at', 'size'], 'media_performance_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
