<?php

namespace Tests\Unit;

use App\Models\Creator;
use App\Models\Media;
use App\Services\PaymentService;
use Tests\TestCase;

class PaymentServiceChargingTest extends TestCase
{
    public function test_zero_cost_disables_charging_and_skips_stripe_minimum(): void
    {
        config([
            'services.media.cost_per_round' => 0,
            'stripe.minimum_amount' => 50,
        ]);

        $creator = Creator::factory()->create();
        $media = Media::factory()->create([
            'creator_id' => $creator->id,
            'rounds' => 4,
        ]);

        $paymentService = $this->app->make(PaymentService::class);

        $this->assertFalse($paymentService->isChargingEnabled());

        $amounts = $paymentService->calculateExpectedPaymentAmount($media);
        $this->assertSame(0, $amounts['total_amount_cents']);

        $result = $paymentService->createPaymentIntent($media, $creator);

        $this->assertFalse($result['requires_payment']);
        $this->assertNull($result['client_secret']);
        $this->assertDatabaseHas('payments', [
            'id' => $result['payment_id'],
            'media_id' => $media->id,
            'status' => 'succeeded',
            'amount' => 0,
        ]);
    }

    public function test_positive_cost_still_applies_stripe_minimum(): void
    {
        config([
            'services.media.cost_per_round' => 0.25,
            'stripe.minimum_amount' => 50,
        ]);

        $media = Media::factory()->make([
            'rounds' => 1,
        ]);

        $paymentService = $this->app->make(PaymentService::class);

        $this->assertTrue($paymentService->isChargingEnabled());

        $amounts = $paymentService->calculateExpectedPaymentAmount($media);
        $this->assertSame(50, $amounts['total_amount_cents']);
    }
}
