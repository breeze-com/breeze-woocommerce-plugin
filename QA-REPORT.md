# Breeze WooCommerce Plugin — QA Report

**Date:** 2026-03-01  
**Version:** 1.0.2  
**Reviewer:** TARS (automated QA)

---

## Summary

The plugin is well-structured with good security fundamentals (return token, webhook signature verification, timing-safe comparison). Several issues need attention before production use.

---

## Findings

### 🔴 Critical

#### 1. `clientReferenceId` Parsing Allows Order ID Injection
**File:** `class-wc-breeze-payment-gateway.php` L380, L404  
The webhook extracts order IDs via `str_replace('order-', '', $data['clientReferenceId'])`. An attacker with a valid webhook signature could craft `clientReferenceId: 'order-order-99'` → parsed as `'order-99'` (benign), but more importantly: `'order-42'` is just `'42'` — a string, not an int. `wc_get_order('42')` works, but there's **no verification that the webhook's payment page ID matches the order's stored `_breeze_payment_page_id`**. If a legitimate payment for order A succeeds, and its webhook data is replayed (different Breeze payment but same structure), order B could be marked paid.

**Mitigation:** Verify `$data['pageId']` matches `$order->get_meta('_breeze_payment_page_id')` before calling `payment_complete()`.

#### 2. Debug Logging Leaks Sensitive Data
**File:** `class-wc-breeze-payment-gateway.php` L262-267  
When debug is enabled, the full API request data is logged including `billingEmail` and the full webhook payload. The API request logs include the Authorization header indirectly (through the `$data` array passed to the logger context). The webhook verified log at L327 logs the entire `$webhook_data` including potentially sensitive customer data.

**Mitigation:** Redact PII from debug logs. Never log full webhook payloads in production.

### 🟡 Medium

#### 3. No Webhook Replay Protection
**File:** `class-wc-breeze-payment-gateway.php` L300+  
There's no timestamp validation or nonce/idempotency key on webhooks. While signature verification prevents forgery, a captured valid webhook could be replayed. The `is_paid()` check provides partial protection for success events, but failure webhooks have no replay guard — a replayed `payment.failed` could flip a paid order to failed.

**Mitigation:** Store processed webhook IDs (or use a timestamp window). For failure webhooks, check if order is already paid before setting to failed.

#### 4. Failure Webhook Can Override Completed Payment
**File:** `class-wc-breeze-payment-gateway.php` L404-414  
`handle_payment_failed_webhook()` calls `update_status('failed')` without checking `$order->is_paid()`. If a delayed failure webhook arrives after a success webhook, it will override the completed order.

**Mitigation:** Add `if ($order->is_paid()) return;` guard.

#### 5. Race Condition: Webhook vs Return URL
**File:** `class-wc-breeze-payment-gateway.php`  
If the webhook fires before `handle_return()`, the return URL handler still works correctly (it checks `is_paid()` and skips the status change). However, if **both** fail (webhook doesn't fire, customer doesn't return), the order stays `pending` indefinitely with no cleanup mechanism.

**Mitigation:** Add a scheduled event (wp_cron) to expire stale pending Breeze orders after a configurable timeout (e.g., 24h).

#### 6. No Rate Limiting on Return URL Endpoint
**File:** `class-wc-breeze-payment-gateway.php` L271+  
The `handle_return` endpoint is publicly accessible. While the one-time token prevents order manipulation, an attacker can still probe order IDs and observe timing differences (valid vs. invalid orders). The token is consumed on first valid hit, so a second legitimate return would fail.

**Mitigation:** Consider not consuming the token immediately, or redirect to a generic page regardless of token validity.

#### 7. WooCommerce Active Check Ignores Multisite
**File:** `breeze-payment-gateway.php` L35-37  
The `active_plugins` check doesn't account for network-activated WooCommerce in multisite. Use `is_plugin_active()` or check `get_site_option('active_sitewide_plugins')` as well.

### 🟢 Low

#### 8. Blocks Support CSS/JS Enqueued Globally
**File:** `class-wc-breeze-blocks-support.php` L107-112  
`wp_enqueue_style('wc-breeze-blocks')` is called at file include time (outside a function/hook), meaning it runs on every page load, not just checkout. Minor performance issue.

#### 9. `process_refund()` Declares Support But Returns Error
**File:** `class-wc-breeze-payment-gateway.php` L82, L266  
The gateway declares `'refunds'` in `$this->supports` but `process_refund()` always returns a WP_Error. This is misleading — the refund button will appear in admin but always fail.

**Mitigation:** Either remove `'refunds'` from supports, or implement the API call.

#### 10. No Currency Validation
**File:** `class-wc-breeze-payment-gateway.php`  
The currency from `$order->get_currency()` is passed directly to Breeze without validating it's a currency Breeze supports. Non-supported currencies would fail at the API level with a potentially unhelpful error.

**Mitigation:** Add `is_available()` override to check currency against supported list, or provide a better error message.

#### 11. Missing `$order_id` Type Validation in Webhook
**File:** `class-wc-breeze-payment-gateway.php` L383  
`str_replace` returns a string, and `wc_get_order()` can accept strings, but no `absint()` is applied. A crafted `clientReferenceId` like `order-1 OR 1=1` would just return false from `wc_get_order()` (not SQL injectable since WC uses prepared statements), but it's still sloppy.

### ℹ️ Info

#### 12. API Key in Basic Auth
The API key is sent as Basic auth (`base64_encode($this->api_key . ':')`) — standard pattern, but ensure HTTPS is enforced (it is, via `https://api.breeze.cash`).

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

All 46 existing tests pass. Tests cover core logic well but are pure unit simulations — no integration with actual WooCommerce classes.

---

## Recommendations (Priority Order)

1. **🔴 Verify payment page ID in webhook** before marking order paid
2. **🔴 Redact PII from debug logs**
3. **🟡 Guard failure webhook against overriding paid orders**
4. **🟡 Add stale order cleanup cron**
5. **🟡 Add webhook replay protection**
6. **🟢 Remove `'refunds'` from supports or implement it**
7. **🟢 Fix blocks CSS global enqueue**
8. **🟢 Add currency validation**
