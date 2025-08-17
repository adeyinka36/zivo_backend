<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\GetPaymentHistoryRequest;
use App\Http\Requests\Payment\RequestRefundRequest;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {}

    /**
     * Handle Stripe webhooks
     */
    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        // Validate webhook signature
        if (!$this->paymentService->validateWebhookSignature($payload, $signature)) {
            Log::error('Invalid webhook signature');
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                config('stripe.webhook_secret')
            );

            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $payment = $this->paymentService->confirmPayment($event->data->object->id);
                    break;

                case 'payment_intent.payment_failed':
                    $payment = $this->paymentService->handlePaymentFailure(
                        $event->data->object->id,
                        $event->data->object->last_payment_error->message ?? 'Payment failed'
                    );
                    break;

                case 'payment_intent.canceled':
                    $payment = $this->paymentService->handlePaymentFailure(
                        $event->data->object->id,
                        'Payment canceled'
                    );
                    break;

                case 'charge.refunded':
                    // Handle refund webhook if needed
                    break;

                default:
                    Log::info('Unhandled webhook event', ['event_type' => $event->type]);
            }

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Get payment status
     */
    public function getPaymentStatus(Request $request, string $paymentId)
    {
        $payment = Payment::where('id', $paymentId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        $response = [
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'paid_at' => $payment->paid_at,
            'failure_reason' => $payment->failure_reason,
        ];

        return response()->json($response);
    }

    /**
     * Get user's payment history
     */
    public function getPaymentHistory(GetPaymentHistoryRequest $request)
    {
        $perPage = $request->input('per_page', 15);
        $page = $request->input('page', 1);
        
        $query = Payment::where('user_id', $request->user()->id)
            ->with(['media:id,name,file_name']);

        // Apply status filter if provided
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Apply date range filter if provided
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'payments' => $payments->items(),
            'pagination' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /**
     * Request refund
     */
    public function requestRefund(RequestRefundRequest $request, Payment $payment)
    {
        // Validate payment ownership
        if ($payment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $refundResult = $this->paymentService->processRefund(
                $payment,
                $request->input('amount'),
                $request->input('reason')
            );

            return response()->json([
                'message' => 'Refund processed successfully',
                'refund_id' => $refundResult['refund_id'],
                'amount' => $refundResult['amount'],
                'status' => $refundResult['status'],
            ]);

        } catch (\Exception $e) {
            Log::error('Refund request failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Refund request failed: ' . $e->getMessage(),
            ], 500);
        }
    }
} 