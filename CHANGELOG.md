# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.1] - 2026-05-12

### Fixed
- **Modal rejected `pay.breeze.com` URLs.** The modal previously checked the iframe URL against a single host (`pay.breeze.cash`), so payment-page URLs returned on the `.com` host failed the host validation. Replaced with a base-domain allowlist — any subdomain of `breeze.cash` or `breeze.com` is now accepted. Match is dot-boundary safe (`evil-breeze.com` is still rejected).
- **Cart not emptied when user dismisses modal after success.** If the customer pressed Escape or clicked the backdrop after seeing "Payment confirmed!" but before the iframe redirect to the success return URL landed (visible mainly on local testing where the redirect is blocked by mixed-content / Local Network Access), the modal closed without invoking `handle_return()`. The order was correctly marked paid by the webhook, but the customer's cart still contained the items. The modal now follows the token-protected success return URL on user-close-after-confirmed, so `handle_return()` runs and the cart clears.
- **Legacy modal now also validates the payment-page URL host** before loading it into the iframe (previously enforced only on the Blocks variant).

### Changed
- Filter `breeze_modal_origin` (single host string) renamed to `breeze_payment_page_domains` (array of base domains). Default: `[ 'breeze.cash', 'breeze.com' ]`.
- Localised JS data: `breezeOrigin` / `breezeHost` removed, replaced by `breezeDomains: string[]`.
- The Blocks Store API redirect tag now exposes both `breeze_success_url` and `breeze_fail_url`; the legacy AJAX response exposes both `successUrl` and `failUrl`. Construction consolidated into `WC_Breeze_Modal_Checkout::build_return_url( $order, $status )`.

---

## [2.0.0] - 2026-05-12

### Added
- **Optional modal/lightbox checkout** via a new `Checkout Display` setting under WooCommerce → Settings → Payments → Breeze. Default remains `Redirect to Breeze (Recommended)`; `Open in a modal` embeds the Breeze payment page in a lightbox on the checkout page without a full-page navigation.
- Modal flow supports both the **WooCommerce Checkout Blocks** (via a `fetch()` intercept on the Store API checkout response) and the **legacy shortcode** checkout (via the `checkout_place_order_breeze_payment_gateway` event + a nonce-protected `breeze_create_modal_payment` admin-ajax action).
- Apple Pay cross-domain support in modal mode (passes `cross_domain_name` to the Breeze iframe and responds to the `request-global-config` postMessage).
- 3DS auto-expansion, "Payment confirmed" overlay on success postMessage events, subtle shake animation on validation errors.
- **Public gateway API**: `WC_Breeze_Payment_Gateway::create_payment_for_order( WC_Order $order )` returns `{ url, id, fail_return_url }` or `WP_Error`. Consumed by both `process_payment()` and the modal integration. Replaces the standalone addon's reflection-into-private-methods workaround.
- New `breeze_modal_origin` filter (defaults to `https://pay.breeze.cash`) for staging environments that need to talk to a different Breeze host.

### Changed
- Folds the previously standalone `breeze-woocommerce-modal-addon` plugin into the main plugin — one install, one settings page.
- `create_breeze_payment_page()` now surfaces the constructed `fail_return_url` on its return value so callers can route the customer to the existing token-protected `handle_return()` endpoint.

### Security
- Modal `postMessage` traffic validates `event.origin` inbound and targets `event.origin` outbound (no `'*'`).
- Breeze URL matching parses with `new URL()` and compares hostnames — substring matches like `indexOf('breeze.cash')` are gone.
- Removed the global `Location.prototype.href` setter override and its 120 s cleanup timer that the standalone addon shipped.
- Removed the unauthenticated `?breeze_payment=` query-string handler that could grief a customer's draft order via a shared link.
- User-cancel path is a nonce-bound, ownership-checked `breeze_cancel_modal_payment` admin-ajax endpoint.
- Failure recovery reuses the existing `_breeze_return_token` flow instead of inferring success/failure from same-origin URL paths.

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
