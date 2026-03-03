<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->foreignUuid('creator_id')->nullable()->constrained('creators')->cascadeOnDelete();
            $table->foreignUuid('media_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_reference')->unique();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_charge_id')->nullable();
            $table->enum('status', ['pending', 'succeeded', 'failed', 'canceled', 'refunded']);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('usd');
            $table->string('payment_method')->nullable();
            $table->json('gateway_response')->nullable();
            $table->text('failure_reason')->nullable();
            $table->boolean('media_uploaded')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['media_id', 'status']);
            $table->index(['creator_id', 'status']);
            $table->index('transaction_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
