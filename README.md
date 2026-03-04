# Breeze Payment Gateway for WooCommerce
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-purple.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net/)
[![Tests](https://img.shields.io/badge/tests-98%20passed%20(262%20assertions)-brightgreen.svg)](#testing)
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
- ✅ 98 unit tests (262 assertions) + E2E test suite

## Installation

1. Upload the `breeze-payment-gateway` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments
4. Enable "Breeze"
5. Click "Manage" to configure settings

## Configuration

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

### Webhook Configuration

In your Breeze Dashboard, set the webhook URL to:
```
https://yoursite.com/?wc-api=breeze_payment_gateway
```

---

## How It Works

### Payment Flow

```
Customer browses store → Adds items to cart
    ↓
Clicks "Place Order" with Breeze selected
    ↓
Plugin creates customer in Breeze (or reuses existing)
    ↓
Plugin creates payment page with inline products (amounts in cents, post-discount)
    ↓
Customer redirected to pay.breeze.cash
    ↓
Customer pays (Card, Apple Pay, Google Pay, Crypto)
    ↓
Customer redirected back → Order set to "On Hold"
    ↓
Breeze webhook fires (PAYMENT_SUCCEEDED) → Order set to "Processing" ✅
```

### Key Design Decisions

- **Return URL does NOT mark orders as paid.** Sets to "On Hold" only. Prevents fake payment confirmations via URL manipulation.
- **Webhook is the authoritative confirmation.** Only `PAYMENT_SUCCEEDED` via verified HMAC webhook triggers `payment_complete()`.
- **Breeze handles tax.** WooCommerce tax is NOT sent as a line item — Breeze is the Merchant of Record and calculates/collects tax itself.
- **Discounts baked into unit prices.** Uses `$item->get_total() / qty` (post-coupon), not catalog `get_price()`.
- **Cross-order webhook protection.** Webhook `pageId` is verified against the stored `_breeze_payment_page_id` meta.
- **Failure webhooks can't override paid orders.** `is_paid()` guard prevents `PAYMENT_EXPIRED` from flipping a completed order to failed.

---

## Bugs Found & Fixed

### 🔴 Critical

| # | Bug | Impact | Fix |
|---|-----|--------|-----|
| 1 | **Return URL called `payment_complete()`** | Anyone hitting `?order_id=X&status=success` with a valid token could mark orders as paid before Breeze confirmed settlement. If payment fails after redirect, order is already marked paid. | Return URL now sets order to `on-hold`. Only the webhook calls `payment_complete()`. |
| 2 | **No payment page ID verification in webhook** | A valid webhook for order A could mark order B as paid if `clientReferenceId` was manipulated. No check that the webhook's `pageId` matched the order's stored page ID. | Added `get_order_from_webhook()` that verifies `$data['pageId']` matches `$order->get_meta('_breeze_payment_page_id')`. Mismatches are rejected. |
| 3 | **Debug logging leaked PII** | With debug enabled, full API request data was logged including `billingEmail`, customer objects, and full webhook payloads. Violates data protection requirements. | API request logs now `unset($safe_data['billingEmail'])` and replace customer with `[redacted]`. Webhook logs only include event type and order reference. |
| 4 | **WooCommerce tax sent as line item** | Breeze is MoR and calculates tax itself. Sending WooCommerce tax = customer charged tax twice. | Removed tax line item entirely. Comment explains why. |
| 5 | **Catalog price used instead of discounted price** | Used `$product->get_price()` (ignores coupons) then distributed discounts with a complex rounding algorithm that accumulated errors. | Now uses `$item->get_total() / $item->get_quantity()` — the actual post-coupon price WooCommerce calculated. Removed 25+ lines of discount distribution code. |

### 🟡 Medium

| # | Bug | Impact | Fix |
|---|-----|--------|-----|
| 6 | **Failure webhook overrides paid orders** | `handle_payment_failed_webhook()` had no `is_paid()` guard. A delayed `PAYMENT_EXPIRED` arriving after `PAYMENT_SUCCEEDED` would flip a completed order to failed. | Added `if ($order->is_paid()) return;` guard with debug logging. |
| 7 | **Order ID not sanitized in webhook** | `str_replace('order-', '', $ref)` returned a string with no validation. `clientReferenceId: 'order-1 OR 1=1'` would pass through (not SQL-injectable due to WC prepared statements, but sloppy). | Added `absint()` on extracted order ID. Non-numeric references return 0 → rejected. |
| 8 | **WooCommerce multisite detection broken** | `active_plugins` check only looked at site-level plugins. Network-activated WooCommerce in multisite wasn't detected → plugin silently disabled. | Added `get_site_option('active_sitewide_plugins')` check for multisite environments. |
| 9 | **Redundant `wc_reduce_stock_levels()` call** | `handle_return()` called both `payment_complete()` AND `wc_reduce_stock_levels()`. Since WC 3.0, `payment_complete()` already triggers stock reduction. Stock was reduced twice per order. | Removed explicit call. `payment_complete()` in webhook handler handles it. |
| 10 | **`verify_webhook_signature()` crashed when debug off** | `$this->log->error(...)` called when `$this->log` was null (debug disabled). Fatal error on webhook with no secret configured. | Added `if ($this->log)` guard. |

### 🟢 Low

| # | Bug | Impact | Fix |
|---|-----|--------|-----|
| 11 | **Blocks CSS enqueued globally** | `wp_enqueue_style('wc-breeze-blocks')` ran on every page load (file-level code outside any hook). Minor performance hit on non-checkout pages. | Moved into `wp_enqueue_scripts` hook, gated by `is_checkout()`. |
| 12 | **Refund declared but not implemented** | `$this->supports` included `'refunds'` but `process_refund()` always returned WP_Error. Refund button appeared in admin but always failed. | Implemented full refund via `POST /v1/payment_pages/{pageId}/refund`. Supports partial + full refunds. |
| 13 | **No currency validation** | Non-USD currencies passed to Breeze API would fail with unhelpful error. Gateway still showed at checkout for unsupported currencies. | Added `is_available()` override checking against `get_supported_currencies()` (default: `['USD']`, extensible via `breeze_supported_currencies` filter). |
| 14 | **PHP 8.2 dynamic property deprecations** | `$this->testmode`, `$this->api_key`, `$this->webhook_secret`, `$this->debug`, `$this->payment_methods` were dynamic properties. PHP 8.2 emits deprecation warnings for each. | Declared all five as `protected` class properties with default values. |
| 15 | **Image field sent as `null`/`false`** | When a product had no image, `wp_get_attachment_url()` returns `false` which was sent to Breeze API. API rejects null/false image values. | Image field only included when `$image_url` is truthy. |

---

## Refunds

Refunds are processed via the Breeze API directly from WooCommerce admin:

1. Go to the order in WooCommerce admin
2. Click "Refund"
3. Enter the amount and reason
4. Click "Refund via Breeze"

Both **partial** and **full** refunds are supported. Amounts are converted to cents and sent to `POST /v1/payment_pages/{pageId}/refund`.

**Validations:**
- Amount must be > 0
- Order must have a stored `_breeze_payment_page_id`
- Reason is sanitized and included in audit trail

---

## Security

| Feature | Implementation |
|---------|---------------|
| **Return URL protection** | One-time token generated per order, verified with `hash_equals()`, consumed after use |
| **Webhook signature** | HMAC SHA256 with recursive key sorting (`ksort_recursive`), timing-safe comparison |
| **Cross-order protection** | Webhook `pageId` verified against stored `_breeze_payment_page_id` on order |
| **Failure webhook guard** | `PAYMENT_EXPIRED` / `payment.failed` cannot override already-paid orders |
| **PII-safe logging** | Debug logs redact `billingEmail`, customer data; only log event type + order ref for webhooks |
| **Input sanitization** | `absint()` on order IDs, `sanitize_text_field()` on all user inputs, `rawurlencode()` on emails |
| **Empty secret rejection** | All webhooks rejected if `webhook_secret` not configured (no silent pass-through) |
| **HTTPS enforced** | API calls to `https://api.breeze.cash` only |

### Order Metadata

| Meta Key | Value | Lifecycle |
|----------|-------|-----------|
| `_breeze_customer_id` | Breeze customer ID | Persists |
| `_breeze_payment_page_id` | Breeze payment page ID (used for refunds + webhook verification) | Persists |
| `_breeze_return_token` | One-time return URL token | Deleted after first use |

---

## Testing

### Unit Tests — 98 tests, 262 assertions

Run with no WordPress/WooCommerce runtime:

```bash
php tests/test-gateway.php
```

#### Complete Test List

| # | Test | Assertions |
|---|------|-----------|
| 1 | Return URL sets on-hold, does NOT call payment_complete | 3 |
| 2 | Webhook PAYMENT_SUCCEEDED calls payment_complete | 2 |
| 3 | Webhook skips already-paid orders (idempotency) | 1 |
| 4 | No tax line item sent to Breeze (MoR handles tax) | 2 |
| 5 | Discounted prices use line item total (not catalog price) | 3 |
| 6 | Multi-quantity with discount — correct per-unit price | 1 |
| 7 | Shipping included as line item when present | 3 |
| 8 | No shipping line item when shipping = $0 | 1 |
| 9 | Webhook signature verification (HMAC SHA256) — valid + tampered | 2 |
| 10 | Wrong webhook secret fails verification | 1 |
| 11 | Webhook handles both event type formats (PAYMENT_SUCCEEDED + payment.succeeded, PAYMENT_EXPIRED + payment.failed) | 4 |
| 12 | clientReferenceId correctly parsed to order ID | 2 |
| 13 | Return token is consumed after use (one-time) | 2 |
| 14 | Invalid return token is rejected (mismatch + empty) | 2 |
| 15 | Empty webhook secret rejects all webhooks | 1 |
| 16 | Image field omitted when product has no image; present when it does | 3 |
| 17 | Price-to-cents conversion accuracy ($9.99, $0.01, $100, $49.95, $0.10, $1999.99) | 6 |
| 18 | Free item ($0) produces 0 cents | 1 |
| 19 | Recursive ksort for webhook signature (nested data) | 4 |
| 20 | Customer reference ID format (user-7 vs guest-42) | 2 |
| 21 | Failure webhook must not override a paid order / Single item checkout → correct products array | 2+ |
| 22 | clientReferenceId injection attempts / Multi-item cart (3+ products) | 2+ |
| 23 | Webhook success without pageId uses empty transaction ID / Cart with 1 item qty=5 | 2+ |
| 24 | Webhook without clientReferenceId is ignored / Checkout with shipping | 2+ |
| 25 | Webhook with empty signature rejected / Percentage discount (20% off $50 = $40) | 2+ |
| 26 | Webhook with empty data rejected / Fixed amount discount ($10 off $50) | 2+ |
| 27 | Return URL with order_id=0 redirects to cart / Multiple coupons stacked | 2+ |
| 28 | Return URL with non-numeric order_id sanitized to 0 / Payment page URL structure | 2+ |
| 29 | Floating point edge cases in cent conversion / Payment page includes customer ID | 2+ |
| 30 | Webhook should verify payment page ID matches order / Order status transitions (pending → on-hold → processing) | 2+ |
| 31 | Unknown webhook event type safely ignored / Cart with 0 quantity item excluded | 2+ |
| 32 | Negative line item total produces negative cents / Negative line total handled gracefully | 2+ |
| 33 | Zero quantity falls back to catalog price / Very large order ($99,999.99) correct cents | 2+ |
| 34 | Very small order ($0.01) → 1 cent | 1 |
| 35 | Webhook with missing clientReferenceId → no crash | 1 |
| 36 | Webhook with malformed clientReferenceId | 2 |
| 37 | Webhook for non-existent order → no crash | 1 |
| 38 | Double webhook → payment_complete idempotent | 2 |
| 39 | Return URL with wrong order_id → rejected | 1 |
| 40 | Return URL replay attack (token already consumed) → rejected | 2 |
| 41 | Empty cart → empty products array | 1 |
| 42 | Product with no description → falls back to product name | 1 |
| 43 | Product with unicode/special chars in name (日本語, émojis 🎮) | 2 |
| 44 | Mixed cart — some items discounted, some not | 2 |
| 45 | 100% discount → $0 total | 1 |
| 46 | Webhook PAYMENT_EXPIRED → order set to failed | 1 |
| 47 | Webhook with unknown event type → no crash, logged | 1 |
| 48 | Floating point precision: $19.99 × 3 with 15% discount | 2 |
| 49 | Currency with 0 decimals (JPY: ¥1000 → 100000 cents) | 2 |
| 50 | Very long product name (255+ chars) — not truncated | 2 |
| 51 | Order with only shipping (no products — gift card scenario) | 2 |
| 52 | Concurrent webhooks for same order → only first processes | 2 |
| 53 | Webhook signature with deeply nested data | 1 |
| 54 | Product ID included in products array | 2 |
| 55 | API base URL override via constant | 1 |
| 56 | Testmode selects test API key (not live) | 2 |
| 57 | Authorization header is Basic base64(key:) | 2 |
| 58 | process_refund returns error when no page ID | 1 |
| 59 | Gateway declares blocks support | 1 |
| 60 | clientReferenceId follows order-{id} format | 2 |
| 61 | Webhook rejects invalid structures (missing signature/data) | 2 |
| 62 | Preferred payment methods added to URL as query param | 2 |
| 63 | Shipping amount edge cases ($0.01, $999.99) | 2 |
| 64 | billingEmail included in payment page data | 1 |
| 65 | Order meta keys used by plugin (_breeze_customer_id, _breeze_payment_page_id) | 2 |
| 66 | Multiple items with varying quantities (2 + 3 + 1) | 3 |
| 67 | signupAt is in milliseconds (not seconds) | 1 |
| 68 | Customer email properly URL-encoded for GET request | 1 |
| 69 | Common price → cents conversions ($1, $10, $99.99, $0.50) | 4 |
| 70 | Webhook failed handler sets order to failed | 1 |
| 71 | Return URL with failed status → order set to failed | 1 |
| 72 | Return URL with missing order_id → redirect to cart | 1 |
| 73 | Coupon discount distributed across multi-quantity correctly | 3 |
| 74 | hash_equals used for timing-safe comparison (not ==) | 2 |
| 75 | Payment page request has all required fields (products, billingEmail, clientReferenceId, URLs, customer) | 5 |
| 76 | Plugin defines expected constants (VERSION, DIR, URL, BASENAME) | 4 |
| 77 | Gateway ID is breeze_payment_gateway | 1 |
| 78 | Rounding edge case — $33.33 / 3 qty → 1111 cents per unit | 1 |
| 79 | Null product object → item skipped, no crash | 1 |
| 80 | Webhook returns appropriate response codes (200 success, 400 invalid) | 2 |
| 81 | Customer creation data structure (referenceId, email, signupAt) | 3 |
| 82 | Large quantity (1000 items) handled correctly | 2 |
| 83 | Webhook handles payment.failed (dot notation) → order failed | 1 |
| 84 | Products array serializes to valid JSON | 2 |
| 85 | Webhook with extra/unknown fields doesn't break extraction | 2 |
| 86 | Webhook verifies payment page ID matches stored page ID (match, mismatch, legacy, missing) | 4 |
| 87 | Failure webhook does NOT override already-paid order | 2 |
| 88 | Order ID sanitized with absint() (normal, SQL injection, negative, non-numeric, empty) | 5 |
| 89 | Refund amount converts to cents correctly ($9.99, $0.01, $100, $49.95, $1999.99) | 5 |
| 90 | Refund rejects invalid amounts (zero, negative, null, valid) | 4 |
| 91 | Refund requires stored payment page ID | 2 |
| 92 | Refund API endpoint correctly constructed | 1 |
| 93 | Currency validation — USD supported, EUR/GBP/JPY blocked by default | 4 |
| 94 | Debug logging redacts sensitive fields (billingEmail removed, customer redacted, non-sensitive preserved) | 3 |
| 95 | Partial refund — half of $99.98 order = 4999 cents | 3 |
| 96 | Full refund — complete $149.97 order = 14997 cents | 2 |
| 97 | Cross-order webhook attack — page ID from order A rejected against order B | 1 |
| 98 | Multisite WooCommerce detection (network-activated + standard) | 2 |

### E2E Tests (Docker)

Spin up a full WooCommerce shop and test the plugin end-to-end:

```bash
cd /path/to/plugin
docker compose up -d

# Install WP + WooCommerce + plugin
docker exec <container> wp core install --allow-root \
  --url="http://localhost:8888" --title="Test Shop" \
  --admin_user=admin --admin_password=admin123 \
  --admin_email=admin@test.com

docker exec <container> wp plugin install woocommerce --activate --allow-root
docker exec <container> wp plugin activate breeze-payment-gateway --allow-root
```

#### E2E Test Coverage

**Store & Plugin:**
- Homepage loads (200)
- Product pages load (all 3 products)
- WP Admin accessible, Breeze gateway visible in payments settings
- Gateway settings saved correctly (test mode, API key)
- Shop page shows products

**Checkout Flow (via WooCommerce REST API):**
- Create order with single item → status pending, line items correct
- Create order with multiple items (different products + quantities) → all items present
- Create order with coupon BREEZE20 → discount applied, prices reflect 20% off
- Breeze listed in available payment gateways

**Webhook Handling (POST to `/?wc-api=breeze_payment_gateway`):**
- Valid `PAYMENT_SUCCEEDED` with correct HMAC signature → 200
- Wrong signature → 400 rejection
- Missing data/signature → 400
- `PAYMENT_EXPIRED` → order status changes to failed
- No webhook secret configured → all webhooks rejected
- Duplicate `PAYMENT_SUCCEEDED` → idempotent (no double-completion)

**Return URL:**
- Valid token → redirect to thank-you page (not 500)
- Invalid/wrong token → redirect to cart (not crash)
- Missing order_id → redirect to cart

**Edge Cases:**
- GET request on webhook endpoint → handled gracefully
- Malformed JSON body → 400
- Debug log exists, contains entries, no PII leaked

---

## API Reference

### Webhook Events

| Event | WooCommerce Action |
|-------|-------------------|
| `PAYMENT_SUCCEEDED` | `payment_complete()` → order status: Processing |
| `payment.succeeded` | Same as above (dot notation supported) |
| `PAYMENT_EXPIRED` | `update_status('failed')` (only if not already paid) |
| `payment.failed` | Same as above (dot notation supported) |

### Inline Products Format (POST /v1/payment_pages)
```json
{
  "products": [
    {
      "name": "Breeze Snowboard",
      "description": "Professional snowboard",
      "amount": 55996,
      "currency": "USD",
      "quantity": 1,
      "id": "10",
      "images": ["https://example.com/snowboard.jpg"]
    }
  ],
  "billingEmail": "customer@example.com",
  "clientReferenceId": "order-42",
  "successReturnUrl": "https://yoursite.com/?wc-api=breeze_return&order_id=42&status=success&token=abc123",
  "failReturnUrl": "https://yoursite.com/?wc-api=breeze_return&order_id=42&status=failed&token=abc123",
  "customer": { "id": "cus_xxx" }
}
```

Note: `amount` is in **cents** (e.g. $559.96 = 55996). The example shows a 20% discount applied ($699.95 → $559.96).

### Refund (POST /v1/payment_pages/{pageId}/refund)
```json
{
  "amount": 55996,
  "reason": "Customer requested refund"
}
```

---

## Development

### File Structure
```
./
├── breeze-payment-gateway.php              # Main plugin file
├── includes/
│   ├── class-wc-breeze-payment-gateway.php # Gateway class (payment, webhooks, refunds)
│   └── class-wc-breeze-blocks-support.php  # WooCommerce Blocks integration
├── assets/
│   ├── images/breeze-icon.png              # Gateway icon
│   ├── js/blocks/
│   │   ├── breeze-blocks.js                # Checkout Block handler
│   │   └── breeze-blocks.asset.php         # Block asset manifest
│   └── css/breeze-blocks.css               # Checkout Block styles (checkout-only)
├── tests/
│   └── test-gateway.php                    # Unit tests (98 tests, 262 assertions)
├── QA-REPORT.md                            # Security & QA audit report (16 findings)
├── languages/breeze-payment-gateway.pot    # Translation template
├── uninstall.php                           # Cleanup on uninstall
└── docker-compose.yml                      # Local dev environment
```

### Hooks & Filters

| Filter | Description | Example |
|--------|-------------|---------|
| `woocommerce_breeze_gateway_icon` | Override gateway icon URL | `add_filter('woocommerce_breeze_gateway_icon', fn() => '/my-icon.png')` |
| `breeze_api_base_url` | Override API base URL | `add_filter('breeze_api_base_url', fn() => 'https://api.qa.breeze.cash')` |
| `breeze_supported_currencies` | Add supported currencies | `add_filter('breeze_supported_currencies', fn($c) => array_merge($c, ['EUR']))` |

API base URL can also be overridden via constant:
```php
define( 'BREEZE_API_BASE_URL', 'https://api.qa.breeze.cash' );
```

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

---

## Changelog

### 1.0.3

**Security fixes (5):**
- 🔴 Return URL no longer calls `payment_complete()` — sets to on-hold, waits for webhook
- 🔴 Webhook verifies `pageId` matches stored `_breeze_payment_page_id` (prevents cross-order attacks)
- 🔴 Debug logging redacts PII (`billingEmail`, customer data, full webhook payloads)
- 🟡 Failure webhooks (`PAYMENT_EXPIRED`) cannot override already-paid orders
- 🟡 Order IDs sanitized with `absint()` in webhook handler

**Bug fixes (10):**
- WooCommerce tax no longer sent as line item (was causing double-taxation)
- Discounted prices use `$item->get_total()/qty` (not catalog price + rounding-error-prone distribution)
- Redundant `wc_reduce_stock_levels()` call removed (was reducing stock twice)
- `verify_webhook_signature()` no longer crashes when debug is off (`$this->log` null check)
- Multisite WooCommerce detection fixed (now checks network-activated plugins)
- Blocks CSS only enqueued on checkout pages (was loading on every page)
- PHP 8.2 dynamic property deprecation warnings fixed (5 properties declared)
- Currency validation added — gateway hidden for unsupported currencies
- Image field omitted when product has no image (was sending `false` to API)
- `process_refund()` stub replaced with working implementation

**New features:**
- Refund support (partial + full) via `POST /v1/payment_pages/{pageId}/refund`
- Currency validation with `breeze_supported_currencies` filter
- 98 unit tests with 262 assertions
- QA report with 16 findings (all addressed)

### 1.0.2
- Initial release with inline products, webhook signature verification, return tokens

---

Made with ❤️ by [Breeze](https://breeze.cash)
