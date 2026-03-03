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

Zivo uses **Stripe** as the payment gateway. The payment flow follows an upload-before-payment approach:

1. **File Upload & Payment Intent Creation**: Creator provides metadata and file; file is uploaded to temporary storage and verified; payment intent is created (creator is the payer).
2. **Payment Processing**: Creator completes payment via Stripe.
3. **Media Processing After Payment**: After successful payment, the pre-uploaded temp file is used for processing.

Payments are **creator** payments: the authenticated payer is a `Creator`; the Payment record stores `creator_id` (and optionally `user_id` for legacy). Endpoints such as payment status, history, and refund require creator authentication.

This design ensures:
- **File upload completes before payment** - No payment is taken if upload fails
- **Upload is verified** - File existence is confirmed before payment intent creation
- **Reliability** - Temp file is preserved for processing after payment
- **Fallback** - If temp file is missing, file can be re-uploaded (backward compatible)

### Payment Calculation

The total payment amount is calculated on the backend from the Media record:
- **Cost per round** is configurable via `MEDIA_COST_PER_ROUND` (in major currency units, e.g. dollars). The backend may use a fixed cents-per-round value (e.g. 500 cents = $5 per round) until config is wired.
- **Total Amount** = (cost per round in cents) × `rounds`
- **Platform Fee** = 0 (no separate platform fee in the current formula)

**Example**: If cost per round is $5 (500 cents) and rounds is 2:
- Total Amount = 500 × 2 = 1000 cents ($10)

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
User fills form (reward, rounds, locations, questions, description, file)
    ↓
User clicks "Pay" button
    ↓
Frontend validates form
    ↓
POST /api/media/payment-intent (with file as FormData)
    ↓
Backend creates draft Media record
    ↓
Backend uploads file to temp storage (temp_uploads/)
    ↓
Backend verifies file exists
    ↓
Backend stores temp_file_path in Media record
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
When status = "succeeded", frontend calls upload-after-payment (without file)
    ↓
POST /api/media/upload-after-payment (no file sent)
    ↓
Backend verifies payment status = "succeeded"
    ↓
Backend checks for temp_file_path in Media record
    ↓
Backend uses pre-uploaded temp file (or falls back to uploaded file if missing)
    ↓
Backend dispatches UploadFileToS3Job
    ↓
UploadFileToS3Job uploads to S3 and dispatches ProcessMediaUploadJob
    ↓
ProcessMediaUploadJob updates Media record (path, disk, payment_status, active)
    ↓
Media model's updated event fires
    ↓
Dependent jobs dispatched independently:
    - GenerateThumbnailJob (if video)
    - ExpandLocationSelectionsJob (if locations exist)
    - AssignMediaToUsersJob (after locations expanded, or directly if no locations)
    - SendPushNotificationJob
    - SendEmailNotificationJob
    
Note: File upload happens BEFORE payment, ensuring upload completes before payment is taken.
If upload fails, payment intent is not created (transaction rollback).
Temp files are cleaned up after 24 hours by CleanupTempUploadsJob.
    ↓
Media is live and assigned to users
```

### Payment Intent Creation Flow

```
Frontend: CreatePage / CreateScreen
    ↓
User fills form (including file) and clicks "Pay"
    ↓
handlePayment() called
    ↓
PaymentService.createPaymentIntent(metadata, file)
    ↓
POST /api/media/payment-intent
    Body: FormData {
        file: File (required),
        rounds: number,
        offer: string,
        from_date, to_date,
        location_selections or location_id,
        questions: array,
        ...
    }
    ↓
Backend: MediaController::createPaymentIntent()
    ↓
Validates request (file max 5GB, formats, etc.)
    ↓
MediaService::createDraftMedia()
    - Creates Media record with metadata
    - Sets mime_type = 'pending'
    - Sets size = 0
    ↓
Uploads file to temp storage:
    - Stores in temp_uploads/ directory
    - Generates unique filename
    - Verifies file was stored successfully
    ↓
Updates Media record:
    - temp_file_path = path to temp file
    - name, file_name, mime_type, size from uploaded file
    ↓
PaymentService::createPaymentIntent(Media $media, Creator $creator)
    - Checks for existing pending payment for this media
    - Calculates expected amount (server-side): cost per round × rounds
    - Creates Stripe PaymentIntent (metadata: media_id, creator_id, ...)
    - Creates Payment record (creator_id, media_id, status pending, amount, ...)
    - Returns client_secret and payment_id
    ↓
Frontend receives response
    ↓
Frontend navigates to payment screen/modal
    ↓
Note: If file upload fails, transaction rolls back and payment intent is not created
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
uploadMediaAfterPayment() called (without file)
    ↓
PaymentService.uploadAfterPayment() (file is undefined/optional)
    ↓
POST /api/media/upload-after-payment
    Body: FormData {
        payment_id: string,
        file: File (optional - only if not pre-uploaded),
        offer, from_date, to_date,
        rounds: number,
        location_selections or location_id,
        questions: array,
        ...
    }
    ↓
Backend: MediaController::uploadAfterPayment()
    ↓
Verifies Payment status = "succeeded"
    ↓
Retrieves Media record from Payment
    ↓
Checks if temp_file_path exists and file exists on disk
    ↓
If temp file exists:
    - Uses pre-uploaded temp file
    - Uses metadata from Media record (name, mime_type, size)
    ↓
If temp file missing (fallback):
    - Requires file in request
    - Uploads file to temp storage
    - Uses metadata from uploaded file
    ↓
Verifies amounts match Media record (security check)
    ↓
Dispatches UploadFileToS3Job (with temp file path)
    ↓
UploadFileToS3Job uploads to S3 and dispatches ProcessMediaUploadJob
    ↓
ProcessMediaUploadJob updates Media record (path, disk, payment_status, active)
    ↓
