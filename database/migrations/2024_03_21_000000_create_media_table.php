<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type');
            $table->string('media_type')->nullable();
            $table->bigInteger('reward');
            $table->unsignedInteger('rounds')->default(1);
            $table->unsignedBigInteger('size');
            $table->string('path');
            $table->string('disk');
            $table->string('thumbnail')->nullable();
            $table->text('description')->nullable();
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->decimal('amount_paid', 10, 2)->nullable();
            $table->boolean('has_reward')->default(true);
            $table->boolean('quiz_played')->default(false);
            $table->integer('quiz_number')->default(10);
            $table->integer('view_count')->default(0);
            $table->integer('rounds_completed')->default(0);
            $table->boolean('prize_won')->default(false);
            $table->boolean('prize_distributed')->default(false);
            $table->boolean('campaign_completed')->default(false);
            $table->boolean('active')->default(false);

            $table->timestamps();

            // Indexes
            $table->index(['created_at', 'size', 'quiz_played'], 'media_performance_index');
            $table->index('user_id', 'media_user_id_index');
            $table->index('quiz_played', 'media_quiz_played_index');
            $table->index(['quiz_played', 'size', 'created_at'], 'media_main_query_index');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
