<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending')->after('description');
            $table->string('stripe_payment_intent_id')->nullable()->after('payment_status');
            $table->timestamp('paid_at')->nullable()->after('stripe_payment_intent_id');
            $table->decimal('amount_paid', 10, 2)->nullable()->after('paid_at'); // Store actual amount paid
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn([
                'payment_status',
                'stripe_payment_intent_id',
                'paid_at',
                'amount_paid',
            ]);
        });
    }
};
