# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