Media model's updated event fires
    ↓
Dependent jobs dispatched independently:
    - GenerateThumbnailJob (if video)
    - ExpandLocationSelectionsJob (if locations exist)
    - AssignMediaToUsersJob (after locations expanded)
    - SendPushNotificationJob
    - SendEmailNotificationJob
    ↓
Returns success response
    ↓
Frontend navigates to campaigns (or profile) page
```

---

## Backend Implementation

### PaymentService

**Location**: `app/Services/PaymentService.php`

#### Key Methods

1. **`calculateExpectedPaymentAmount(Media $media)`**
   - Calculates payment amount from Media record (server-side)
   - Returns: `['reward_amount_cents', 'platform_fee_cents', 'total_amount_cents']`
   - Formula: cost per round (e.g. from `config('services.media.cost_per_round')` or fixed cents) × `rounds`; platform fee currently 0

2. **`createPaymentIntent(Media $media, Creator $creator)`**
   - Checks for existing pending payment for this media (prevents duplicates)
   - Creates Stripe PaymentIntent with metadata (media_id, creator_id, ...)
   - Creates Payment record with `creator_id`, `media_id`, status pending, amount
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
   - Returns payment status for polling (creator-authenticated)
   - Queries by `creator_id`; returns `{ payment_id, status, amount, currency, paid_at, failure_reason }`

3. **`GET /api/payments/history`**
   - Returns creator's payment history (creator-authenticated)
   - Supports pagination and filtering (status, date range)

4. **`POST /api/payments/{payment}/refund`**
   - Processes refund request (creator-authenticated)
   - Validates payment ownership via `creator_id`
   - Returns refund details

### MediaController

**Location**: `app/Http/Controllers/Api/MediaController.php`

#### Payment-Related Endpoints

1. **`POST /api/media/payment-intent`**
   - Accepts file as FormData (optional but recommended)
   - Uploads file to temp storage if provided
   - Verifies file exists before creating payment intent
   - Creates draft Media record with temp_file_path
   - Creates payment intent
   - Returns `client_secret` and `payment_id`
   - **Note**: If file upload fails, transaction rolls back and payment intent is not created

2. **`POST /api/media/upload-after-payment`**
   - Handles media processing after payment
   - Verifies payment status = "succeeded"
   - Uses pre-uploaded temp file if available (from createPaymentIntent)
   - Falls back to uploaded file if temp file is missing (backward compatible)
   - Dispatches UploadFileToS3Job for async processing

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

1. **`createPaymentIntent(metadata: PaymentMetadata, file?: File)`**
   - POST `/api/media/payment-intent`
   - Sends file as FormData if provided
   - Returns: `{ client_secret, payment_id }`
   - File is uploaded to temp storage before payment intent creation

2. **`uploadAfterPayment(request: UploadAfterPaymentRequest)`**
   - POST `/api/media/upload-after-payment`
   - File is optional (uses pre-uploaded temp file if available)
   - Uses FormData if file is provided (fallback only)

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

1. Creator fills form (offer, rounds, dates, locations, questions, file)
2. `handlePayment()` called on "Pay" button click
3. Validates form
4. Calls `PaymentService.createPaymentIntent()`
5. Opens payment modal with `client_secret`
6. User completes payment via `PaymentForm`
7. `handlePaymentSuccess()` called
8. Polls payment status
9. When succeeded, calls `uploadMediaAfterPayment()`
10. Navigates to campaigns (or profile) page

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
  - Navigates to campaigns or profile

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
   - Payment creation and upload use creator auth (`ensure.creator`)
   - Status, history, and refund require creator auth; payments are keyed by `creator_id`

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
Backend validates payment ownership (creator_id)
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
- Creator must own the payment (`creator_id` matches authenticated creator)

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
- `creator_id`: Foreign key to creators (payer for campaign uploads)
- `user_id`: Optional, legacy
- `media_id`: Foreign key to media
- `transaction_reference`: Unique transaction reference
- `stripe_payment_intent_id`: Stripe PaymentIntent ID
- `stripe_charge_id`: Stripe Charge ID (after payment)
- `status`: `pending`, `succeeded`, `failed`, `refunded`
- `amount`: Total amount (e.g. in major currency units)
- `currency`: Currency code (e.g., 'usd')
- `paid_at`: Timestamp when payment succeeded
- `failure_reason`: Reason for failure
- `gateway_response`: JSON of Stripe response
- `created_at`, `updated_at`

### Media Model (Payment-Related Fields)

- `rounds`: Number of rounds (used with cost per round for total amount)
- `mime_type`: Set to 'pending' before upload
- `size`: Set to 0 before upload
- No `reward` field; pricing is cost per round from config × rounds

---

## Configuration

### Environment Variables

**Backend**:
- `STRIPE_PUBLISHABLE_KEY`: Stripe publishable key
- `STRIPE_SECRET_KEY`: Stripe secret key
- `STRIPE_WEBHOOK_SECRET`: Webhook signing secret
- `STRIPE_CURRENCY`: Currency code (default: 'usd')
- `STRIPE_MINIMUM_AMOUNT`: Optional; minimum charge in smallest currency unit (e.g. 50 for 50 cents USD). Default 50. Stripe requires e.g. 50¢ USD minimum.
- `MEDIA_COST_PER_ROUND`: Cost per round in major currency (e.g. 0.25 for $0.25, 0.01 for $0.01). Used for the actual charge; if total is below `STRIPE_MINIMUM_AMOUNT`, the charge is raised to the minimum.

**Frontend**:
- Publishable key can be read from `/platform-info` (`payments.stripe_publishable_key`) or from env (e.g. `NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY`, `EXPO_PUBLIC_STRIPE_PUBLISHABLE_KEY`)

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
