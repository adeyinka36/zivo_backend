# Payment Flow Documentation

This document explains how payments work across the Zivo platform: backend, web app (`zivo_web`), and mobile app (`zivo_app`).

## Table of Contents
1. [Overview](#overview)
2. [Payment Architecture](#payment-architecture)
3. [Payment Flow Diagrams](#payment-flow-diagrams)
4. [Backend Implementation](#backend-implementation)
5. [Web App Implementation](#web-app-implementation)
6. [Mobile App Implementation](#mobile-app-implementation)
7. [Security Measures](#security-measures)
8. [Error Handling](#error-handling)
9. [Webhook Processing](#webhook-processing)
10. [Refund Process](#refund-process)

---

## Overview

Zivo uses **Stripe** as the payment gateway. The payment flow follows a two-phase approach:

1. **Payment Intent Creation**: User provides metadata (reward amount, rounds, locations, etc.) and a payment intent is created
2. **Media Upload After Payment**: After successful payment, the actual media file is uploaded

This design ensures payment is completed before processing expensive operations (file storage, media processing, user assignments).

### Payment Calculation

The total payment amount is calculated as:
- **Reward Amount** = `reward` (per round) × `rounds`
- **Platform Fee** = 10% of reward amount
- **Total Amount** = Reward Amount + Platform Fee

**Example**: If reward is $10 and rounds is 2:
- Reward Amount = $10 × 2 = $20
- Platform Fee = $20 × 0.10 = $2
- Total Amount = $22

---

## Payment Architecture

### Components

1. **Backend (`zivo_backend`)**
   - `PaymentService`: Core payment logic
   - `PaymentController`: API endpoints
   - `MediaController`: Payment intent creation and upload handling
   - Stripe webhook handler

2. **Web App (`zivo_web`)**
   - `PaymentService`: API client for payment operations
   - `PaymentForm`: Stripe Elements card form
   - `CreatePage`: Payment flow orchestration

3. **Mobile App (`zivo_app`)**
   - `PaymentService`: API client for payment operations
   - `PaymentScreen`: Stripe React Native SDK integration
   - `CreateScreen`: Payment flow orchestration

### Payment States

- `pending`: Payment intent created, awaiting payment
- `succeeded`: Payment completed successfully
- `failed`: Payment failed or was canceled
- `refunded`: Payment was refunded

---

## Payment Flow Diagrams

### Complete Payment Flow

```
User fills form (reward, rounds, locations, questions, description)
    ↓
User clicks "Pay" button
    ↓
Frontend validates form
    ↓
POST /api/media/payment-intent
    ↓
Backend creates draft Media record
    ↓
Backend calculates payment amount (server-side)
    ↓
Backend creates Stripe PaymentIntent
    ↓
Backend creates Payment record (status: pending)
    ↓
Backend returns client_secret and payment_id
    ↓
Frontend displays payment form/modal
    ↓
User enters payment details (card/Apple Pay/Google Pay)
    ↓
Stripe processes payment
    ↓
Stripe sends webhook: payment_intent.succeeded
    ↓
Backend updates Payment status to "succeeded"
    ↓
Frontend polls payment status
    ↓
When status = "succeeded", frontend uploads media file
    ↓
POST /api/media/upload-after-payment
    ↓
Backend verifies payment status = "succeeded"
    ↓
Backend stores media file (S3/local)
    ↓
Backend dispatches ProcessMediaUploadJob
    ↓
ProcessMediaUploadJob completes successfully
    ↓
ProcessMediaUploadJob dispatches dependent jobs independently:
    - GenerateThumbnailJob (if video)
    - AssignMediaToUsers
    - SendPushNotificationJob
    
Note: Dependent jobs only run if ProcessMediaUploadJob succeeds.
Dependent jobs run independently - if one fails, others continue processing.
    ↓
Media is live and assigned to users
```

### Payment Intent Creation Flow

```
Frontend: CreatePage / CreateScreen
    ↓
User fills form and clicks "Pay"
    ↓
handlePayment() called
    ↓
PaymentService.createPaymentIntent(metadata)
    ↓
POST /api/media/payment-intent
    Body: {
        reward: number,
        rounds: number,
        locations: array,
        questions: array,
        description: string
    }
    ↓
Backend: MediaController::createPaymentIntent()
    ↓
MediaService::createDraftMedia()
    - Creates Media record with metadata
    - Sets mime_type = 'pending'
    - Sets size = 0
    ↓
PaymentService::createPaymentIntent()
    - Checks for existing pending payment
    - Calculates expected amounts (server-side)
    - Creates Stripe PaymentIntent
    - Creates Payment record
    - Returns client_secret and payment_id
    ↓
Frontend receives response
    ↓
Frontend navigates to payment screen/modal
```

### Payment Processing Flow

```
Frontend: PaymentScreen / PaymentForm
    ↓
User selects payment method:
    - Card (Stripe Elements / Payment Sheet)
    - Apple Pay
    - Google Pay
    ↓
Stripe SDK processes payment
    ↓
Payment submitted to Stripe
    ↓
Stripe processes payment
    ↓
Two paths:
    1. Webhook (async) → Backend updates payment status
    2. Frontend polling → Frontend checks status
    ↓
When status = "succeeded":
    Frontend uploads media file
```

### Media Upload After Payment Flow

```
Frontend: PaymentScreen / CreatePage
    ↓
Payment status = "succeeded"
    ↓
uploadMediaAfterPayment() called
    ↓
PaymentService.uploadAfterPayment()
    ↓
POST /api/media/upload-after-payment
    Body: FormData {
        payment_id: string,
        file: File,
        reward: number,
        rounds: number,
        description: string,
        location_selections: array,
        questions: array
    }
    ↓
Backend: MediaController::uploadAfterPayment()
    ↓
Verifies Payment status = "succeeded"
    ↓
Retrieves Media record from Payment
    ↓
Stores file to S3/local storage
    ↓
Verifies amounts match Media record (security check)
    ↓
Updates Media record with file metadata
    ↓
Syncs locations
    ↓
Dispatches ProcessMediaUploadJob
    ↓
ProcessMediaUploadJob completes successfully
    ↓
ProcessMediaUploadJob dispatches dependent jobs independently:
    GenerateThumbnailJob (if video)
    AssignMediaToUsers
    SendPushNotificationJob
    ↓
Returns success response
    ↓
Frontend navigates to profile page
```

---

## Backend Implementation

### PaymentService

**Location**: `app/Services/PaymentService.php`

#### Key Methods

1. **`calculateExpectedPaymentAmount(Media $media)`**
   - Calculates payment amount from Media record (server-side)
   - Returns: `['reward_amount_cents', 'platform_fee_cents', 'total_amount_cents']`
   - Formula: `reward × rounds + (reward × rounds × 0.10)`

2. **`createPaymentIntent(Media $media, User $user)`**
   - Checks for existing pending payment (prevents duplicates)
   - Creates Stripe PaymentIntent with metadata
   - Creates Payment record in database
   - Returns `client_secret` and `payment_id`
   - Uses idempotency key to prevent duplicate intents

3. **`verifyPaymentAmount(Payment $payment, int $actualAmountCents)`**
   - Verifies actual payment amount matches expected amount
   - Logs security events if mismatch detected
   - Returns boolean

4. **`confirmPayment(string $paymentIntentId)`**
   - Called by webhook handler
   - Retrieves payment intent from Stripe
   - Verifies payment amount
   - Updates Payment status to "succeeded"
   - Logs payment success

5. **`handlePaymentFailure(string $paymentIntentId, string $reason)`**
   - Updates Payment status to "failed"
   - Stores failure reason
   - Logs payment failure

6. **`processRefund(Payment $payment, float $amount, string $reason)`**
   - Creates Stripe refund
   - Updates Payment status to "refunded"
   - Logs refund details

### PaymentController

**Location**: `app/Http/Controllers/Api/PaymentController.php`

#### Endpoints

1. **`POST /api/webhooks/stripe`**
   - Handles Stripe webhook events
   - Validates webhook signature
   - Processes events:
     - `payment_intent.succeeded` → `confirmPayment()`
     - `payment_intent.payment_failed` → `handlePaymentFailure()`
     - `payment_intent.canceled` → `handlePaymentFailure()`

2. **`GET /api/payments/{paymentId}/status`**
   - Returns payment status for polling
   - Response: `{ payment_id, status, amount, currency, paid_at, failure_reason }`

3. **`GET /api/payments/history`**
   - Returns user's payment history
   - Supports pagination and filtering (status, date range)

4. **`POST /api/payments/{paymentId}/refund`**
   - Processes refund request
   - Validates payment ownership
   - Returns refund details

### MediaController

**Location**: `app/Http/Controllers/Api/MediaController.php`

#### Payment-Related Endpoints

1. **`POST /api/media/payment-intent`**
   - Creates payment intent
   - Creates draft Media record
   - Returns `client_secret` and `payment_id`

2. **`POST /api/media/upload-after-payment`**
   - Handles media upload after payment
   - Verifies payment status
   - Stores file and dispatches jobs

### Security Measures

1. **Server-Side Amount Calculation**
   - Payment amount is calculated server-side from Media record
   - Frontend amounts are validated but not trusted

2. **Payment Verification**
   - Actual payment amount is verified against expected amount
   - Mismatches are logged as security events

3. **Webhook Signature Validation**
   - All webhooks are validated using Stripe signature
   - Invalid signatures are rejected

4. **Idempotency Keys**
   - Payment intents use idempotency keys
   - Prevents duplicate payment intents

5. **Payment Status Verification**
   - Media upload requires `payment.status = 'succeeded'`
   - Prevents upload without payment

---

## Web App Implementation

### PaymentService

**Location**: `services/paymentService.ts`

#### Methods

1. **`createPaymentIntent(metadata: PaymentMetadata)`**
   - POST `/api/media/payment-intent`
   - Returns: `{ client_secret, payment_id }`

2. **`uploadAfterPayment(request: UploadAfterPaymentRequest)`**
   - POST `/api/media/upload-after-payment`
   - Uses FormData for file upload

3. **`pollPaymentStatus(paymentId: string)`**
   - GET `/api/payments/{paymentId}/status`
   - Polls until status is "succeeded" or "failed"
   - Used to wait for webhook processing

4. **`getPaymentHistory(params)`**
   - GET `/api/payments/history`
   - Returns paginated payment history

### CreatePage

**Location**: `app/create/page.tsx`

#### Flow

1. User fills form (reward, rounds, locations, questions, description)
2. `handlePayment()` called on "Pay" button click
3. Validates form
4. Calls `PaymentService.createPaymentIntent()`
5. Opens payment modal with `client_secret`
6. User completes payment via `PaymentForm`
7. `handlePaymentSuccess()` called
8. Polls payment status
9. When succeeded, calls `uploadMediaAfterPayment()`
10. Navigates to profile page

### PaymentForm

**Location**: `components/payment/PaymentForm.tsx`

#### Implementation

- Uses Stripe Elements (`@stripe/react-stripe-js`)
- CardElement for card input
- `stripe.confirmCardPayment()` to process payment
- Error handling for various Stripe error codes
- Secure styling for dark theme

---

## Mobile App Implementation

### PaymentService

**Location**: `services/PaymentService.ts`

#### Methods

Same as web app, but uses React Native API client.

### CreateScreen

**Location**: `app/(app)/create.tsx`

#### Flow

1. User fills form
2. `handlePayment()` called
3. Validates form
4. Calls `PaymentService.createPaymentIntent()`
5. Navigates to `PaymentScreen` with params:
   - `clientSecret`
   - `paymentId`
   - `amount`

### PaymentScreen

**Location**: `app/(app)/payment.tsx`

#### Implementation

- Uses Stripe React Native SDK (`@stripe/stripe-react-native`)
- Supports:
  - **Card**: Payment Sheet (`usePaymentSheet`)
  - **Apple Pay**: Platform Pay (`usePlatformPay`)
  - **Google Pay**: Platform Pay (`usePlatformPay`)
- `handlePaymentSuccess()`:
  - Polls payment status
  - When succeeded, uploads media
  - Shows success modal
  - Navigates to profile page

#### Payment Methods

1. **Card Payment**
   - Uses `presentPaymentSheet()`
   - Stripe handles card input UI

2. **Apple Pay**
   - Checks support with `isPlatformPaySupported()`
   - Uses `confirmPlatformPayPayment()` with Apple Pay config

3. **Google Pay**
   - Checks support with `isPlatformPaySupported()`
   - Uses `confirmPlatformPayPayment()` with Google Pay config

---

## Security Measures

### Backend Security

1. **Server-Side Calculation**
   ```php
   // Payment amount calculated from Media record, not frontend
   $expectedAmounts = $this->calculateExpectedPaymentAmount($media);
   $totalAmountCents = $expectedAmounts['total_amount_cents'];
   ```

2. **Amount Verification**
   ```php
   // Verify actual payment matches expected amount
   $amountMatches = $this->verifyPaymentAmount($payment, $actualAmountCents);
   if (!$amountMatches) {
       // Log security event
   }
   ```

3. **Webhook Signature Validation**
   ```php
   if (!$this->paymentService->validateWebhookSignature($payload, $signature)) {
       return response()->json(['error' => 'Invalid signature'], 400);
   }
   ```

4. **Payment Status Verification**
   ```php
   // Only allow upload if payment succeeded
   $payment = Payment::where('id', $paymentId)
       ->where('status', Payment::STATUS_SUCCEEDED)
       ->firstOrFail();
   ```

### Frontend Security

1. **No Sensitive Data Storage**
   - Payment details never stored in frontend
   - Only `client_secret` and `payment_id` stored temporarily

2. **HTTPS Only**
   - All API calls use HTTPS
   - Stripe requires HTTPS for production

3. **Token-Based Authentication**
   - All payment endpoints require authentication
   - User can only access their own payments

---

## Error Handling

### Payment Intent Creation Errors

- **Duplicate Payment**: If payment already exists and succeeded
- **Stripe API Error**: Network or API errors from Stripe
- **Validation Error**: Invalid form data

### Payment Processing Errors

- **Card Declined**: User's card was declined
- **Expired Card**: Card has expired
- **Insufficient Funds**: Card has insufficient funds
- **Processing Error**: Stripe processing error

### Upload Errors

- **Payment Not Found**: Payment ID doesn't exist
- **Payment Not Succeeded**: Payment status is not "succeeded"
- **File Upload Error**: File storage or processing error

### Error Recovery

1. **Payment Intent Creation Failure**
   - User can retry payment intent creation
   - Existing draft Media record is reused

2. **Payment Processing Failure**
   - User can retry payment with same payment intent
   - Or create new payment intent

3. **Upload Failure After Payment**
   - Payment is already completed
   - User can retry upload using same `payment_id`
   - Or contact support for refund

---

## Webhook Processing

### Webhook Endpoint

**URL**: `POST /api/webhooks/stripe`

### Webhook Events Handled

1. **`payment_intent.succeeded`**
   - Payment completed successfully
   - Updates Payment status to "succeeded"
   - Logs payment success

2. **`payment_intent.payment_failed`**
   - Payment failed
   - Updates Payment status to "failed"
   - Stores failure reason

3. **`payment_intent.canceled`**
   - Payment was canceled
   - Updates Payment status to "failed"
   - Stores cancellation reason

### Webhook Security

- Signature validation using `Stripe-Signature` header
- Webhook secret stored in `config('stripe.webhook_secret')`
- Invalid signatures are rejected with 400 error

### Webhook Reliability

- Stripe retries failed webhooks
- Backend should be idempotent (handles duplicate events)
- Payment status can be polled if webhook is delayed

---

## Refund Process

### Refund Flow

```
User requests refund
    ↓
POST /api/payments/{paymentId}/refund
    Body: {
        amount?: number (optional, defaults to full),
        reason: string
    }
    ↓
Backend validates payment ownership
    ↓
PaymentService::processRefund()
    ↓
Creates Stripe refund
    ↓
Updates Payment status to "refunded"
    ↓
Returns refund details
```

### Refund Requirements

- Payment must be successful (`status = 'succeeded'`)
- Payment must have `stripe_charge_id`
- User must own the payment

### Refund Types

1. **Full Refund**: Default if `amount` not provided
2. **Partial Refund**: If `amount` is provided

### Refund Webhook

- Stripe sends `charge.refunded` webhook
- Currently logged but not processed
- Can be extended to update payment status automatically

---

## Payment States and Transitions

```
pending
    ↓ (payment succeeds)
succeeded
    ↓ (refund requested)
refunded

pending
    ↓ (payment fails/cancels)
failed
```

---

## Database Schema

### Payment Model

- `id`: UUID
- `user_id`: Foreign key to users
- `media_id`: Foreign key to media
- `transaction_reference`: Unique transaction reference
- `stripe_payment_intent_id`: Stripe PaymentIntent ID
- `stripe_charge_id`: Stripe Charge ID (after payment)
- `status`: `pending`, `succeeded`, `failed`, `refunded`
- `amount`: Reward amount (not total)
- `currency`: Currency code (e.g., 'usd')
- `paid_at`: Timestamp when payment succeeded
- `failure_reason`: Reason for failure
- `gateway_response`: JSON of Stripe response
- `created_at`, `updated_at`

### Media Model (Payment-Related Fields)

- `reward`: Reward amount per round
- `rounds`: Number of rounds
- `mime_type`: Set to 'pending' before upload
- `size`: Set to 0 before upload

---

## Configuration

### Environment Variables

**Backend**:
- `STRIPE_KEY`: Stripe publishable key
- `STRIPE_SECRET`: Stripe secret key
- `STRIPE_WEBHOOK_SECRET`: Webhook signing secret
- `STRIPE_CURRENCY`: Currency code (default: 'usd')

**Frontend**:
- `NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY`: Stripe publishable key (web)
- `EXPO_PUBLIC_STRIPE_PUBLISHABLE_KEY`: Stripe publishable key (mobile)

### Stripe Configuration

- **Test Mode**: Uses test keys for development
- **Production Mode**: Uses live keys for production
- **Webhook URL**: Must be configured in Stripe Dashboard
- **Webhook Events**: `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`

---

## Testing

### Test Cards (Stripe Test Mode)

- **Success**: `4242 4242 4242 4242`
- **Decline**: `4000 0000 0000 0002`
- **Insufficient Funds**: `4000 0000 0000 9995`

### Test Scenarios

1. **Successful Payment Flow**
   - Create payment intent
   - Complete payment with test card
   - Verify webhook updates status
   - Upload media successfully

2. **Payment Failure**
   - Create payment intent
   - Use declined card
   - Verify status updates to "failed"

3. **Refund Flow**
   - Complete successful payment
   - Request refund
   - Verify refund processed

4. **Duplicate Payment Prevention**
   - Create payment intent
   - Try to create another for same media
   - Verify existing payment intent returned

---

## Troubleshooting

### Payment Intent Creation Fails

- Check Stripe API keys are correct
- Verify network connectivity
- Check backend logs for Stripe errors

### Payment Succeeds But Status Not Updated

- Check webhook endpoint is accessible
- Verify webhook secret is correct
- Check webhook logs in Stripe Dashboard
- Frontend can poll status as fallback

### Upload Fails After Payment

- Verify payment status is "succeeded"
- Check file size and format
- Verify S3/storage configuration
- Check backend logs for errors

### Webhook Not Received

- Verify webhook URL in Stripe Dashboard
- Check webhook secret matches
- Ensure endpoint is publicly accessible
- Check Stripe webhook logs

---

## Notes

- Payment amount is always calculated server-side for security
- Frontend amounts are validated but not trusted
- Media upload requires successful payment
- Webhooks are preferred but polling is used as fallback
- Refunds are processed through Stripe
- All payment operations are logged for audit
