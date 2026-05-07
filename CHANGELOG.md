# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.0] - 2026-05-08

### Added
- **Multi-currency support** — gateway is now available when the WooCommerce store currency is `USD`, `EUR`, `SGD`, or `CAD` (previously USD-only). The list remains overridable via the `breeze_supported_currencies` filter.
- **Flexible Amount (Crypto) settings section** under WooCommerce → Settings → Payments → Breeze. Three configurable fields wired into the `/v1/payment_pages` payload as `settings.flexibleAmount`:
  - `Max Amount` — optional cap on the deduction (minor units)
  - `Percentage` — deduction as a percentage of the base amount (`(0, 100]`)
  - `Fixed Amount` — fixed deduction (minor units)
  - At least one of `Percentage` or `Fixed Amount` must be set for the object to be sent. Only honored by Breeze for crypto deposit payments.
- **Server-side validation** for all three flexible-amount fields with strict-positive bounds (`> 0`) matching the Breeze API server enforcement; decimal cents rejected for `Max Amount` / `Fixed Amount` since they are minor-unit integers.

### Changed
- Updated `get_supported_currencies()` filter example to reflect the expanded default list.

### Tests
- Refactored `build_flexible_amount` test helper into `apply_flexible_amount` so tests assert on the wrapped payload structure (`settings.flexibleAmount`) rather than the inner object alone.
- Added regression tests guarding against top-level `flexibleAmount` placement and verifying merge-behavior into existing `settings`.

---

## [1.1.1] - 2026-04-20

### Added
- **Constrain gateway icon to 24×24px** on the checkout payment methods list using the `woocommerce_gateway_icon` filter — keeps the Breeze logo consistently sized across themes.
- **Product description toggle** — a new setting under WooCommerce → Settings → Payments → Breeze lets merchants optionally include product short descriptions in line items sent to the Breeze payment page (off by default).

---

## [1.1.0] - 2026-04-13

### Changed
- **Payment page creation now uses `lineItems` with `clientProductId`** (Inline Products — Your Product IDs) instead of the legacy anonymous `products` array.
  - Each WooCommerce product is mapped to a `lineItems` entry carrying the merchant's own product ID as `clientProductId`.
  - Field renames: `name` → `displayName`, `images` (array) → `image` (single URL string).
  - Shipping is emitted as a virtual line item with `clientProductId: 'shipping'`.
  - Rounding strategy for coupon-adjusted multi-unit lines is preserved.
- **Customer data is now passed inline in the payment page request** instead of requiring a separate customer creation step. Breeze is queried by email first; if the customer exists their ID is used, otherwise the full customer object is sent for inline creation. Eliminates the separate `GET /v1/customers` + `POST /v1/customers` round-trips.

### Fixed
- **PHP 8 compatibility** — explicit `(float)` casts added before `* 100` on `get_total()` and `get_shipping_total()`, which return strings in WooCommerce and threw `TypeError: Unsupported operand types: string * int` on PHP 8+.
- **Spec compliance in `build_line_items()`**:
  - Zero-amount items (free / 100%-discounted) are skipped — Breeze requires `amount ≥ 1`.
  - `displayName` truncated to 100 chars and `description` to 280 chars per API limits.
  - Items with no valid product ID (unattached variations) are skipped.
  - Throws a user-visible checkout error if the order would exceed Breeze's 20 `lineItems` limit.
- **`RESOURCE_ALREADY_EXISTS` error for returning customers** — the payment page request no longer attempts to create a customer when one already exists for that email.
- **Docker dev setup** — `scripts/setup.sh` now configures the Breeze gateway settings (enabled, test mode, API key) from `BREEZE_TEST_API_KEY` in `.env` so fresh environments work without manual configuration.

---

## [1.0.0] - 2026-02-26

### Added
- Full Breeze API integration (customers, products, payment pages)
- Automatic customer creation and reuse via `user-{id}` / `guest-{order_id}` reference IDs
- Dynamic product creation for each order line item, shipping, and taxes
- Secure payment page redirect flow
- Webhook handling for `payment.succeeded` and `payment.failed` events
- Return URL handling (`breeze_return` WC API endpoint)
- Test mode with separate API key
- HPOS (High-Performance Order Storage) compatibility
- WooCommerce Checkout Blocks support
- Debug logging to WooCommerce log system
- Configurable API base URL via constant (`BREEZE_API_BASE_URL`) or filter (`breeze_api_base_url`)
- Internationalization support (`breeze-payment-gateway` text domain)
