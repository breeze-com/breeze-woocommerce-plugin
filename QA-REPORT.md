# Breeze WooCommerce Plugin — QA Report

**Date:** 2026-03-04
**Version:** 1.0.2
**Reviewer:** TARS (automated QA) + human review

---

## Revision History

| Date | Changes |
|------|---------|
| 2026-03-01 | Initial report (v1.0.2, pre-PR #2) |
| 2026-03-04 | Updated to reflect PR #2 fixes and subsequent follow-up patches |

---

## Summary

The plugin is well-structured with good security fundamentals (return token, webhook signature verification, timing-safe comparison). PR #2 resolved 13 of 16 original findings. Two additional issues were identified and patched during PR review. Three items remain open.

---

## Findings

### 🔴 Critical

#### 1. ~~`clientReferenceId` Parsing / Missing `pageId` Verification~~ ✅ Fixed (PR #2 + follow-up patch)
**File:** `class-wc-breeze-payment-gateway.php`
The webhook handler had no verification that the incoming `pageId` matched the `_breeze_payment_page_id` stored on the order, allowing a valid webhook for order A to mark order B as paid.

PR #2 added `get_order_from_webhook()` with a pageId check, but the condition had a bypass bug:
```php
// Buggy — silently bypassed when webhook omits pageId
if ( $stored_page_id && $webhook_page_id && $stored_page_id !== $webhook_page_id )
```
**Follow-up patch** corrected this to fail closed: if `$stored_page_id` is set but the webhook omits `pageId`, the webhook is now rejected.

#### 2. ~~Debug Logging Leaks Sensitive Data~~ ✅ Fixed (PR #2)
**File:** `class-wc-breeze-payment-gateway.php`
`billingEmail` and full customer objects are no longer logged. Webhook logs now include only event type and order reference.

---

### 🟡 Medium

#### 3. No Webhook Replay Protection — ⚠️ Open (tracked)
**File:** `class-wc-breeze-payment-gateway.php`
There's no timestamp validation or idempotency key on webhooks. HMAC signature verification prevents forgery, and `is_paid()` prevents double-completion of success events. Failure webhooks now guard against overriding paid orders (Finding 4). The remaining risk is narrow (replay against a refunded order), but not yet fully addressed.

**Mitigation:** Store processed webhook page IDs in order meta; reject duplicate `pageId` events. Tracked as a follow-up issue.

#### 4. ~~Failure Webhook Can Override Completed Payment~~ ✅ Fixed (PR #2)
`handle_payment_failed_webhook()` now checks `$order->is_paid()` and returns early if the order is already complete.

#### 5. Race Condition: Stale Orders if Webhook Never Fires — ⚠️ Open
**File:** `class-wc-breeze-payment-gateway.php`
PR #2 correctly changed `handle_return()` to set orders to `on-hold` rather than calling `payment_complete()`, deferring authoritative confirmation to the webhook. However, if the webhook never arrives (misconfigured endpoint, network failure), the order stays `on-hold` indefinitely with no cleanup.

**Mitigation:** Add a `wp_cron` job to expire or flag stale `on-hold` Breeze orders after a configurable timeout (e.g., 24h). Tracked as a follow-up issue.

#### 6. No Rate Limiting on Return URL Endpoint — ⚠️ Open
**File:** `class-wc-breeze-payment-gateway.php`
The `handle_return` endpoint is publicly accessible. The one-time token prevents order manipulation, but probing is still possible. Low practical risk given the token requirement.

**Mitigation:** Consider adding IP-based rate limiting or absorbing into server-level rules.

#### 7. ~~WooCommerce Active Check Ignores Multisite~~ ✅ Fixed (PR #2)
**File:** `breeze-payment-gateway.php`
Now checks `get_site_option('active_sitewide_plugins')` for network-activated WooCommerce in multisite environments.

---

### 🟢 Low

#### 8. ~~Blocks Support CSS Enqueued Globally~~ ✅ Fixed (PR #2 + follow-up patch)
**File:** `class-wc-breeze-blocks-support.php`
PR #2 moved the enqueue into a `wp_enqueue_scripts` action gated by `is_checkout()`. Follow-up patch also added `is_wc_endpoint_url('order-pay')` to cover the order-pay page.

#### 9. ~~`process_refund()` Declares Support But Returns Error~~ ✅ Fixed (PR #2)
Full refund implementation added via `POST /v1/payment_pages/{pageId}/refund`. Supports partial and full refunds with proper error messaging for crypto payments (which require manual handling).

#### 10. ~~No Currency Validation~~ ✅ Fixed (PR #2 + follow-up patch)
**File:** `class-wc-breeze-payment-gateway.php`
`is_available()` now hides the gateway for unsupported currencies (default: USD). Extensible via the `breeze_supported_currencies` filter. Follow-up patch added a visible note in the gateway's admin description so merchants are aware of the USD-only default.

#### 11. ~~Missing `absint()` on Webhook Order ID~~ ✅ Fixed (PR #2)
`get_order_from_webhook()` now applies `absint()` to the extracted order ID, rejecting non-numeric references.

---

### ℹ️ Info

#### 12. API Key in Basic Auth
The API key is sent as Basic auth (`base64_encode($this->api_key . ':')`) — standard pattern, enforced over HTTPS.

#### 13. HPOS Compatibility Declared
Properly declares `custom_order_tables` compatibility via `FeaturesUtil`. Good.

#### 14. Cart Checkout Blocks Compatibility Declared
Properly declares `cart_checkout_blocks` compatibility. Good.

#### 15. Return Token Uses `hash_equals()`
Timing-safe comparison for token verification. Good.

#### 16. Webhook Signature Uses `hash_equals()`
Timing-safe comparison for HMAC verification. Good.

---

## Test Results

PR #2 expanded coverage from 46 to 120 tests (352 assertions), covering payment flow, webhook handlers, discount/tax calculations, security validations, and end-to-end lifecycle scenarios. All tests pass.

**Gaps to address in future test iterations:**
- `pageId`-absent webhook rejection (new behaviour from follow-up patch)
- `order-pay` page CSS enqueue
- Stale on-hold order behaviour (pending cron implementation)

---

## Open Items (Priority Order)

| # | Severity | Finding | Status |
|---|----------|---------|--------|
| 3 | 🟡 Medium | Webhook replay protection | Open — follow-up issue |
| 5 | 🟡 Medium | Stale on-hold orders / no cron cleanup | Open — follow-up issue |
| 6 | 🟡 Medium | No rate limiting on return URL | Open — low risk, defer |