# Stripe Subscription Payment API

A CodeIgniter REST API for managing Stripe subscription payments.

## Installation

```bash
composer require stripe/stripe-php
```

## Configuration

Set your Stripe API keys:
- `STRIPE_LIVE_SECRET_KEY`
- `STRIPE_TEST_SECRET_KEY`

## API Endpoints

### POST `/pay_subscription`
Create a subscription payment.

**Required Parameters:**
- `new_pm_yn` - New payment method? (Y/N)
- `payment_method_id` - Payment method ID
- `product_price_id` - Product price ID
- `payment_mode` - `live` or `test`

**Optional:**
- `stripe_customer_id` - Customer ID
- `option1_price_id` - One-time option 1
- `option2_price_id` - One-time option 2
- `promo_id` - Promotion code
- `trial_period_days` - Free trial days
- `trial_end` - Start date

### POST `/cancel_subscription`
Cancel at period end.

**Parameters:**
- `sub_id` - Subscription ID

### POST `/reactive_subscription`
Reactivate canceled subscription.

**Parameters:**
- `sub_id` - Subscription ID

### DELETE `/delete_subscription_immediately`
Delete subscription now.

**Parameters:**
- `sub_id` - Subscription ID

### GET `/validate_promotion_code`
Check promotion code validity.

**Parameters:**
- `check_promo_code` - Code to validate

### POST `/webhooks`
Handle Stripe events.

**Events:**
- `invoice.finalized`
- `invoice.payment_succeeded`
- `invoice.payment_failed`
- `invoice.payment_action_required`

## Example

```php
$data = [
    'payment_mode' => 'test',
    'new_pm_yn' => 'Y',
    'payment_method_id' => 'pm_xxx',
    'product_price_id' => 'price_xxx',
    'email' => 'user@example.com',
    'first_name' => 'John',
    'last_name' => 'Doe'
];
```

## Logic Map

[Logic Map Presentation](https://docs.google.com/presentation/d/17xoTgWqj8kN0tVNLH4jdWJyyelJhAqniBu1QZ_R9nMl)

## Dependencies

- Stripe PHP Library
- REST Controller

## Reference

- [Stripe Docs](https://stripe.com/docs/api)
- [Stripe PHP](https://github.com/stripe/stripe-php)
