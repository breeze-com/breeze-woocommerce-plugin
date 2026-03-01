# Breeze Payment Gateway for WooCommerce
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-purple.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net/)
[![Tests](https://img.shields.io/badge/tests-98%20passed-brightgreen.svg)](#testing)
[![Version](https://img.shields.io/badge/version-1.0.3-green.svg)](https://github.com/breeze-com/breeze-woocommerce-plugin)

![Breeze Payment Gateway](.github/images/banner.png)

## Features

- ✅ Full Breeze API integration (Merchant of Record)
- ✅ Automatic customer creation in Breeze
- ✅ Inline product format — no pre-registration needed
- ✅ Secure payment page redirects with one-time return tokens
- ✅ Webhook support with HMAC SHA256 signature verification
- ✅ Refunds (partial + full) via Breeze API
- ✅ Discount/coupon support — discounted prices sent automatically
- ✅ Test mode and live mode support
- ✅ Currency validation (USD, extensible via filter)
- ✅ HPOS (High-Performance Order Storage) compatible
- ✅ WooCommerce Blocks compatible
- ✅ Multisite compatible
- ✅ PHP 8.2+ compatible (no dynamic property deprecations)
- ✅ PII-safe debug logging
- ✅ 98 unit tests + E2E test suite

## Installation

1. Upload the `breeze-payment-gateway` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments
4. Enable "Breeze"
5. Click "Manage" to configure settings

## Configuration

### Settings

Navigate to **WooCommerce > Settings > Payments > Breeze**

> Don't have a Breeze merchant account yet? [Contact our sales team](https://breeze.com/sales) to get started.

| Setting | Description |
|---------|-------------|
| **Enable/Disable** | Enable or disable the payment gateway |
| **Title** | The title customers see during checkout (default: "Breeze") |
| **Description** | Payment method description shown at checkout |
| **Test Mode** | Enable to use test API credentials |
| **Test API Key** | Your Breeze test environment API key (`sk_test_...`) |
| **Live API Key** | Your Breeze production API key (`sk_live_...`) |
| **Webhook Secret** | HMAC secret for webhook signature verification (`whook_sk_...`) |
| **Preferred Payment Methods** | Optional: limit to Apple Pay, Google Pay, Card, or Crypto |
| **Debug Log** | Enable logging for troubleshooting |

### Getting Your API Key

1. Log in to your [Breeze Dashboard](https://dashboard.breeze.cash)
2. Navigate to Developer > API Keys
3. Generate a new API key
4. Paste the key into the appropriate field (Test or Live)

### Webhook Configuration

In your Breeze Dashboard, set the webhook URL to:
```
https://yoursite.com/?wc-api=breeze_payment_gateway
```

Copy the webhook secret and paste it into the plugin settings.

## How It Works

### Payment Flow

```
Customer browses store → Adds items to cart
    ↓
Clicks "Place Order" with Breeze selected
    ↓
Plugin creates customer in Breeze (or reuses existing)
    ↓
Plugin creates payment page with inline products
    ↓
Customer redirected to pay.breeze.cash
    ↓
Customer pays (Card, Apple Pay, Google Pay, Crypto)
    ↓
Customer redirected back → Order set to "On Hold"
    ↓
Breeze webhook fires → Order set to "Processing" (payment confirmed)
```

**Key design decisions:**
- The **return URL does NOT mark orders as paid**. It sets them to "On Hold" and waits for the webhook. This prevents fake payment confirmations.
- The **webhook is the authoritative confirmation**. Only `PAYMENT_SUCCEEDED` via verified webhook triggers `payment_complete()`.
- **Breeze handles tax**. WooCommerce tax is NOT sent as a line item (Breeze is the Merchant of Record).
- **Discounts are baked into prices**. Line item totals (post-coupon) are used, not catalog prices.

### API Integration

#### Inline Products (POST /v1/payment_pages)
```json
{
  "products": [
    {
      "name": "Breeze Snowboard",
      "amount": 69995,
      "currency": "USD",
      "quantity": 1
    }
  ],
  "billingEmail": "customer@example.com",
  "clientReferenceId": "order-42",
  "successReturnUrl": "https://yoursite.com/?wc-api=breeze_return&order_id=42&status=success&token=abc123",
  "failReturnUrl": "https://yoursite.com/?wc-api=breeze_return&order_id=42&status=failed&token=abc123",
  "customer": { "id": "cus_xxx" }
}
```

#### Refund (POST /v1/payment_pages/{pageId}/refund)
```json
{
  "amount": 2999,
  "reason": "Customer requested refund"
}
```

### Webhook Events

| Event | Action |
|-------|--------|
| `PAYMENT_SUCCEEDED` / `payment.succeeded` | Order → Processing (payment confirmed) |
| `PAYMENT_EXPIRED` / `payment.failed` | Order → Failed (only if not already paid) |

Webhooks are verified via HMAC SHA256 signature. Requires `webhook_secret` to be configured — **webhooks are rejected if no secret is set**.

### Security

| Feature | Implementation |
|---------|---------------|
| **Return URL protection** | One-time token (generated per order, consumed on use) |
| **Webhook signature** | HMAC SHA256 with recursive key sorting, timing-safe comparison |
| **Cross-order protection** | Webhook verifies `pageId` matches stored `_breeze_payment_page_id` |
| **Failure webhook guard** | `PAYMENT_EXPIRED` cannot override already-paid orders |
| **PII-safe logging** | Debug logs redact `billingEmail` and customer data |
| **Input sanitization** | `absint()` on order IDs, `sanitize_text_field()` on all inputs |
| **HTTPS enforced** | API calls to `https://api.breeze.cash` only |

### Order Metadata

| Meta Key | Value |
|----------|-------|
| `_breeze_customer_id` | Breeze customer ID |
| `_breeze_payment_page_id` | Breeze payment page ID (used for refunds + webhook verification) |
| `_breeze_return_token` | One-time return URL token (deleted after use) |

## Refunds

Refunds are processed via the Breeze API directly from WooCommerce admin:

1. Go to the order in WooCommerce admin
2. Click "Refund"
3. Enter the amount and reason
4. Click "Refund via Breeze"

Both **partial** and **full** refunds are supported. Amounts are converted to cents and sent to `POST /v1/payment_pages/{pageId}/refund`.

## Testing

### Unit Tests (98 tests, 262 assertions)

No WordPress or WooCommerce runtime needed. Tests verify gateway logic in isolation.

```bash
php tests/test-gateway.php
```

#### Test Coverage

| Category | Tests | What's covered |
|----------|-------|---------------|
| **Payment Flow** | 1–3 | Return URL → on-hold (not payment_complete), webhook → payment_complete, idempotency |
| **Tax Handling** | 4 | No tax line item sent (Breeze is MoR) |
| **Discounts** | 5–6 | Line item total used (not catalog price), multi-quantity with discount |
| **Shipping** | 7–8 | Shipping line item when > $0, excluded when $0 |
| **Webhook Signatures** | 9–10 | HMAC SHA256 verification, wrong secret rejection |
| **Event Types** | 11 | Both `PAYMENT_SUCCEEDED` and `payment.succeeded` formats |
| **Reference Parsing** | 12 | `order-42` → `42` extraction |
| **Return Tokens** | 13–14 | One-time use, invalid token rejection |
| **Webhook Secret** | 15 | Empty secret rejects all webhooks |
| **Image Handling** | 16 | Image field omitted when product has no image |
| **Price Conversion** | 17–18 | Cents conversion accuracy, free items ($0) |
| **Signature Internals** | 19 | Recursive ksort for nested data |
| **Customer References** | 20 | `user-7` vs `guest-42` format |
| **Happy Paths** | 21–35 | Single item, multi-item, quantities, shipping, % discount, $ discount, stacked coupons, payment page URLs, customer ID, status transitions |
| **Unhappy Paths** | 36–55 | Zero qty, negative totals, $99,999 orders, $0.01 orders, missing clientReferenceId, malformed refs, non-existent orders, double webhooks, wrong order_id, replay attacks, empty carts, no description fallback, unicode names, mixed discounts, 100% discount, PAYMENT_EXPIRED, unknown events |
| **Edge Cases** | 56–85 | Floating point precision ($19.99 × 3 × 15% off), JPY zero-decimal, 255+ char names, shipping-only orders, concurrent webhooks, JSON serialization, large quantities |
| **Security** | 86–88 | Payment page ID verification (cross-order attack), failure webhook vs paid order, absint sanitization |
| **Refunds** | 89–96 | Amount conversion, validation (zero/negative/null), page ID requirement, endpoint construction, partial refund, full refund |
| **Infrastructure** | 97–98 | Cross-order webhook attack, multisite WooCommerce detection |

### E2E Tests (Docker)

Spin up a full WooCommerce shop with Docker and test the plugin end-to-end:

```bash
# Start the environment
cd /path/to/plugin
docker compose up -d

# Install WordPress + WooCommerce + plugin
docker exec <container> wp core install --allow-root \
  --url="http://localhost:8888" \
  --title="Test Shop" \
  --admin_user=admin \
  --admin_password=admin123 \
  --admin_email=admin@test.com

docker exec <container> wp plugin install woocommerce --activate --allow-root
docker exec <container> wp plugin activate breeze-payment-gateway --allow-root
```

E2E tests cover:
- Store pages load (homepage, products, admin)
- Breeze gateway visible in WooCommerce settings
- Order creation via REST API (single, multi-item, with coupon)
- Webhook handling (valid signature → 200, invalid → 400, missing data → 400)
- Return URL handling (valid token, invalid token, missing order)
- Idempotency (duplicate webhooks)
- Edge cases (GET on webhook endpoint, malformed JSON)
- Debug log verification (entries exist, no PII leaked)

## Development

### Prerequisites

- **Docker** (v20.10+) + **Docker Compose** (v2.0+)
- **PHP** (7.4+ for unit tests)
- **make** (optional, for dev commands)

### Dev Commands (Makefile)

| Command | Description |
|---------|-------------|
| `make setup` | Download containers, install WP + WooCommerce + sample data |
| `make uninstall` | Delete and clean up containers |
| `make up` | Start containers in background |
| `make down` | Stop containers (keeps data) |
| `make logs` | Follow container logs |
| `make shell` | Shell into WordPress container |
| `make wpcli` | Shell into WP-CLI container |
| `make release VERSION=x.y.z` | Bump version, commit, tag, push |

### File Structure
```
./
├── breeze-payment-gateway.php              # Main plugin file
├── includes/
│   ├── class-wc-breeze-payment-gateway.php # Gateway class
│   └── class-wc-breeze-blocks-support.php  # WooCommerce Blocks integration
├── assets/
│   ├── images/breeze-icon.png              # Gateway icon
│   ├── js/blocks/
│   │   ├── breeze-blocks.js                # Checkout Block handler
│   │   └── breeze-blocks.asset.php         # Block asset manifest
│   └── css/breeze-blocks.css               # Checkout Block styles
├── tests/
│   └── test-gateway.php                    # Unit tests (98 tests, 262 assertions)
├── QA-REPORT.md                            # Security & QA audit report
├── languages/breeze-payment-gateway.pot    # Translation template
├── uninstall.php                           # Cleanup on uninstall
└── docker-compose.yml                      # Local dev environment
```

### Hooks & Filters

| Filter | Description |
|--------|-------------|
| `woocommerce_breeze_gateway_icon` | Override the gateway icon URL |
| `breeze_api_base_url` | Override API base URL (e.g. QA environment) |
| `breeze_supported_currencies` | Add supported currencies (default: `['USD']`) |

### Overriding API Base URL

```php
// Via constant (wp-config.php)
define( 'BREEZE_API_BASE_URL', 'https://api.qa.breeze.cash' );

// Via filter
add_filter( 'breeze_api_base_url', fn() => 'https://api.qa.breeze.cash' );
```

### Adding Currency Support

```php
add_filter( 'breeze_supported_currencies', function( $currencies ) {
    $currencies[] = 'EUR';
    $currencies[] = 'GBP';
    return $currencies;
});
```

## Changelog

### 1.0.3
- **Security:** Return URL no longer calls `payment_complete()` — waits for webhook
- **Security:** Webhook verifies payment page ID matches stored order
- **Security:** Debug logging redacts PII (billing email, customer data)
- **Security:** Failure webhooks cannot override already-paid orders
- **Fix:** PHP 8.2 dynamic property deprecation warnings
- **Fix:** Blocks CSS only enqueued on checkout pages (not globally)
- **Fix:** Multisite WooCommerce detection (network-activated plugins)
- **Fix:** Currency validation — gateway hidden for unsupported currencies
- **Fix:** Discounted prices use line item total (not catalog price)
- **Fix:** No WooCommerce tax sent to Breeze (MoR handles tax)
- **Feature:** Refund support (partial + full) via Breeze API
- **Feature:** 98 unit tests with 262 assertions

### 1.0.2
- Initial release with inline products, webhook signature verification, return tokens

---

Made with ❤️ by [Breeze](https://breeze.cash)
