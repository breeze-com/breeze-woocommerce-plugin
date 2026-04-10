# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Changed
- **Payment page creation now uses `lineItems` with `clientProductId`** (Inline Products — Your Product IDs) instead of the legacy anonymous `products` array.
  - Each WooCommerce product is mapped to a `lineItems` entry carrying the merchant's own product ID as `clientProductId`.
  - Field renames: `name` → `displayName`, `images` (array) → `image` (single URL).
  - Shipping is emitted as a virtual line item with `clientProductId: 'shipping'`.
  - Rounding strategy for coupon-adjusted multi-unit lines is preserved.

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
