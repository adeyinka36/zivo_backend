# Stripe Payment Integration Setup Guide

## Environment Variables

Add the following environment variables to your `.env` file:

```env
# Stripe Configuration
STRIPE_PUBLISHABLE_KEY=pk_test_your_publishable_key_here
STRIPE_SECRET_KEY=sk_test_your_secret_key_here
STRIPE_WEBHOOK_SECRET=whsec_your_webhook_secret_here
STRIPE_CURRENCY=usd
STRIPE_MERCHANT_IDENTIFIER=merchant.com.zivo.app
```

## Stripe Dashboard Setup

1. **Create a Stripe Account**: Sign up at https://stripe.com
2. **Get API Keys**: 
   - Go to Developers > API keys
   - Copy your publishable key and secret key
   - Use test keys for development

3. **Configure Webhooks**:
   - Go to Developers > Webhooks
   - Add endpoint: `https://your-domain.com/api/v1/webhooks/stripe`
   - Select events:
     - `payment_intent.succeeded`
     - `payment_intent.payment_failed`
     - `payment_intent.canceled`
     - `charge.refunded`
   - Copy the webhook signing secret

4. **Apple Pay Setup** (iOS):
   - Go to Settings > Payment methods > Apple Pay
   - Add your domain to the allowed domains
   - Configure merchant identifier

5. **Google Pay Setup** (Android):
   - Go to Settings > Payment methods > Google Pay
   - Add your domain to the allowed domains

## Database Migration

Run the migrations to create the payment tables:

```bash
php artisan migrate
```

## Testing

Use Stripe's test card numbers:
- Success: `4242 4242 4242 4242`
- Decline: `4000 0000 0000 0002`
- Insufficient funds: `4000 0000 0000 9995`

## Security Notes

- Never commit your Stripe secret keys to version control
- Use environment variables for all sensitive data
- Enable webhook signature verification
- Implement proper error handling and logging
- Use HTTPS in production 