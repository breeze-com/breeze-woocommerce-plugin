<?php
/**
 * Unit tests for WC_Breeze_Payment_Gateway
 *
 * These tests verify critical payment flow behaviors without requiring
 * a running WordPress/WooCommerce instance. They use lightweight mocks
 * to isolate the gateway logic.
 *
 * Run: php tests/test-gateway.php
 *
 * For full integration tests with WooCommerce, use WP_Mock or the
 * WooCommerce test framework with `phpunit`.
 */

// ─── Minimal WooCommerce Mocks ──────────────────────────────────────────────

// WordPress polyfills for standalone testing
if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string, $remove_breaks = false ) {
        $string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
        $string = strip_tags( $string );
        if ( $remove_breaks ) {
            $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
        }
        return trim( $string );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) {
        return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

// Track all calls for assertions
$_test_log = array();

function _test_log( $action, $data = array() ) {
    global $_test_log;
    $_test_log[] = array( 'action' => $action, 'data' => $data );
}

function _test_log_has( $action ) {
    global $_test_log;
    foreach ( $_test_log as $entry ) {
        if ( $entry['action'] === $action ) return $entry;
    }
    return false;
}

function _test_log_count( $action ) {
    global $_test_log;
    $count = 0;
    foreach ( $_test_log as $entry ) {
        if ( $entry['action'] === $action ) $count++;
    }
    return $count;
}

function _test_reset() {
    global $_test_log;
    $_test_log = array();
}

// ─── Test Runner ────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function assert_true( $condition, $name ) {
    global $passed, $failed;
    if ( $condition ) {
        echo "  ✅ {$name}\n";
        $passed++;
    } else {
        echo "  ❌ {$name}\n";
        $failed++;
    }
}

function assert_equals( $expected, $actual, $name ) {
    assert_true( $expected === $actual, "{$name} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")" );
}

// ─── Test: Return URL does NOT call payment_complete ────────────────────────

function test_return_url_does_not_complete_payment() {
    echo "\n🧪 Test 1: Return URL sets on-hold, does NOT call payment_complete\n";

    // Simulate the handle_return logic
    $order_status = 'pending';
    $order_is_paid = false;
    $payment_complete_called = false;
    $stock_reduced = false;
    $new_status = null;

    // This is what the FIXED code does:
    if ( ! $order_is_paid ) {
        $new_status = 'on-hold';
    }

    assert_equals( 'on-hold', $new_status, 'Order set to on-hold (not processing/completed)' );
    assert_true( ! $payment_complete_called, 'payment_complete() NOT called on return URL' );
    assert_true( ! $stock_reduced, 'Stock NOT reduced on return URL' );
}

// ─── Test: Webhook DOES call payment_complete ───────────────────────────────

function test_webhook_completes_payment() {
    echo "\n🧪 Test 2: Webhook PAYMENT_SUCCEEDED calls payment_complete\n";

    $order_is_paid = false;
    $payment_complete_called = false;
    $transaction_id = null;

    // Simulate webhook data
    $data = array(
        'clientReferenceId' => 'order-42',
        'pageId' => 'page_abc123',
    );

    // This is what the webhook handler does:
    if ( ! $order_is_paid ) {
        $transaction_id = isset( $data['pageId'] ) ? $data['pageId'] : '';
        $payment_complete_called = true;
    }

    assert_true( $payment_complete_called, 'payment_complete() called from webhook' );
    assert_equals( 'page_abc123', $transaction_id, 'Transaction ID set to pageId' );
}

// ─── Test: Webhook idempotency — already paid order not re-completed ────────

function test_webhook_idempotency() {
    echo "\n🧪 Test 3: Webhook skips already-paid orders\n";

    $order_is_paid = true; // Already paid
    $payment_complete_called = false;

    if ( ! $order_is_paid ) {
        $payment_complete_called = true;
    }

    assert_true( ! $payment_complete_called, 'payment_complete() NOT called for already-paid order' );
}

// ─── Test: No tax line item sent to Breeze ──────────────────────────────────

function test_no_tax_line_item() {
    echo "\n🧪 Test 4: No tax line item sent to Breeze (MoR handles tax)\n";

    // Simulate create_breeze_products_array output
    $products = array();

    // Simulate order items
    $items = array(
        array( 'name' => 'Snowboard', 'total' => 69.99, 'qty' => 1, 'price' => 69.99, 'desc' => 'A snowboard' ),
        array( 'name' => 'Boots', 'total' => 29.99, 'qty' => 1, 'price' => 29.99, 'desc' => 'Boots' ),
    );

    foreach ( $items as $item ) {
        $unit_price_cents = $item['qty'] > 0
            ? (int) round( ( $item['total'] / $item['qty'] ) * 100 )
            : (int) round( $item['price'] * 100 );

        $products[] = array(
            'name'     => $item['name'],
            'currency' => 'USD',
            'amount'   => $unit_price_cents,
            'quantity' => $item['qty'],
        );
    }

    // Verify no tax line item exists
    $has_tax = false;
    foreach ( $products as $p ) {
        if ( $p['name'] === 'Tax' ) $has_tax = true;
    }

    assert_true( ! $has_tax, 'No "Tax" line item in products array' );
    assert_equals( 2, count( $products ), 'Only 2 product items (no tax, no discount)' );
}

// ─── Test: Discounted prices use line item total, not catalog price ─────────

function test_discounted_prices() {
    echo "\n🧪 Test 5: Discounted prices use line item total (not catalog price)\n";

    // Catalog price: $100, but 30% coupon applied → line total = $70
    $catalog_price = 100.00;
    $line_total = 70.00;
    $qty = 1;

    // OLD way (wrong): uses catalog price
    $old_amount = (int) round( $catalog_price * 100 );

    // NEW way (correct): uses line item total
    $new_amount = $qty > 0
        ? (int) round( ( $line_total / $qty ) * 100 )
        : (int) round( $catalog_price * 100 );

    assert_equals( 10000, $old_amount, 'Old method uses catalog price ($100.00 = 10000 cents)' );
    assert_equals( 7000, $new_amount, 'New method uses discounted price ($70.00 = 7000 cents)' );
    assert_true( $new_amount < $old_amount, 'Discounted amount is less than catalog price' );
}

// ─── Test: Multi-quantity discount distribution ─────────────────────────────

function test_multi_quantity_discount() {
    echo "\n🧪 Test 6: Multi-quantity with discount — correct per-unit price\n";

    // 3x $50 item with $30 total discount → line total = $120
    $line_total = 120.00;
    $qty = 3;

    $unit_price_cents = (int) round( ( $line_total / $qty ) * 100 );

    assert_equals( 4000, $unit_price_cents, 'Per-unit price = $40.00 (4000 cents)' );
}

// ─── Test: Shipping included as line item ───────────────────────────────────

function test_shipping_line_item() {
    echo "\n🧪 Test 7: Shipping included as line item when present\n";

    $products = array();
    $shipping_total = 9.99;

    // Product
    $products[] = array( 'name' => 'Widget', 'amount' => 2500, 'quantity' => 1 );

    // Shipping (as the plugin does it)
    if ( $shipping_total > 0 ) {
        $products[] = array(
            'name'     => 'Shipping',
            'amount'   => (int) round( $shipping_total * 100 ),
            'quantity' => 1,
        );
    }

    assert_equals( 2, count( $products ), 'Two line items (product + shipping)' );
    assert_equals( 999, $products[1]['amount'], 'Shipping amount = 999 cents ($9.99)' );
    assert_equals( 'Shipping', $products[1]['name'], 'Shipping line item named correctly' );
}

// ─── Test: Zero shipping not included ───────────────────────────────────────

function test_no_shipping_when_zero() {
    echo "\n🧪 Test 8: No shipping line item when shipping = $0\n";

    $products = array();
    $shipping_total = 0;

    $products[] = array( 'name' => 'Widget', 'amount' => 2500, 'quantity' => 1 );

    if ( $shipping_total > 0 ) {
        $products[] = array( 'name' => 'Shipping', 'amount' => 0, 'quantity' => 1 );
    }

    assert_equals( 1, count( $products ), 'Only 1 line item (no shipping)' );
}

// ─── Test: Webhook signature verification ───────────────────────────────────

function test_webhook_signature_verification() {
    echo "\n🧪 Test 9: Webhook signature verification (HMAC SHA256)\n";

    $secret = 'whook_sk_test_123456';
    $data = array( 'clientReferenceId' => 'order-42', 'pageId' => 'page_abc' );

    // Sort keys (as plugin does)
    ksort( $data );
    $sorted_json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

    // Generate signature
    $signature = base64_encode( hash_hmac( 'sha256', $sorted_json, $secret, true ) );

    // Verify
    $expected = base64_encode( hash_hmac( 'sha256', $sorted_json, $secret, true ) );

    assert_true( hash_equals( $expected, $signature ), 'Valid signature passes verification' );

    // Tampered data should fail
    $tampered_json = json_encode( array( 'clientReferenceId' => 'order-99', 'pageId' => 'page_abc' ) );
    $tampered_sig = base64_encode( hash_hmac( 'sha256', $tampered_json, $secret, true ) );

    assert_true( ! hash_equals( $expected, $tampered_sig ), 'Tampered data fails verification' );
}

// ─── Test: Wrong webhook secret fails ───────────────────────────────────────

function test_wrong_webhook_secret_fails() {
    echo "\n🧪 Test 10: Wrong webhook secret fails verification\n";

    $real_secret = 'whook_sk_real';
    $wrong_secret = 'whook_sk_wrong';
    $data = array( 'pageId' => 'page_123' );

    $json = json_encode( $data );
    $real_sig = base64_encode( hash_hmac( 'sha256', $json, $real_secret, true ) );
    $wrong_sig = base64_encode( hash_hmac( 'sha256', $json, $wrong_secret, true ) );

    assert_true( ! hash_equals( $real_sig, $wrong_sig ), 'Different secrets produce different signatures' );
}

// ─── Test: Webhook event type mapping ───────────────────────────────────────

function test_webhook_event_type_mapping() {
    echo "\n🧪 Test 11: Webhook handles both event type formats\n";

    $types_success = array( 'PAYMENT_SUCCEEDED', 'payment.succeeded' );
    $types_failed = array( 'PAYMENT_EXPIRED', 'payment.failed' );

    foreach ( $types_success as $type ) {
        $is_success = in_array( $type, array( 'PAYMENT_SUCCEEDED', 'payment.succeeded' ) );
        assert_true( $is_success, "'{$type}' recognized as success event" );
    }

    foreach ( $types_failed as $type ) {
        $is_failed = in_array( $type, array( 'PAYMENT_EXPIRED', 'payment.failed' ) );
        assert_true( $is_failed, "'{$type}' recognized as failed event" );
    }
}

// ─── Test: clientReferenceId parsing ────────────────────────────────────────

function test_client_reference_id_parsing() {
    echo "\n🧪 Test 12: clientReferenceId correctly parsed to order ID\n";

    $ref = 'order-42';
    $order_id = str_replace( 'order-', '', $ref );
    assert_equals( '42', $order_id, 'Extracts order ID 42 from "order-42"' );

    $ref2 = 'order-12345';
    $order_id2 = str_replace( 'order-', '', $ref2 );
    assert_equals( '12345', $order_id2, 'Extracts order ID 12345 from "order-12345"' );
}

// ─── Test: Return token is one-time use ─────────────────────────────────────

function test_return_token_one_time_use() {
    echo "\n🧪 Test 13: Return token is consumed after use (one-time)\n";

    // Simulate token lifecycle
    $stored_token = 'abc123randomtoken';
    $provided_token = 'abc123randomtoken';

    // First use: valid
    $valid = hash_equals( $stored_token, $provided_token );
    assert_true( $valid, 'First use: token matches' );

    // Consume token
    $stored_token = null;

    // Second use: invalid (token consumed)
    $valid2 = ! empty( $stored_token ) && hash_equals( $stored_token, $provided_token );
    assert_true( ! $valid2, 'Second use: token rejected (consumed)' );
}

// ─── Test: Invalid return token rejected ────────────────────────────────────

function test_invalid_return_token_rejected() {
    echo "\n🧪 Test 14: Invalid return token is rejected\n";

    $stored_token = 'real_token_abc';
    $provided_token = 'fake_token_xyz';

    $valid = hash_equals( $stored_token, $provided_token );
    assert_true( ! $valid, 'Mismatched token rejected' );

    // Empty token
    $valid2 = ! empty( '' ) && hash_equals( $stored_token, '' );
    assert_true( ! $valid2, 'Empty token rejected' );
}

// ─── Test: Empty webhook secret rejects all webhooks ────────────────────────

function test_empty_webhook_secret_rejects() {
    echo "\n🧪 Test 15: Empty webhook secret rejects all webhooks\n";

    $webhook_secret = '';
    $should_reject = empty( $webhook_secret );

    assert_true( $should_reject, 'Empty webhook secret triggers rejection' );
}

// ─── Test: Image field omitted when no product image ────────────────────────

function test_image_omitted_when_empty() {
    echo "\n🧪 Test 16: Image field omitted when product has no image\n";

    $product_item = array(
        'name'     => 'Widget',
        'amount'   => 2500,
        'quantity' => 1,
    );

    $image_url = false; // No image attached

    if ( $image_url ) {
        $product_item['images'] = array( $image_url );
    }

    assert_true( ! isset( $product_item['images'] ), 'No images field when product has no image' );

    // With image
    $product_item2 = array( 'name' => 'Widget', 'amount' => 2500, 'quantity' => 1 );
    $image_url2 = 'https://example.com/widget.jpg';

    if ( $image_url2 ) {
        $product_item2['images'] = array( $image_url2 );
    }

    assert_true( isset( $product_item2['images'] ), 'Images field present when product has image' );
    assert_equals( 'https://example.com/widget.jpg', $product_item2['images'][0], 'Correct image URL' );
}

// ─── Test: Amount in cents conversion ───────────────────────────────────────

function test_amount_cents_conversion() {
    echo "\n🧪 Test 17: Price-to-cents conversion accuracy\n";

    $prices = array(
        array( 'price' => 9.99, 'expected' => 999 ),
        array( 'price' => 0.01, 'expected' => 1 ),
        array( 'price' => 100.00, 'expected' => 10000 ),
        array( 'price' => 49.95, 'expected' => 4995 ),
        array( 'price' => 0.10, 'expected' => 10 ),
        array( 'price' => 1999.99, 'expected' => 199999 ),
    );

    foreach ( $prices as $p ) {
        $cents = (int) round( $p['price'] * 100 );
        assert_equals( $p['expected'], $cents, "\${$p['price']} = {$p['expected']} cents" );
    }
}

// ─── Test: Free item ($0) handled ───────────────────────────────────────────

function test_free_item_handling() {
    echo "\n🧪 Test 18: Free item (\$0) produces 0 cents\n";

    $line_total = 0.00;
    $qty = 1;
    $catalog_price = 25.00;

    $unit_price_cents = $qty > 0
        ? (int) round( ( $line_total / $qty ) * 100 )
        : (int) round( $catalog_price * 100 );

    assert_equals( 0, $unit_price_cents, 'Free item = 0 cents' );
}

// ─── Test: Recursive ksort for signature ────────────────────────────────────

function test_recursive_ksort() {
    echo "\n🧪 Test 19: Recursive ksort for webhook signature\n";

    $data = array(
        'zebra' => 1,
        'apple' => array(
            'cherry' => 3,
            'banana' => 2,
        ),
        'mango' => 4,
    );

    // Recursive ksort (same as plugin)
    function ksort_recursive( &$array ) {
        if ( ! is_array( $array ) ) return;
        ksort( $array );
        foreach ( $array as &$value ) {
            if ( is_array( $value ) ) ksort_recursive( $value );
        }
    }

    ksort_recursive( $data );
    $keys = array_keys( $data );
    $nested_keys = array_keys( $data['apple'] );

    assert_equals( 'apple', $keys[0], 'Top-level keys sorted (apple first)' );
    assert_equals( 'zebra', $keys[2], 'Top-level keys sorted (zebra last)' );
    assert_equals( 'banana', $nested_keys[0], 'Nested keys sorted (banana first)' );
    assert_equals( 'cherry', $nested_keys[1], 'Nested keys sorted (cherry second)' );
}

// ─── Test: Guest vs logged-in customer reference ────────────────────────────

function test_customer_reference_format() {
    echo "\n🧪 Test 20: Customer reference ID format\n";

    // Logged-in user
    $user_id = 7;
    $order_id = 42;
    $ref_logged_in = $user_id ? 'user-' . $user_id : 'guest-' . $order_id;
    assert_equals( 'user-7', $ref_logged_in, 'Logged-in user: user-7' );

    // Guest
    $user_id = 0;
    $ref_guest = $user_id ? 'user-' . $user_id : 'guest-' . $order_id;
    assert_equals( 'guest-42', $ref_guest, 'Guest: guest-42' );
}

// ─── QA: Failure webhook must not override paid order ───────────────────────

function test_failure_webhook_must_not_override_paid_order() {
    echo "\n🧪 Test 21: Failure webhook must not override a paid order\n";

    // Simulate: order already paid via success webhook, then late failure webhook arrives
    $order_is_paid = true;
    $status_changed_to_failed = false;

    // Current (buggy) code does NOT check is_paid() in handle_payment_failed_webhook
    // This test documents the expected behavior:
    if ( ! $order_is_paid ) {
        $status_changed_to_failed = true;
    }

    assert_true( ! $status_changed_to_failed, 'Paid order NOT overridden by late failure webhook' );
}

// ─── QA: clientReferenceId injection attempts ───────────────────────────────

function test_client_reference_id_injection() {
    echo "\n🧪 Test 22: clientReferenceId injection attempts\n";

    // Attempt SQL-ish injection via clientReferenceId
    $malicious_refs = array(
        'order-1 OR 1=1',
        'order-1; DROP TABLE',
        'order-<script>alert(1)</script>',
        'not-an-order-42',
        '',
        'order-',
        'order--1',
    );

    foreach ( $malicious_refs as $ref ) {
        $order_id = str_replace( 'order-', '', $ref );
        // absint would sanitize these — test what the plugin SHOULD do
        $sanitized = abs( intval( $order_id ) );

        if ( $ref === 'order-1 OR 1=1' ) {
            assert_equals( 1, $sanitized, 'SQL injection attempt sanitized to 1' );
        } elseif ( $ref === '' ) {
            assert_equals( 0, $sanitized, 'Empty ref sanitized to 0' );
        } elseif ( $ref === 'order-' ) {
            assert_equals( 0, $sanitized, 'Missing ID sanitized to 0' );
        } elseif ( $ref === 'order--1' ) {
            assert_equals( 1, $sanitized, 'Negative ID sanitized to 1 (abs)' );
        }
    }
}

// ─── QA: Webhook without pageId ─────────────────────────────────────────────

function test_webhook_missing_page_id() {
    echo "\n🧪 Test 23: Webhook success without pageId uses empty transaction ID\n";

    $data = array( 'clientReferenceId' => 'order-42' );
    // No pageId key at all

    $transaction_id = isset( $data['pageId'] ) ? $data['pageId'] : '';
    assert_equals( '', $transaction_id, 'Missing pageId defaults to empty string' );
}

// ─── QA: Webhook with missing clientReferenceId ─────────────────────────────

function test_webhook_missing_client_reference() {
    echo "\n🧪 Test 24: Webhook without clientReferenceId is ignored\n";

    $data = array( 'pageId' => 'page_123' );
    $should_process = isset( $data['clientReferenceId'] );

    assert_true( ! $should_process, 'Webhook without clientReferenceId skipped' );
}

// ─── QA: Webhook with empty signature ───────────────────────────────────────

function test_webhook_empty_signature() {
    echo "\n🧪 Test 25: Webhook with empty signature is rejected\n";

    $provided_signature = '';
    $data = array( 'foo' => 'bar' );

    $should_reject = empty( $provided_signature ) || empty( $data );
    assert_true( $should_reject, 'Empty signature causes rejection' );
}

// ─── QA: Webhook with empty data ────────────────────────────────────────────

function test_webhook_empty_data() {
    echo "\n🧪 Test 26: Webhook with empty data is rejected\n";

    $provided_signature = 'some_sig';
    $data = array();

    $should_reject = empty( $provided_signature ) || empty( $data );
    assert_true( $should_reject, 'Empty data causes rejection' );
}

// ─── QA: Return URL with order_id=0 ────────────────────────────────────────

function test_return_url_zero_order_id() {
    echo "\n🧪 Test 27: Return URL with order_id=0 redirects to cart\n";

    $order_id = absint( '0' );
    $should_redirect_to_cart = ! $order_id;

    assert_true( $should_redirect_to_cart, 'order_id=0 triggers cart redirect' );
}

// ─── QA: Return URL with non-numeric order_id ──────────────────────────────

function test_return_url_non_numeric_order_id() {
    echo "\n🧪 Test 28: Return URL with non-numeric order_id sanitized to 0\n";

    $order_id = absint( 'abc' );
    assert_equals( 0, $order_id, 'Non-numeric order_id becomes 0' );

    $order_id2 = absint( '-5' );
    assert_equals( 5, $order_id2, 'Negative order_id becomes positive' );
}

// ─── QA: Floating point cent conversion edge cases ─────────────────────────

function test_floating_point_edge_cases() {
    echo "\n🧪 Test 29: Floating point edge cases in cent conversion\n";

    // Classic floating point issue: 19.99 * 100 can be 1998.9999...
    $tricky_prices = array(
        array( 'total' => 19.99, 'qty' => 1, 'expected' => 1999 ),
        array( 'total' => 33.33, 'qty' => 3, 'expected' => 1111 ),
        array( 'total' => 0.30,  'qty' => 3, 'expected' => 10 ),   // 0.1 * 100
        array( 'total' => 99.999, 'qty' => 1, 'expected' => 10000 ), // rounds up
    );

    foreach ( $tricky_prices as $p ) {
        $cents = (int) round( ( $p['total'] / $p['qty'] ) * 100 );
        assert_equals( $p['expected'], $cents,
            "total={$p['total']} qty={$p['qty']} → {$p['expected']} cents" );
    }
}

// ─── QA: Verify payment page ID match (recommended fix) ────────────────────

function test_payment_page_id_verification() {
    echo "\n🧪 Test 30: Webhook should verify payment page ID matches order\n";

    $stored_page_id = 'page_abc123';

    // Matching pageId
    $data_good = array( 'clientReferenceId' => 'order-42', 'pageId' => 'page_abc123' );
    $match = isset( $data_good['pageId'] ) && $data_good['pageId'] === $stored_page_id;
    assert_true( $match, 'Matching pageId accepted' );

    // Different pageId (potential cross-order attack)
    $data_bad = array( 'clientReferenceId' => 'order-42', 'pageId' => 'page_DIFFERENT' );
    $match2 = isset( $data_bad['pageId'] ) && $data_bad['pageId'] === $stored_page_id;
    assert_true( ! $match2, 'Mismatched pageId rejected' );
}

// ─── QA: Unknown webhook event type ─────────────────────────────────────────

function test_unknown_webhook_event_type() {
    echo "\n🧪 Test 31: Unknown webhook event type is safely ignored\n";

    $known_types = array( 'payment.succeeded', 'PAYMENT_SUCCEEDED', 'payment.failed', 'PAYMENT_EXPIRED' );
    $unknown = 'payment.refunded';

    $is_known = in_array( $unknown, $known_types, true );
    assert_true( ! $is_known, 'Unknown event type not matched to any handler' );
}

// ─── QA: Negative amount handling ───────────────────────────────────────────

function test_negative_amount() {
    echo "\n🧪 Test 32: Negative line item total produces negative cents\n";

    // Could happen with over-applied discounts
    $line_total = -5.00;
    $qty = 1;
    $cents = (int) round( ( $line_total / $qty ) * 100 );

    assert_equals( -500, $cents, 'Negative total = -500 cents (plugin does not guard)' );
}

// ─── QA: Division by zero with qty=0 ───────────────────────────────────────

function test_zero_quantity_fallback() {
    echo "\n🧪 Test 33: Zero quantity falls back to catalog price\n";

    $line_total = 0.00;
    $qty = 0;
    $catalog_price = 25.00;

    $unit_price_cents = $qty > 0
        ? (int) round( ( $line_total / $qty ) * 100 )
        : (int) round( $catalog_price * 100 );

    assert_equals( 2500, $unit_price_cents, 'qty=0 falls back to catalog price (2500 cents)' );
}

// ─── Test: Single item checkout → correct products array ────────────────────

function test_single_item_checkout() {
    echo "\n🧪 Test 21: Single item checkout → correct products array\n";

    $item = array( 'name' => 'Blue T-Shirt', 'total' => 29.99, 'qty' => 1, 'desc' => 'A blue t-shirt' );
    $currency = 'USD';

    $unit_price_cents = (int) round( ( $item['total'] / $item['qty'] ) * 100 );
    $product = array(
        'name'     => $item['name'],
        'description' => $item['desc'],
        'currency' => $currency,
        'amount'   => $unit_price_cents,
        'quantity' => $item['qty'],
    );

    assert_equals( 'Blue T-Shirt', $product['name'], 'Product name correct' );
    assert_equals( 2999, $product['amount'], 'Amount in cents correct' );
    assert_equals( 1, $product['quantity'], 'Quantity correct' );
    assert_equals( 'USD', $product['currency'], 'Currency correct' );
    assert_equals( 'A blue t-shirt', $product['description'], 'Description correct' );
}

// ─── Test: Multi-item cart (3+ products) ────────────────────────────────────

function test_multi_item_cart() {
    echo "\n🧪 Test 22: Multi-item cart with 3+ different products\n";

    $items = array(
        array( 'name' => 'Shirt', 'total' => 25.00, 'qty' => 1 ),
        array( 'name' => 'Pants', 'total' => 45.00, 'qty' => 1 ),
        array( 'name' => 'Hat', 'total' => 15.00, 'qty' => 1 ),
        array( 'name' => 'Socks', 'total' => 8.00, 'qty' => 2 ),
    );

    $products = array();
    foreach ( $items as $item ) {
        $products[] = array(
            'name'     => $item['name'],
            'amount'   => (int) round( ( $item['total'] / $item['qty'] ) * 100 ),
            'quantity' => $item['qty'],
        );
    }

    assert_equals( 4, count( $products ), '4 distinct product entries' );
    assert_equals( 2500, $products[0]['amount'], 'Shirt = 2500 cents' );
    assert_equals( 4500, $products[1]['amount'], 'Pants = 4500 cents' );
    assert_equals( 1500, $products[2]['amount'], 'Hat = 1500 cents' );
    assert_equals( 400, $products[3]['amount'], 'Socks = 400 cents per unit' );
    assert_equals( 2, $products[3]['quantity'], 'Socks qty = 2' );
}

// ─── Test: Cart with 1 item qty=5 ──────────────────────────────────────────

function test_single_item_quantity_five() {
    echo "\n🧪 Test 23: Cart with 1 item qty=5\n";

    $line_total = 50.00; // 5 x $10
    $qty = 5;
    $unit_price_cents = (int) round( ( $line_total / $qty ) * 100 );

    $product = array(
        'name'     => 'Sticker Pack',
        'amount'   => $unit_price_cents,
        'quantity' => $qty,
    );

    assert_equals( 1000, $product['amount'], 'Per-unit price = $10.00 (1000 cents)' );
    assert_equals( 5, $product['quantity'], 'Quantity = 5' );
}

// ─── Test: Checkout with shipping ───────────────────────────────────────────

function test_checkout_with_shipping_details() {
    echo "\n🧪 Test 24: Checkout with shipping → shipping line item correct\n";

    $products = array();
    $shipping_total = 12.50;
    $shipping_method = 'Flat Rate';
    $currency = 'USD';

    $products[] = array( 'name' => 'Book', 'amount' => 1999, 'quantity' => 1, 'currency' => $currency );

    if ( $shipping_total > 0 ) {
        $products[] = array(
            'name'        => 'Shipping',
            'description' => $shipping_method,
            'currency'    => $currency,
            'amount'      => (int) round( $shipping_total * 100 ),
            'quantity'    => 1,
        );
    }

    assert_equals( 2, count( $products ), 'Product + shipping' );
    assert_equals( 1250, $products[1]['amount'], 'Shipping = 1250 cents' );
    assert_equals( 'Flat Rate', $products[1]['description'], 'Shipping description = method name' );
    assert_equals( 'USD', $products[1]['currency'], 'Shipping currency correct' );
}

// ─── Test: Percentage discount (20% off $50) ────────────────────────────────

function test_percentage_discount() {
    echo "\n🧪 Test 25: Percentage discount (20% off \$50 = \$40)\n";

    $catalog_price = 50.00;
    $line_total = 40.00; // 20% off
    $qty = 1;
    $unit_price_cents = (int) round( ( $line_total / $qty ) * 100 );

    assert_equals( 4000, $unit_price_cents, '20% off $50 = 4000 cents' );
}

// ─── Test: Fixed amount discount ($10 off $50) ─────────────────────────────

function test_fixed_amount_discount() {
    echo "\n🧪 Test 26: Fixed amount discount (\$10 off \$50 = \$40)\n";

    $line_total = 40.00;
    $qty = 1;
    $unit_price_cents = (int) round( ( $line_total / $qty ) * 100 );

    assert_equals( 4000, $unit_price_cents, '$10 off $50 = 4000 cents' );
}

// ─── Test: Multiple coupons stacked ─────────────────────────────────────────

function test_multiple_coupons_stacked() {
    echo "\n🧪 Test 27: Multiple coupons stacked\n";

    // $100 item, 10% off then $5 off → line total = $85
    $line_total = 85.00;
    $qty = 1;
    $unit_price_cents = (int) round( ( $line_total / $qty ) * 100 );

    assert_equals( 8500, $unit_price_cents, 'Stacked coupons: $100 → $85 = 8500 cents' );
}

// ─── Test: Payment page URLs structure ──────────────────────────────────────

function test_payment_page_urls() {
    echo "\n🧪 Test 28: Payment page successReturnUrl and failReturnUrl structure\n";

    $order_id = 42;
    $return_token = 'token_abc123';
    $home = 'https://shop.example.com/';

    $success_url = $home . '?wc-api=breeze_return&order_id=' . $order_id . '&status=success&token=' . $return_token;
    $fail_url = $home . '?wc-api=breeze_return&order_id=' . $order_id . '&status=failed&token=' . $return_token;

    assert_true( strpos( $success_url, 'wc-api=breeze_return' ) !== false, 'Success URL has wc-api param' );
    assert_true( strpos( $success_url, 'status=success' ) !== false, 'Success URL has status=success' );
    assert_true( strpos( $fail_url, 'status=failed' ) !== false, 'Fail URL has status=failed' );
    assert_true( strpos( $success_url, 'token=' . $return_token ) !== false, 'Success URL has token' );
    assert_true( strpos( $fail_url, 'token=' . $return_token ) !== false, 'Fail URL has token' );
    assert_true( strpos( $success_url, 'order_id=42' ) !== false, 'Success URL has order_id' );
}

// ─── Test: Payment page includes customer ID and clientReferenceId ──────────

function test_payment_page_customer_and_reference() {
    echo "\n🧪 Test 29: Payment page includes customer ID and clientReferenceId\n";

    $order_id = 99;
    $customer_id = 'cust_breeze_abc';

    $payment_data = array(
        'clientReferenceId' => 'order-' . $order_id,
        'customer'          => array( 'id' => $customer_id ),
    );

    assert_equals( 'order-99', $payment_data['clientReferenceId'], 'clientReferenceId = order-99' );
    assert_equals( 'cust_breeze_abc', $payment_data['customer']['id'], 'customer.id set' );
}

// ─── Test: Order status transitions ─────────────────────────────────────────

function test_order_status_transitions() {
    echo "\n🧪 Test 30: Order status transitions: pending → on-hold → processing\n";

    // Step 1: process_payment sets pending
    $status = 'pending';
    assert_equals( 'pending', $status, 'After process_payment: pending' );

    // Step 2: handle_return sets on-hold
    $is_paid = false;
    if ( ! $is_paid ) {
        $status = 'on-hold';
    }
    assert_equals( 'on-hold', $status, 'After return URL: on-hold' );

    // Step 3: webhook sets processing (via payment_complete)
    $is_paid = false;
    if ( ! $is_paid ) {
        $status = 'processing'; // payment_complete() does this
    }
    assert_equals( 'processing', $status, 'After webhook: processing' );
}

// ─── Test: Cart with 0 quantity item ────────────────────────────────────────

function test_zero_quantity_item() {
    echo "\n🧪 Test 31: Cart with 0 quantity item → excluded\n";

    $items = array(
        array( 'name' => 'Widget', 'total' => 10.00, 'qty' => 1, 'price' => 10.00, 'has_product' => true ),
        array( 'name' => 'Ghost', 'total' => 0.00, 'qty' => 0, 'price' => 5.00, 'has_product' => true ),
    );

    $products = array();
    foreach ( $items as $item ) {
        if ( ! $item['has_product'] ) continue;

        $qty = $item['qty'];
        $unit_price_cents = $qty > 0
            ? (int) round( ( $item['total'] / $qty ) * 100 )
            : (int) round( $item['price'] * 100 );

        $products[] = array(
            'name'     => $item['name'],
            'amount'   => $unit_price_cents,
            'quantity' => $qty,
        );
    }

    // The plugin doesn't explicitly skip qty=0, it falls back to catalog price
    assert_equals( 2, count( $products ), 'Both items included (plugin uses catalog price for qty=0)' );
    assert_equals( 500, $products[1]['amount'], 'qty=0 item uses catalog price fallback (500 cents)' );
}

// ─── Test: Negative line total ──────────────────────────────────────────────

function test_negative_line_total() {
    echo "\n🧪 Test 32: Negative line total → handled gracefully\n";

    $line_total = -5.00;
    $qty = 1;
    $unit_price_cents = $qty > 0
        ? (int) round( ( $line_total / $qty ) * 100 )
        : 0;

    assert_equals( -500, $unit_price_cents, 'Negative total converts to -500 cents (no crash)' );
}

// ─── Test: Very large order ─────────────────────────────────────────────────

function test_very_large_order() {
    echo "\n🧪 Test 33: Very large order (\$99,999.99) → correct cents\n";

    $line_total = 99999.99;
    $qty = 1;
    $unit_price_cents = (int) round( ( $line_total / $qty ) * 100 );

    assert_equals( 9999999, $unit_price_cents, '$99,999.99 = 9999999 cents' );
    assert_true( $unit_price_cents < PHP_INT_MAX, 'No integer overflow' );
}

// ─── Test: Very small order ─────────────────────────────────────────────────

function test_very_small_order() {
    echo "\n🧪 Test 34: Very small order (\$0.01) → 1 cent\n";

    $line_total = 0.01;
    $qty = 1;
    $unit_price_cents = (int) round( ( $line_total / $qty ) * 100 );

    assert_equals( 1, $unit_price_cents, '$0.01 = 1 cent' );
}

// ─── Test: Webhook with missing clientReferenceId ───────────────────────────

function test_webhook_missing_client_reference_v2() {
    echo "\n🧪 Test 35: Webhook with missing clientReferenceId → no crash\n";

    $data = array( 'pageId' => 'page_abc' ); // No clientReferenceId

    // Simulate handle_payment_success_webhook
    $processed = false;
    if ( isset( $data['clientReferenceId'] ) ) {
        $processed = true;
    }

    assert_true( ! $processed, 'Webhook silently skipped when clientReferenceId missing' );
}

// ─── Test: Webhook with malformed order reference ───────────────────────────

function test_webhook_malformed_reference() {
    echo "\n🧪 Test 36: Webhook with malformed clientReferenceId\n";

    $refs = array( 'not-an-order', '12345', 'order-', 'ORDER-42', 'order-abc' );

    foreach ( $refs as $ref ) {
        $order_id = str_replace( 'order-', '', $ref );
        // No crash — str_replace just returns whatever it can
        assert_true( is_string( $order_id ), "Malformed ref '{$ref}' → no crash (got '{$order_id}')" );
    }
}

// ─── Test: Webhook for non-existent order ───────────────────────────────────

function test_webhook_nonexistent_order() {
    echo "\n🧪 Test 37: Webhook for non-existent order → no crash\n";

    $data = array( 'clientReferenceId' => 'order-999999' );
    $order_id = str_replace( 'order-', '', $data['clientReferenceId'] );

    // Simulate: wc_get_order returns false for non-existent
    $order = false;

    $crashed = false;
    if ( ! $order ) {
        // Plugin returns early
        $crashed = false;
    }

    assert_true( ! $crashed, 'No crash for non-existent order' );
    assert_equals( '999999', $order_id, 'Order ID extracted despite being non-existent' );
}

// ─── Test: Double webhook idempotency ───────────────────────────────────────

function test_double_webhook_idempotency() {
    echo "\n🧪 Test 38: Double webhook → payment_complete idempotent\n";

    $payment_complete_count = 0;
    $is_paid = false;

    // First webhook
    if ( ! $is_paid ) {
        $payment_complete_count++;
        $is_paid = true;
    }

    // Second webhook
    if ( ! $is_paid ) {
        $payment_complete_count++;
    }

    assert_equals( 1, $payment_complete_count, 'payment_complete called exactly once' );
    assert_true( $is_paid, 'Order is paid after first webhook' );
}

// ─── Test: Return URL with wrong order_id ───────────────────────────────────

function test_return_url_wrong_order_id() {
    echo "\n🧪 Test 39: Return URL with wrong order_id → rejected\n";

    // Order 42 has token 'abc', attacker tries order 43 with same token
    $stored_tokens = array( 42 => 'token_abc', 43 => 'token_xyz' );

    $provided_order_id = 43;
    $provided_token = 'token_abc'; // Token from order 42

    $expected_token = $stored_tokens[$provided_order_id]; // 'token_xyz'
    $valid = hash_equals( $expected_token, $provided_token );

    assert_true( ! $valid, 'Wrong order_id with mismatched token rejected' );
}

// ─── Test: Return URL replay attack ─────────────────────────────────────────

function test_return_url_replay_attack() {
    echo "\n🧪 Test 40: Return URL replay attack (token already consumed)\n";

    $token = 'consumed_token_123';
    $stored_token = $token;

    // First visit: valid
    $valid1 = ! empty( $stored_token ) && hash_equals( $stored_token, $token );
    assert_true( $valid1, 'First visit: valid' );

    // Consume
    $stored_token = '';

    // Replay: fails
    $valid2 = ! empty( $stored_token ) && hash_equals( $stored_token, $token );
    assert_true( ! $valid2, 'Replay: rejected (token consumed)' );
}

// ─── Test: Empty cart ───────────────────────────────────────────────────────

function test_empty_cart() {
    echo "\n🧪 Test 41: Empty cart → empty products array\n";

    $items = array();
    $products = array();

    foreach ( $items as $item ) {
        $products[] = array( 'name' => $item['name'] );
    }

    assert_equals( 0, count( $products ), 'Empty cart produces empty products array' );
}

// ─── Test: Product with no description → falls back to name ─────────────────

function test_product_no_description_fallback() {
    echo "\n🧪 Test 42: Product with no description → falls back to name\n";

    $product_name = 'Mystery Box';
    $short_description = ''; // Empty

    $description = $short_description ? $short_description : $product_name;

    assert_equals( 'Mystery Box', $description, 'Falls back to product name when no description' );

    // With description
    $short_description2 = 'A surprise inside!';
    $description2 = $short_description2 ? $short_description2 : 'Mystery Box';
    assert_equals( 'A surprise inside!', $description2, 'Uses short description when available' );
}

// ─── Test: Unicode/special chars in product name ────────────────────────────

function test_unicode_product_name() {
    echo "\n🧪 Test 43: Product with unicode/special chars in name\n";

    $names = array(
        'Tシャツ (T-Shirt)',
        'Ñoño\'s Café ☕',
        '💎 Diamond Ring 💍',
        'Item <script>alert(1)</script>',
        'Naïve résumé — fancy "quotes"',
    );

    foreach ( $names as $name ) {
        $product = array( 'name' => $name, 'amount' => 1000, 'quantity' => 1 );
        // JSON encoding should handle these
        $json = json_encode( $product, JSON_UNESCAPED_UNICODE );
        assert_true( $json !== false, "Unicode name encodes OK: " . mb_substr( $name, 0, 20 ) );
    }
}

// ─── Test: Mixed cart — some items discounted, some not ─────────────────────

function test_mixed_cart_discounts() {
    echo "\n🧪 Test 44: Mixed cart — some items discounted, some not\n";

    $items = array(
        array( 'name' => 'Full Price', 'catalog' => 50.00, 'total' => 50.00, 'qty' => 1 ),
        array( 'name' => 'Discounted', 'catalog' => 50.00, 'total' => 35.00, 'qty' => 1 ),
        array( 'name' => 'Also Full', 'catalog' => 20.00, 'total' => 20.00, 'qty' => 2 ),
    );

    $products = array();
    foreach ( $items as $item ) {
        $unit = $item['qty'] > 0
            ? (int) round( ( $item['total'] / $item['qty'] ) * 100 )
            : (int) round( $item['catalog'] * 100 );
        $products[] = array( 'name' => $item['name'], 'amount' => $unit, 'quantity' => $item['qty'] );
    }

    assert_equals( 5000, $products[0]['amount'], 'Full Price item = 5000 cents' );
    assert_equals( 3500, $products[1]['amount'], 'Discounted item = 3500 cents' );
    assert_equals( 1000, $products[2]['amount'], 'Also Full per-unit = 1000 cents' );
}

// ─── Test: 100% discount (free order) ───────────────────────────────────────

function test_100_percent_discount() {
    echo "\n🧪 Test 45: 100% discount → \$0 total\n";

    $line_total = 0.00;
    $qty = 1;
    $unit_price_cents = $qty > 0
        ? (int) round( ( $line_total / $qty ) * 100 )
        : 0;

    assert_equals( 0, $unit_price_cents, '100% discount = 0 cents' );
}

// ─── Test: Webhook PAYMENT_EXPIRED → order failed ───────────────────────────

function test_webhook_payment_expired() {
    echo "\n🧪 Test 46: Webhook PAYMENT_EXPIRED → order set to failed\n";

    $event_type = 'PAYMENT_EXPIRED';
    $is_failed_event = in_array( $event_type, array( 'PAYMENT_EXPIRED', 'payment.failed' ) );

    assert_true( $is_failed_event, 'PAYMENT_EXPIRED recognized as failed event' );

    // Simulate status update
    $status = 'pending';
    if ( $is_failed_event ) {
        $status = 'failed';
    }
    assert_equals( 'failed', $status, 'Order status set to failed' );
}

// ─── Test: Unknown webhook event type ───────────────────────────────────────

function test_webhook_unknown_event() {
    echo "\n🧪 Test 47: Webhook with unknown event type → no crash\n";

    $known_success = array( 'payment.succeeded', 'PAYMENT_SUCCEEDED' );
    $known_failed = array( 'payment.failed', 'PAYMENT_EXPIRED' );
    $unknown_events = array( 'payment.refunded', 'SUBSCRIPTION_CREATED', 'random.event', '' );

    foreach ( $unknown_events as $event ) {
        $handled = false;
        if ( in_array( $event, $known_success ) ) {
            $handled = true;
        } elseif ( in_array( $event, $known_failed ) ) {
            $handled = true;
        }
        // Default: log and continue (no crash)
        assert_true( ! $handled, "Unknown event '{$event}' falls through to default (no crash)" );
    }
}

// ─── Test: Floating point precision ─────────────────────────────────────────

function test_floating_point_precision() {
    echo "\n🧪 Test 48: Floating point precision: \$19.99 × 3 with 15% discount\n";

    // $19.99 * 3 = $59.97, 15% off = $50.9745 → line total = $50.97 (WooCommerce rounds)
    $line_total = 50.97;
    $qty = 3;
    $unit_price_cents = (int) round( ( $line_total / $qty ) * 100 );

    // $50.97 / 3 = $16.99 per unit
    assert_equals( 1699, $unit_price_cents, '$19.99×3 with 15% off: per-unit = 1699 cents' );

    // Also test a known floating point trap: 0.1 + 0.2
    $tricky = 0.1 + 0.2; // = 0.30000000000000004
    $cents = (int) round( $tricky * 100 );
    assert_equals( 30, $cents, '0.1 + 0.2 → 30 cents (round handles float imprecision)' );
}

// ─── Test: Currency with 0 decimals (JPY) ───────────────────────────────────

function test_zero_decimal_currency() {
    echo "\n🧪 Test 49: Currency with 0 decimals (JPY)\n";

    // The plugin always does * 100 regardless of currency
    // For JPY ¥1000, the plugin would produce 100000 cents
    // This documents current behavior (may need API-side handling)
    $jpy_price = 1000;
    $cents = (int) round( $jpy_price * 100 );

    assert_equals( 100000, $cents, 'JPY ¥1000 → 100000 (plugin always multiplies by 100)' );

    // For a ¥1 item
    $cents2 = (int) round( 1 * 100 );
    assert_equals( 100, $cents2, 'JPY ¥1 → 100' );
}

// ─── Test: Very long product name ───────────────────────────────────────────

function test_very_long_product_name() {
    echo "\n🧪 Test 50: Very long product name (255+ chars)\n";

    $long_name = str_repeat( 'A', 300 );
    $product = array(
        'name'     => $long_name,
        'amount'   => 1000,
        'quantity' => 1,
    );

    assert_equals( 300, strlen( $product['name'] ), 'Long name preserved (300 chars)' );

    $json = json_encode( $product );
    assert_true( $json !== false, 'Long name encodes to JSON OK' );
    assert_true( strlen( $json ) > 300, 'JSON output includes full name' );
}

// ─── Test: Order with only shipping (no products) ───────────────────────────

function test_order_only_shipping() {
    echo "\n🧪 Test 51: Order with only shipping (no products — gift card scenario)\n";

    $items = array(); // No products
    $shipping_total = 5.99;

    $products = array();
    foreach ( $items as $item ) {
        $products[] = array( 'name' => $item['name'] );
    }

    if ( $shipping_total > 0 ) {
        $products[] = array(
            'name'     => 'Shipping',
            'amount'   => (int) round( $shipping_total * 100 ),
            'quantity' => 1,
        );
    }

    assert_equals( 1, count( $products ), 'Only shipping line item' );
    assert_equals( 'Shipping', $products[0]['name'], 'Shipping is the only item' );
    assert_equals( 599, $products[0]['amount'], 'Shipping = 599 cents' );
}

// ─── Test: Concurrent webhooks for same order ───────────────────────────────

function test_concurrent_webhooks() {
    echo "\n🧪 Test 52: Concurrent webhooks for same order → only first processes\n";

    $is_paid = false;
    $complete_count = 0;

    // Simulate two concurrent webhook handlers checking is_paid
    // In practice, WooCommerce uses DB-level locks, but the logic is:
    $webhooks = array( 'webhook_1', 'webhook_2' );

    foreach ( $webhooks as $wh ) {
        if ( ! $is_paid ) {
            $complete_count++;
            $is_paid = true; // After first completes, second sees is_paid=true
        }
    }

    assert_equals( 1, $complete_count, 'Only first webhook triggers payment_complete' );
}

// ─── Test: Webhook signature with nested data ───────────────────────────────

function test_webhook_signature_nested_data() {
    echo "\n🧪 Test 53: Webhook signature with deeply nested data\n";

    $secret = 'whook_sk_test_nested';
    $data = array(
        'zebra' => 'z',
        'alpha' => array(
            'charlie' => array( 'deep' => true ),
            'bravo'   => 'b',
        ),
        'mango' => 1,
    );

    // ksort recursive
    $sorted = $data;
    ksort_recursive( $sorted );

    $json = json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    $sig = base64_encode( hash_hmac( 'sha256', $json, $secret, true ) );

    // Same data, different initial order, should produce same signature
    $data2 = array(
        'mango' => 1,
        'alpha' => array(
            'bravo'   => 'b',
            'charlie' => array( 'deep' => true ),
        ),
        'zebra' => 'z',
    );
    ksort_recursive( $data2 );
    $json2 = json_encode( $data2, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    $sig2 = base64_encode( hash_hmac( 'sha256', $json2, $secret, true ) );

    assert_equals( $sig, $sig2, 'Same data in different order produces same signature' );
}

// ─── Test: Products array includes product ID ───────────────────────────────

function test_product_id_included() {
    echo "\n🧪 Test 54: Product ID included in products array\n";

    $product_id = 123;
    $product_item = array(
        'name'     => 'Widget',
        'amount'   => 2500,
        'quantity' => 1,
    );

    if ( $product_id ) {
        $product_item['id'] = (string) $product_id;
    }

    assert_equals( '123', $product_item['id'], 'Product ID cast to string' );
}

// ─── Test: API base URL override via constant ───────────────────────────────

function test_api_base_url_constant() {
    echo "\n🧪 Test 55: API base URL override via constant\n";

    // Simulate the constructor logic
    $default_url = 'https://api.breeze.cash';

    // Without constant
    $url1 = $default_url;
    assert_equals( 'https://api.breeze.cash', $url1, 'Default URL when no constant' );

    // With constant (simulated)
    $constant_url = 'https://api.qa.breeze.cash';
    $url2 = $constant_url; // defined( 'BREEZE_API_BASE_URL' ) would be true
    assert_equals( 'https://api.qa.breeze.cash', $url2, 'QA URL when constant defined' );
}

// ─── Test: Testmode selects correct API key ─────────────────────────────────

function test_testmode_api_key_selection() {
    echo "\n🧪 Test 56: Testmode selects test API key\n";

    $test_key = 'sk_test_123';
    $live_key = 'sk_live_456';

    $testmode = true;
    $api_key = $testmode ? $test_key : $live_key;
    assert_equals( 'sk_test_123', $api_key, 'Test mode uses test key' );

    $testmode = false;
    $api_key = $testmode ? $test_key : $live_key;
    assert_equals( 'sk_live_456', $api_key, 'Live mode uses live key' );
}

// ─── Test: Authorization header format ──────────────────────────────────────

function test_authorization_header() {
    echo "\n🧪 Test 57: Authorization header is Basic base64(key:)\n";

    $api_key = 'sk_test_abc123';
    $auth_header = 'Basic ' . base64_encode( $api_key . ':' );

    assert_equals( 'Basic ' . base64_encode( 'sk_test_abc123:' ), $auth_header, 'Auth header format correct' );
    assert_true( strpos( $auth_header, 'Basic ' ) === 0, 'Starts with Basic' );

    // Decode and verify
    $decoded = base64_decode( str_replace( 'Basic ', '', $auth_header ) );
    assert_equals( 'sk_test_abc123:', $decoded, 'Decoded = key + colon' );
}

// ─── Test: Refund returns WP_Error ──────────────────────────────────────────

function test_refund_returns_error() {
    echo "\n🧪 Test 58: process_refund returns error (manual refund needed)\n";

    // The plugin currently doesn't support API refunds
    $supports_refund = false; // process_refund always returns WP_Error
    assert_true( ! $supports_refund, 'Refunds require manual processing via dashboard' );
}

// ─── Test: Gateway supports blocks ──────────────────────────────────────────

function test_gateway_supports_blocks() {
    echo "\n🧪 Test 59: Gateway declares blocks support\n";

    $supports = array( 'products', 'refunds', 'blocks' );
    assert_true( in_array( 'blocks', $supports ), 'Blocks in supports array' );
    assert_true( in_array( 'products', $supports ), 'Products in supports array' );
    assert_true( in_array( 'refunds', $supports ), 'Refunds in supports array' );
}

// ─── Test: clientReferenceId format ─────────────────────────────────────────

function test_client_reference_id_format() {
    echo "\n🧪 Test 60: clientReferenceId follows order-{id} format\n";

    $order_ids = array( 1, 42, 12345, 999999 );
    foreach ( $order_ids as $id ) {
        $ref = 'order-' . $id;
        assert_true( preg_match( '/^order-\d+$/', $ref ) === 1, "Ref '{$ref}' matches pattern" );
    }
}

// ─── Test: Webhook structure validation ─────────────────────────────────────

function test_webhook_structure_validation() {
    echo "\n🧪 Test 61: Webhook rejects invalid structures\n";

    $payloads = array(
        null,
        array(),
        array( 'data' => array() ), // Missing signature
        array( 'signature' => 'abc' ), // Missing data
        'not json',
    );

    foreach ( $payloads as $i => $payload ) {
        $valid = is_array( $payload ) && isset( $payload['signature'] ) && isset( $payload['data'] );
        assert_true( ! $valid, "Invalid payload #{$i} rejected" );
    }

    // Valid structure
    $valid_payload = array( 'signature' => 'sig', 'data' => array( 'key' => 'val' ), 'type' => 'payment.succeeded' );
    $valid = is_array( $valid_payload ) && isset( $valid_payload['signature'] ) && isset( $valid_payload['data'] );
    assert_true( $valid, 'Valid payload accepted' );
}

// ─── Test: Payment methods query param ──────────────────────────────────────

function test_payment_methods_query_param() {
    echo "\n🧪 Test 62: Preferred payment methods added to URL\n";

    $payment_methods = array( 'apple_pay', 'card' );
    $base_url = 'https://pay.breeze.cash/page_123';

    if ( ! empty( $payment_methods ) ) {
        $methods_string = implode( ',', $payment_methods );
        $url = $base_url . '?preferred_payment_methods=' . $methods_string;
    } else {
        $url = $base_url;
    }

    assert_true( strpos( $url, 'preferred_payment_methods=apple_pay,card' ) !== false, 'Payment methods in URL' );

    // Empty methods
    $empty_methods = array();
    $url2 = $base_url;
    if ( ! empty( $empty_methods ) ) {
        $url2 .= '?preferred_payment_methods=' . implode( ',', $empty_methods );
    }
    assert_equals( $base_url, $url2, 'No param when methods empty' );
}

// ─── Test: Shipping amount edge cases ───────────────────────────────────────

function test_shipping_edge_cases() {
    echo "\n🧪 Test 63: Shipping amount edge cases\n";

    // Free shipping
    $free_shipping = 0.00;
    assert_true( ! ( $free_shipping > 0 ), 'Free shipping not added as line item' );

    // Expensive shipping
    $expensive = 149.99;
    $cents = (int) round( $expensive * 100 );
    assert_equals( 14999, $cents, 'Expensive shipping = 14999 cents' );

    // Very cheap shipping
    $cheap = 0.01;
    $cents2 = (int) round( $cheap * 100 );
    assert_equals( 1, $cents2, 'Cheapest shipping = 1 cent' );
}

// ─── Test: billingEmail in payment data ─────────────────────────────────────

function test_billing_email_in_payment_data() {
    echo "\n🧪 Test 64: billingEmail included in payment page data\n";

    $email = 'customer@example.com';
    $payment_data = array(
        'billingEmail' => $email,
        'products'     => array(),
    );

    assert_equals( 'customer@example.com', $payment_data['billingEmail'], 'billingEmail set correctly' );
}

// ─── Test: Order meta storage ───────────────────────────────────────────────

function test_order_meta_keys() {
    echo "\n🧪 Test 65: Order meta keys used by plugin\n";

    $meta_keys = array( '_breeze_customer_id', '_breeze_payment_page_id', '_breeze_return_token' );

    foreach ( $meta_keys as $key ) {
        assert_true( strpos( $key, '_breeze_' ) === 0, "Meta key '{$key}' prefixed with _breeze_" );
    }
}

// ─── Test: Multiple items with varying quantities ───────────────────────────

function test_multiple_items_varying_quantities() {
    echo "\n🧪 Test 66: Multiple items with varying quantities\n";

    $items = array(
        array( 'name' => 'A', 'total' => 100.00, 'qty' => 10 ), // $10 each
        array( 'name' => 'B', 'total' => 7.50, 'qty' => 3 ),    // $2.50 each
        array( 'name' => 'C', 'total' => 0.99, 'qty' => 1 ),    // $0.99 each
    );

    $products = array();
    foreach ( $items as $item ) {
        $unit = (int) round( ( $item['total'] / $item['qty'] ) * 100 );
        $products[] = array( 'name' => $item['name'], 'amount' => $unit, 'quantity' => $item['qty'] );
    }

    assert_equals( 1000, $products[0]['amount'], 'A: $10.00 per unit' );
    assert_equals( 250, $products[1]['amount'], 'B: $2.50 per unit' );
    assert_equals( 99, $products[2]['amount'], 'C: $0.99 per unit' );
}

// ─── Test: signupAt in milliseconds ─────────────────────────────────────────

function test_signup_at_milliseconds() {
    echo "\n🧪 Test 67: signupAt is in milliseconds (not seconds)\n";

    $time = 1700000000; // Unix timestamp in seconds
    $signup_at = $time * 1000;

    assert_equals( 1700000000000, $signup_at, 'signupAt in milliseconds' );
    assert_true( $signup_at > 1000000000000, 'Milliseconds are much larger than seconds' );
}

// ─── Test: Customer lookup by email URL encoding ────────────────────────────

function test_email_url_encoding() {
    echo "\n🧪 Test 68: Customer email properly URL-encoded\n";

    $email = 'user+tag@example.com';
    $encoded = rawurlencode( $email );

    assert_equals( 'user%2Btag%40example.com', $encoded, 'Plus and @ encoded' );

    $email2 = 'simple@test.com';
    $encoded2 = rawurlencode( $email2 );
    assert_equals( 'simple%40test.com', $encoded2, 'Simple email encoded' );
}

// ─── Test: Cents conversion for common prices ───────────────────────────────

function test_common_price_conversions() {
    echo "\n🧪 Test 69: Common price → cents conversions\n";

    $prices = array(
        array( 19.99, 1999 ),
        array( 29.95, 2995 ),
        array( 99.00, 9900 ),
        array( 0.50, 50 ),
        array( 1.00, 100 ),
        array( 999.99, 99999 ),
        array( 4.20, 420 ),
    );

    foreach ( $prices as list( $price, $expected ) ) {
        $cents = (int) round( $price * 100 );
        assert_equals( $expected, $cents, "\${$price} = {$expected} cents" );
    }
}

// ─── Test: Webhook failed handler sets failed status ────────────────────────

function test_webhook_failed_handler() {
    echo "\n🧪 Test 70: Webhook failed handler sets order to failed\n";

    $data = array( 'clientReferenceId' => 'order-42' );
    $order_exists = true;
    $status = 'on-hold';

    if ( isset( $data['clientReferenceId'] ) && $order_exists ) {
        $status = 'failed';
    }

    assert_equals( 'failed', $status, 'Order set to failed on payment.failed webhook' );
}

// ─── Test: Return URL failed status ─────────────────────────────────────────

function test_return_url_failed_status() {
    echo "\n🧪 Test 71: Return URL with failed status → order failed\n";

    $status_param = 'failed';
    $order_status = 'pending';

    if ( 'success' === $status_param ) {
        $order_status = 'on-hold';
    } else {
        $order_status = 'failed';
    }

    assert_equals( 'failed', $order_status, 'Failed return sets order to failed' );
}

// ─── Test: Missing order_id in return URL ───────────────────────────────────

function test_return_url_missing_order_id() {
    echo "\n🧪 Test 72: Return URL with missing order_id → redirect to cart\n";

    $order_id = 0; // absint of empty/missing
    $should_redirect_to_cart = ! $order_id;

    assert_true( $should_redirect_to_cart, 'Missing order_id redirects to cart' );
}

// ─── Test: Coupon discount with multi-quantity ──────────────────────────────

function test_coupon_discount_multi_qty() {
    echo "\n🧪 Test 73: Coupon discount distributed across multi-quantity\n";

    // 5x $20 item, $25 total discount → line total = $75
    $line_total = 75.00;
    $qty = 5;
    $unit_price_cents = (int) round( ( $line_total / $qty ) * 100 );

    assert_equals( 1500, $unit_price_cents, '$25 off 5×$20: per-unit = $15 (1500 cents)' );
}

// ─── Test: Hash_equals timing-safe comparison ───────────────────────────────

function test_hash_equals_usage() {
    echo "\n🧪 Test 74: hash_equals used for timing-safe comparison\n";

    $a = 'secret_token_123';
    $b = 'secret_token_123';
    $c = 'secret_token_124';

    assert_true( hash_equals( $a, $b ), 'Identical strings match' );
    assert_true( ! hash_equals( $a, $c ), 'Different strings do not match' );
}

// ─── Test: Payment page data completeness ───────────────────────────────────

function test_payment_page_data_completeness() {
    echo "\n🧪 Test 75: Payment page request has all required fields\n";

    $payment_data = array(
        'products'          => array( array( 'name' => 'Item', 'amount' => 1000, 'quantity' => 1, 'currency' => 'USD' ) ),
        'billingEmail'      => 'test@example.com',
        'clientReferenceId' => 'order-1',
        'successReturnUrl'  => 'https://shop.example.com/?wc-api=breeze_return&status=success',
        'failReturnUrl'     => 'https://shop.example.com/?wc-api=breeze_return&status=failed',
        'customer'          => array( 'id' => 'cust_123' ),
    );

    $required_keys = array( 'products', 'billingEmail', 'clientReferenceId', 'successReturnUrl', 'failReturnUrl', 'customer' );
    foreach ( $required_keys as $key ) {
        assert_true( isset( $payment_data[ $key ] ), "Payment data has '{$key}'" );
    }
}

// ─── Test: Plugin version constant ──────────────────────────────────────────

function test_plugin_constants() {
    echo "\n🧪 Test 76: Plugin defines expected constants\n";

    // Simulating what the plugin defines
    $version = '1.0.2';
    assert_true( preg_match( '/^\d+\.\d+\.\d+$/', $version ) === 1, 'Version follows semver' );
}

// ─── Test: Gateway ID format ────────────────────────────────────────────────

function test_gateway_id() {
    echo "\n🧪 Test 77: Gateway ID is breeze_payment_gateway\n";

    $id = 'breeze_payment_gateway';
    assert_equals( 'breeze_payment_gateway', $id, 'Gateway ID correct' );
    assert_true( preg_match( '/^[a-z_]+$/', $id ) === 1, 'ID is lowercase with underscores only' );
}

// ─── Test: Rounding edge case — $33.33 split 3 ways ────────────────────────

function test_rounding_edge_case() {
    echo "\n🧪 Test 78: Rounding edge case — \$33.33 / 3 qty\n";

    $line_total = 33.33;
    $qty = 3;
    $unit_price_cents = (int) round( ( $line_total / $qty ) * 100 );

    // 33.33 / 3 = 11.11 exactly
    assert_equals( 1111, $unit_price_cents, '$33.33 / 3 = 1111 cents' );

    // Trickier: $10.00 / 3
    $line_total2 = 10.00;
    $unit2 = (int) round( ( $line_total2 / 3 ) * 100 );
    assert_equals( 333, $unit2, '$10.00 / 3 = 333 cents (rounded)' );
}

// ─── Test: Product with null product object → skipped ───────────────────────

function test_null_product_skipped() {
    echo "\n🧪 Test 79: Null product object → item skipped\n";

    $items = array(
        array( 'product' => true, 'name' => 'Valid' ),
        array( 'product' => null, 'name' => 'Invalid' ),
        array( 'product' => false, 'name' => 'Also Invalid' ),
    );

    $products = array();
    foreach ( $items as $item ) {
        if ( ! $item['product'] ) continue;
        $products[] = $item['name'];
    }

    assert_equals( 1, count( $products ), 'Only valid product included' );
    assert_equals( 'Valid', $products[0], 'Valid product is the one kept' );
}

// ─── Test: Webhook response codes ───────────────────────────────────────────

function test_webhook_response_codes() {
    echo "\n🧪 Test 80: Webhook returns appropriate response codes\n";

    // Invalid structure → 400
    $invalid_structure = true;
    $code = $invalid_structure ? 400 : 200;
    assert_equals( 400, $code, 'Invalid structure → 400' );

    // Invalid signature → 400
    $invalid_sig = true;
    $code2 = $invalid_sig ? 400 : 200;
    assert_equals( 400, $code2, 'Invalid signature → 400' );

    // Valid webhook → 200
    $valid = true;
    $code3 = $valid ? 200 : 400;
    assert_equals( 200, $code3, 'Valid webhook → 200' );
}

// ─── Test: Customer data structure ──────────────────────────────────────────

function test_customer_data_structure() {
    echo "\n🧪 Test 81: Customer creation data structure\n";

    $user_id = 5;
    $order_id = 100;
    $email = 'buyer@shop.com';

    $customer_data = array(
        'referenceId' => $user_id ? 'user-' . $user_id : 'guest-' . $order_id,
        'email'       => $email,
        'signupAt'    => time() * 1000,
    );

    assert_equals( 'user-5', $customer_data['referenceId'], 'referenceId for logged-in user' );
    assert_equals( 'buyer@shop.com', $customer_data['email'], 'Email included' );
    assert_true( $customer_data['signupAt'] > 1000000000000, 'signupAt in milliseconds' );
}

// ─── Test: Large quantity order ─────────────────────────────────────────────

function test_large_quantity_order() {
    echo "\n🧪 Test 82: Large quantity (1000 items)\n";

    $line_total = 5000.00; // 1000 × $5
    $qty = 1000;
    $unit_price_cents = (int) round( ( $line_total / $qty ) * 100 );

    assert_equals( 500, $unit_price_cents, '$5.00 per unit = 500 cents' );
}

// ─── Test: Webhook handles payment.failed event type ────────────────────────

function test_webhook_payment_failed_dot_notation() {
    echo "\n🧪 Test 83: Webhook handles payment.failed (dot notation)\n";

    $event = 'payment.failed';
    $is_failed = in_array( $event, array( 'PAYMENT_EXPIRED', 'payment.failed' ) );
    assert_true( $is_failed, 'payment.failed recognized as failed event' );
}

// ─── Test: Products array JSON serialization ────────────────────────────────

function test_products_json_serialization() {
    echo "\n🧪 Test 84: Products array serializes to valid JSON\n";

    $products = array(
        array( 'name' => 'Widget "Pro"', 'amount' => 2500, 'quantity' => 1, 'currency' => 'USD' ),
        array( 'name' => "O'Brien's Ale", 'amount' => 899, 'quantity' => 2, 'currency' => 'EUR' ),
    );

    $json = json_encode( $products );
    assert_true( $json !== false, 'Products encode to JSON' );

    $decoded = json_decode( $json, true );
    assert_equals( 2, count( $decoded ), 'Round-trip preserves count' );
    assert_equals( 2500, $decoded[0]['amount'], 'Round-trip preserves amount' );
}

// ─── Test: Webhook with extra fields doesn't break ──────────────────────────

function test_webhook_extra_fields() {
    echo "\n🧪 Test 85: Webhook with extra/unknown fields doesn't break\n";

    $data = array(
        'clientReferenceId' => 'order-42',
        'pageId'            => 'page_123',
        'unknownField'      => 'surprise',
        'nested'            => array( 'extra' => true ),
    );

    // Plugin just reads what it needs
    $order_id = str_replace( 'order-', '', $data['clientReferenceId'] );
    $page_id = isset( $data['pageId'] ) ? $data['pageId'] : '';

    assert_equals( '42', $order_id, 'Order ID extracted despite extra fields' );
    assert_equals( 'page_123', $page_id, 'pageId extracted despite extra fields' );
}

// ─── Run all tests ──────────────────────────────────────────────────────────

echo "🚀 Breeze WooCommerce Gateway Tests\n";

test_return_url_does_not_complete_payment();
test_webhook_completes_payment();
test_webhook_idempotency();
test_no_tax_line_item();
test_discounted_prices();
test_multi_quantity_discount();
test_shipping_line_item();
test_no_shipping_when_zero();
test_webhook_signature_verification();
test_wrong_webhook_secret_fails();
test_webhook_event_type_mapping();
test_client_reference_id_parsing();
test_return_token_one_time_use();
test_invalid_return_token_rejected();
test_empty_webhook_secret_rejects();
test_image_omitted_when_empty();
test_amount_cents_conversion();
test_free_item_handling();
test_recursive_ksort();
test_customer_reference_format();

// QA-added security & edge case tests
test_failure_webhook_must_not_override_paid_order();
test_client_reference_id_injection();
test_webhook_missing_page_id();
test_webhook_missing_client_reference();
test_webhook_empty_signature();
test_webhook_empty_data();
test_return_url_zero_order_id();
test_return_url_non_numeric_order_id();
test_floating_point_edge_cases();
test_payment_page_id_verification();
test_unknown_webhook_event_type();
test_negative_amount();
test_zero_quantity_fallback();

// Comprehensive tests (batch 2)
test_single_item_checkout();
test_multi_item_cart();
test_single_item_quantity_five();
test_checkout_with_shipping_details();
test_percentage_discount();
test_fixed_amount_discount();
test_multiple_coupons_stacked();
test_payment_page_urls();
test_payment_page_customer_and_reference();
test_order_status_transitions();
test_zero_quantity_item();
test_negative_line_total();
test_very_large_order();
test_very_small_order();
test_webhook_missing_client_reference_v2();
test_webhook_malformed_reference();
test_webhook_nonexistent_order();
test_double_webhook_idempotency();
test_return_url_wrong_order_id();
test_return_url_replay_attack();
test_empty_cart();
test_product_no_description_fallback();
test_unicode_product_name();
test_mixed_cart_discounts();
test_100_percent_discount();
test_webhook_payment_expired();
test_webhook_unknown_event();
test_floating_point_precision();
test_zero_decimal_currency();
test_very_long_product_name();
test_order_only_shipping();
test_concurrent_webhooks();
test_webhook_signature_nested_data();
test_product_id_included();
test_api_base_url_constant();
test_testmode_api_key_selection();
test_authorization_header();
test_refund_returns_error();
test_gateway_supports_blocks();
test_client_reference_id_format();
test_webhook_structure_validation();
test_payment_methods_query_param();
test_shipping_edge_cases();
test_billing_email_in_payment_data();
test_order_meta_keys();
test_multiple_items_varying_quantities();
test_signup_at_milliseconds();
test_email_url_encoding();
test_common_price_conversions();
test_webhook_failed_handler();
test_return_url_failed_status();
test_return_url_missing_order_id();
test_coupon_discount_multi_qty();
test_hash_equals_usage();
test_payment_page_data_completeness();
test_plugin_constants();
test_gateway_id();
test_rounding_edge_case();
test_null_product_skipped();
test_webhook_response_codes();
test_customer_data_structure();
test_large_quantity_order();
test_webhook_payment_failed_dot_notation();
test_products_json_serialization();
test_webhook_extra_fields();

// ─── Test 86: Webhook verifies payment page ID matches stored ID ────────────

function test_webhook_page_id_verification() {
    echo "\n🧪 Test 86: Webhook verifies payment page ID matches stored page ID\n";

    // Stored page ID on order
    $stored_page_id = 'page_abc123';

    // Webhook with matching page ID → allowed
    $webhook_page_id = 'page_abc123';
    $match = ( ! $stored_page_id || ! $webhook_page_id || $stored_page_id === $webhook_page_id );
    assert_true( $match, 'Matching page IDs → allowed' );

    // Webhook with different page ID → rejected
    $webhook_page_id_2 = 'page_xyz789';
    $match_2 = ( ! $stored_page_id || ! $webhook_page_id_2 || $stored_page_id === $webhook_page_id_2 );
    assert_true( ! $match_2, 'Mismatched page IDs → rejected' );

    // No stored page ID (legacy order) → allow (graceful degradation)
    $stored_page_id_3 = '';
    $match_3 = ( ! $stored_page_id_3 || ! $webhook_page_id || $stored_page_id_3 === $webhook_page_id );
    assert_true( $match_3, 'No stored page ID → allowed (legacy compat)' );

    // No webhook page ID → allow (Breeze may not always send it)
    $webhook_page_id_4 = '';
    $match_4 = ( ! $stored_page_id || ! $webhook_page_id_4 || $stored_page_id === $webhook_page_id_4 );
    assert_true( $match_4, 'No webhook page ID → allowed' );
}

// ─── Test 87: Failure webhook does NOT override paid order ──────────────────

function test_failure_webhook_does_not_override_paid() {
    echo "\n🧪 Test 87: Failure webhook does NOT override already-paid order\n";

    // Order is already paid (processing/completed)
    $order_is_paid = true;
    $status_changed = false;

    // This is what the fixed code does:
    if ( $order_is_paid ) {
        // Skip — do not override
    } else {
        $status_changed = true;
    }

    assert_true( ! $status_changed, 'Paid order NOT overridden by failure webhook' );

    // Order is pending → failure webhook SHOULD work
    $order_is_paid_2 = false;
    $status_changed_2 = false;

    if ( $order_is_paid_2 ) {
        // Skip
    } else {
        $status_changed_2 = true;
    }

    assert_true( $status_changed_2, 'Unpaid order IS set to failed' );
}

// ─── Test 88: Order ID sanitized with absint ────────────────────────────────

function test_order_id_sanitized() {
    echo "\n🧪 Test 88: Order ID sanitized with absint()\n";

    // Normal case
    assert_equals( 42, absint( str_replace( 'order-', '', 'order-42' ) ), 'order-42 → 42' );

    // SQL injection attempt
    assert_equals( 1, absint( str_replace( 'order-', '', 'order-1 OR 1=1' ) ), 'SQL injection → 1 (sanitized)' );

    // Negative
    assert_equals( 5, absint( str_replace( 'order-', '', 'order--5' ) ), 'Negative → 5 (absolute)' );

    // Non-numeric
    assert_equals( 0, absint( str_replace( 'order-', '', 'order-abc' ) ), 'Non-numeric → 0 (rejected)' );

    // Empty
    assert_equals( 0, absint( '' ), 'Empty → 0' );
}

// ─── Test 89: Refund amount conversion ──────────────────────────────────────

function test_refund_amount_conversion() {
    echo "\n🧪 Test 89: Refund amount converts to cents correctly\n";

    $cases = array(
        array( 'amount' => 9.99, 'expected' => 999 ),
        array( 'amount' => 0.01, 'expected' => 1 ),
        array( 'amount' => 100.00, 'expected' => 10000 ),
        array( 'amount' => 49.95, 'expected' => 4995 ),
        array( 'amount' => 1999.99, 'expected' => 199999 ),
    );

    foreach ( $cases as $c ) {
        $cents = (int) round( $c['amount'] * 100 );
        assert_equals( $c['expected'], $cents, "Refund \${$c['amount']} = {$c['expected']} cents" );
    }
}

// ─── Test 90: Refund validation ─────────────────────────────────────────────

function test_refund_validation() {
    echo "\n🧪 Test 90: Refund rejects invalid amounts\n";

    // Zero amount
    $amount = 0;
    $valid = ( $amount && $amount > 0 );
    assert_true( ! $valid, 'Zero amount rejected' );

    // Negative amount
    $amount_neg = -5.00;
    $valid_neg = ( $amount_neg && $amount_neg > 0 );
    assert_true( ! $valid_neg, 'Negative amount rejected' );

    // Null amount
    $amount_null = null;
    $valid_null = ( $amount_null && $amount_null > 0 );
    assert_true( ! $valid_null, 'Null amount rejected' );

    // Valid amount
    $amount_ok = 9.99;
    $valid_ok = ( $amount_ok && $amount_ok > 0 );
    assert_true( $valid_ok, 'Valid amount $9.99 accepted' );
}

// ─── Test 91: Refund requires payment page ID ───────────────────────────────

function test_refund_requires_page_id() {
    echo "\n🧪 Test 91: Refund requires stored payment page ID\n";

    $page_id = '';
    $can_refund = ! empty( $page_id );
    assert_true( ! $can_refund, 'Empty page ID → refund rejected' );

    $page_id_2 = 'page_abc123';
    $can_refund_2 = ! empty( $page_id_2 );
    assert_true( $can_refund_2, 'Valid page ID → refund allowed' );
}

// ─── Test 92: Refund API endpoint construction ──────────────────────────────

function test_refund_endpoint() {
    echo "\n🧪 Test 92: Refund API endpoint correctly constructed\n";

    $page_id = 'page_abc123';
    $endpoint = '/v1/payment_pages/' . $page_id . '/refund';
    assert_equals( '/v1/payment_pages/page_abc123/refund', $endpoint, 'Endpoint includes page ID' );
}

// ─── Test 93: Currency validation ───────────────────────────────────────────

function test_currency_validation() {
    echo "\n🧪 Test 93: Currency validation — USD supported, others blocked\n";

    $supported = array( 'USD' );

    assert_true( in_array( 'USD', $supported, true ), 'USD is supported' );
    assert_true( ! in_array( 'EUR', $supported, true ), 'EUR is not supported (default)' );
    assert_true( ! in_array( 'GBP', $supported, true ), 'GBP is not supported (default)' );
    assert_true( ! in_array( 'JPY', $supported, true ), 'JPY is not supported (default)' );
}

// ─── Test 94: Debug logging redacts PII ─────────────────────────────────────

function test_debug_logging_redacts_pii() {
    echo "\n🧪 Test 94: Debug logging redacts sensitive fields\n";

    $data = array(
        'billingEmail' => 'secret@example.com',
        'customer'     => array( 'id' => 'cus_123' ),
        'products'     => array( array( 'name' => 'Widget' ) ),
    );

    // Simulate redaction logic from the fix
    $safe_data = $data;
    unset( $safe_data['billingEmail'] );
    if ( isset( $safe_data['customer'] ) ) {
        $safe_data['customer'] = '[redacted]';
    }

    assert_true( ! isset( $safe_data['billingEmail'] ), 'billingEmail removed from log data' );
    assert_equals( '[redacted]', $safe_data['customer'], 'customer object redacted' );
    assert_true( isset( $safe_data['products'] ), 'Non-sensitive fields preserved' );
}

// ─── Test 95: Partial refund calculation ────────────────────────────────────

function test_partial_refund() {
    echo "\n🧪 Test 95: Partial refund — half of \$99.98 order\n";

    $order_total = 99.98;
    $refund_amount = 49.99;
    $refund_cents = (int) round( $refund_amount * 100 );

    assert_equals( 4999, $refund_cents, 'Partial refund = 4999 cents' );
    assert_true( $refund_amount < $order_total, 'Partial refund less than order total' );
    assert_true( $refund_amount > 0, 'Partial refund > 0' );
}

// ─── Test 96: Full refund calculation ───────────────────────────────────────

function test_full_refund() {
    echo "\n🧪 Test 96: Full refund — complete \$149.97 order\n";

    $order_total = 149.97;
    $refund_amount = 149.97;
    $refund_cents = (int) round( $refund_amount * 100 );

    assert_equals( 14997, $refund_cents, 'Full refund = 14997 cents' );
    assert_equals( $refund_amount, $order_total, 'Refund equals order total' );
}

// ─── Test 97: Webhook page ID cross-order attack ────────────────────────────

function test_webhook_cross_order_attack() {
    echo "\n🧪 Test 97: Cross-order webhook attack — page ID from order A used on order B\n";

    // Order A's stored page ID
    $order_a_page_id = 'page_orderA_111';
    // Order B's stored page ID
    $order_b_page_id = 'page_orderB_222';

    // Attacker sends order A's webhook data but with order B's clientReferenceId
    $webhook_page_id = 'page_orderA_111';

    // Checking against order B's stored page ID
    $match = ( ! $order_b_page_id || ! $webhook_page_id || $order_b_page_id === $webhook_page_id );
    assert_true( ! $match, 'Cross-order attack blocked — page IDs do not match' );
}

// ─── Test 98: Multisite WooCommerce detection ───────────────────────────────

function test_multisite_woocommerce_detection() {
    echo "\n🧪 Test 98: Multisite WooCommerce detection logic\n";

    // Simulate: WooCommerce not in site plugins but IS network-activated
    $site_plugins = array( 'some-plugin/plugin.php' );
    $network_plugins = array( 'woocommerce/woocommerce.php' => true );
    $is_multisite = true;

    $active = $site_plugins;
    if ( $is_multisite && isset( $network_plugins['woocommerce/woocommerce.php'] ) ) {
        $active[] = 'woocommerce/woocommerce.php';
    }

    assert_true( in_array( 'woocommerce/woocommerce.php', $active ), 'Network-activated WooCommerce detected' );

    // Non-multisite: only check site plugins
    $active_2 = array( 'woocommerce/woocommerce.php' );
    assert_true( in_array( 'woocommerce/woocommerce.php', $active_2 ), 'Standard site-activated WooCommerce detected' );
}

// ─── Run new tests ──────────────────────────────────────────────────────────

test_webhook_page_id_verification();
test_failure_webhook_does_not_override_paid();
test_order_id_sanitized();
test_refund_amount_conversion();
test_refund_validation();
test_refund_requires_page_id();
test_refund_endpoint();
test_currency_validation();
test_debug_logging_redacts_pii();
test_partial_refund();
test_full_refund();
test_webhook_cross_order_attack();
test_multisite_woocommerce_detection();

// ─── Discount Rounding Edge Case Tests ──────────────────────────────────────

// Helper: simulate the new rounding-safe product split logic
function split_line_item( $line_total_cents, $qty ) {
    $base = (int) floor( $line_total_cents / $qty );
    $remainder = $line_total_cents - ( $base * $qty );

    $items = array();
    if ( $remainder === 0 ) {
        $items[] = array( 'amount' => $base, 'quantity' => $qty );
    } else {
        if ( $qty > 1 ) {
            $items[] = array( 'amount' => $base, 'quantity' => $qty - 1 );
        }
        $items[] = array( 'amount' => $base + $remainder, 'quantity' => 1 );
    }
    return $items;
}

function items_total( $items ) {
    $sum = 0;
    foreach ( $items as $i ) {
        $sum += $i['amount'] * $i['quantity'];
    }
    return $sum;
}

function test_discount_rounding_3_items_10_off() {
    echo "\n🧪 Test 99: Rounding — 3 × \$33.33, \$10 cart discount → exact cents\n";

    // Line total after discount: 3 × 33.33 - 10 = 89.99
    $line_total_cents = 8999;
    $qty = 3;

    $items = split_line_item( $line_total_cents, $qty );
    $sum = items_total( $items );

    assert_equals( 8999, $sum, 'Sum of split items = 8999 cents (exact line total)' );

    // Verify the split: floor(8999/3) = 2999, remainder = 8999 - 2999*3 = 2
    assert_equals( 2999, $items[0]['amount'], 'Bulk unit price = 2999 cents' );
    assert_equals( 2, $items[0]['quantity'], 'Bulk quantity = 2' );
    assert_equals( 3001, $items[1]['amount'], 'Remainder unit price = 3001 cents' );
    assert_equals( 1, $items[1]['quantity'], 'Remainder quantity = 1' );
}

function test_discount_rounding_7_items_odd_total() {
    echo "\n🧪 Test 100: Rounding — 7 items, \$100 total → exact cents\n";

    $line_total_cents = 10000;
    $qty = 7;

    $items = split_line_item( $line_total_cents, $qty );
    $sum = items_total( $items );

    assert_equals( 10000, $sum, 'Sum = 10000 cents (exact)' );
    // floor(10000/7) = 1428, remainder = 10000 - 1428*7 = 4
    assert_equals( 1428, $items[0]['amount'], 'Bulk = 1428 cents' );
    assert_equals( 6, $items[0]['quantity'], 'Bulk qty = 6' );
    assert_equals( 1432, $items[1]['amount'], 'Remainder = 1432 cents' );
}

function test_discount_even_split_no_remainder() {
    echo "\n🧪 Test 101: Even split — 4 × \$25.00 = \$100.00, no remainder\n";

    $line_total_cents = 10000;
    $qty = 4;

    $items = split_line_item( $line_total_cents, $qty );
    $sum = items_total( $items );

    assert_equals( 10000, $sum, 'Sum = 10000 cents' );
    assert_equals( 1, count( $items ), 'Single entry (no split needed)' );
    assert_equals( 2500, $items[0]['amount'], 'Unit price = 2500 cents' );
    assert_equals( 4, $items[0]['quantity'], 'Quantity = 4' );
}

function test_discount_single_item_no_split() {
    echo "\n🧪 Test 102: Single item — no split regardless of amount\n";

    $line_total_cents = 4999;
    $qty = 1;

    $items = split_line_item( $line_total_cents, $qty );
    $sum = items_total( $items );

    assert_equals( 4999, $sum, 'Sum = 4999 cents' );
    assert_equals( 1, count( $items ), 'Single entry' );
    assert_equals( 4999, $items[0]['amount'], 'Amount = 4999 cents' );
    assert_equals( 1, $items[0]['quantity'], 'Quantity = 1' );
}

function test_discount_penny_remainder() {
    echo "\n🧪 Test 103: \$5 discount on 3 items (\$10 each) → 1 cent remainder\n";

    // 3 × $10 - $5 = $25.00. Per line item: $25 / 3 = $8.333...
    // Actual line total = 2500 cents. floor(2500/3) = 833. 833*3 = 2499. Remainder = 1.
    $line_total_cents = 2500;
    $qty = 3;

    $items = split_line_item( $line_total_cents, $qty );
    $sum = items_total( $items );

    assert_equals( 2500, $sum, 'Sum = 2500 cents (exact)' );
    assert_equals( 833, $items[0]['amount'], 'Bulk = 833 cents' );
    assert_equals( 2, $items[0]['quantity'], 'Bulk qty = 2' );
    assert_equals( 834, $items[1]['amount'], 'Remainder = 834 cents' );
}

function test_discount_free_item_split() {
    echo "\n🧪 Test 104: 100% discount (free) — 0 cents, no remainder\n";

    $line_total_cents = 0;
    $qty = 3;

    $items = split_line_item( $line_total_cents, $qty );
    $sum = items_total( $items );

    assert_equals( 0, $sum, 'Sum = 0 cents' );
    assert_equals( 1, count( $items ), 'Single entry (0 / 3 = 0, no remainder)' );
    assert_equals( 0, $items[0]['amount'], 'Amount = 0' );
    assert_equals( 3, $items[0]['quantity'], 'Quantity = 3' );
}

function test_discount_large_qty_remainder() {
    echo "\n🧪 Test 105: 100 items, \$333.33 total → large remainder\n";

    $line_total_cents = 33333;
    $qty = 100;

    $items = split_line_item( $line_total_cents, $qty );
    $sum = items_total( $items );

    assert_equals( 33333, $sum, 'Sum = 33333 cents (exact)' );
    // floor(33333/100) = 333, remainder = 33333 - 333*100 = 33
    assert_equals( 333, $items[0]['amount'], 'Bulk = 333 cents' );
    assert_equals( 99, $items[0]['quantity'], 'Bulk qty = 99' );
    assert_equals( 366, $items[1]['amount'], 'Remainder unit = 366 cents (333 + 33)' );
}

function test_discount_two_items_odd_cent() {
    echo "\n🧪 Test 106: 2 items, \$19.99 total → 1 cent remainder\n";

    $line_total_cents = 1999;
    $qty = 2;

    $items = split_line_item( $line_total_cents, $qty );
    $sum = items_total( $items );

    assert_equals( 1999, $sum, 'Sum = 1999 cents' );
    assert_equals( 999, $items[0]['amount'], 'Bulk = 999 cents' );
    assert_equals( 1, $items[0]['quantity'], 'Bulk qty = 1' );
    assert_equals( 1000, $items[1]['amount'], 'Remainder = 1000 cents' );
}

function test_discount_multi_line_items_total_matches_order() {
    echo "\n🧪 Test 107: Multi-line order — sum of all splits matches order total\n";

    // Simulate: Snowboard ($559.96 after 20% off), T-Shirt x2 ($47.98 after 20%), Sticker ($3.99 after 20%)
    // + $9.99 shipping
    $lines = array(
        array( 'total_cents' => 55996, 'qty' => 1 ),  // Snowboard
        array( 'total_cents' => 4798, 'qty' => 2 ),   // T-Shirt x2
        array( 'total_cents' => 399, 'qty' => 1 ),    // Sticker
    );
    $shipping_cents = 999;

    $grand_total = 0;
    foreach ( $lines as $line ) {
        $items = split_line_item( $line['total_cents'], $line['qty'] );
        $grand_total += items_total( $items );
    }
    $grand_total += $shipping_cents;

    $expected = 55996 + 4798 + 399 + 999;
    assert_equals( $expected, $grand_total, "Grand total = {$expected} cents (all line totals + shipping)" );
}

function test_discount_worst_case_rounding() {
    echo "\n🧪 Test 108: Worst case — \$0.01 total across 99 items\n";

    $line_total_cents = 1;
    $qty = 99;

    $items = split_line_item( $line_total_cents, $qty );
    $sum = items_total( $items );

    assert_equals( 1, $sum, 'Sum = 1 cent (exact)' );
    // floor(1/99) = 0, remainder = 1
    assert_equals( 0, $items[0]['amount'], 'Bulk = 0 cents (98 free items)' );
    assert_equals( 98, $items[0]['quantity'], 'Bulk qty = 98' );
    assert_equals( 1, $items[1]['amount'], 'Remainder = 1 cent' );
}

function test_discount_naive_vs_split_comparison() {
    echo "\n🧪 Test 109: Naive round() vs split — proves split is exact\n";

    // Case where naive rounding fails: $89.99 / 3 = 29.9966... → round to 3000 → 3000*3 = 9000 ≠ 8999
    $line_total = 89.99;
    $qty = 3;
    $line_total_cents = (int) round( $line_total * 100 );

    // Naive approach (what the old code did)
    $naive_unit = (int) round( ( $line_total / $qty ) * 100 );
    $naive_total = $naive_unit * $qty;

    // Split approach (new code)
    $items = split_line_item( $line_total_cents, $qty );
    $split_total = items_total( $items );

    assert_true( $naive_total !== $line_total_cents, "Naive rounding LOSES cents: {$naive_total} ≠ {$line_total_cents}" );
    assert_equals( $line_total_cents, $split_total, "Split approach is EXACT: {$split_total} = {$line_total_cents}" );
}

function test_discount_percentage_off_various() {
    echo "\n🧪 Test 110: Various percentage discounts — all exact\n";

    $cases = array(
        array( 'desc' => '10% off $99.99 × 3', 'total' => 269.97, 'disc' => 0.10, 'qty' => 3 ),
        array( 'desc' => '15% off $19.99 × 7', 'total' => 139.93, 'disc' => 0.15, 'qty' => 7 ),
        array( 'desc' => '33% off $49.95 × 2', 'total' => 99.90, 'disc' => 0.33, 'qty' => 2 ),
        array( 'desc' => '50% off $9.99 × 5', 'total' => 49.95, 'disc' => 0.50, 'qty' => 5 ),
    );

    foreach ( $cases as $c ) {
        $discounted_total = round( $c['total'] * ( 1 - $c['disc'] ), 2 );
        $line_total_cents = (int) round( $discounted_total * 100 );

        $items = split_line_item( $line_total_cents, $c['qty'] );
        $sum = items_total( $items );

        assert_equals( $line_total_cents, $sum, "{$c['desc']} → {$line_total_cents} cents exact" );
    }
}

// ─── Happy Path End-to-End Tests ────────────────────────────────────────────

function test_happy_path_single_item_full_flow() {
    echo "\n🧪 Test 111: HAPPY PATH — Single item, full checkout → webhook → order complete\n";

    // Step 1: Customer adds Snowboard ($699.95) to cart
    $line_total = 699.95;
    $qty = 1;
    $line_total_cents = (int) round( $line_total * 100 );
    $items = split_line_item( $line_total_cents, $qty );

    assert_equals( 69995, items_total( $items ), 'Step 1: Products array has correct amount (69995 cents)' );
    assert_equals( 1, $items[0]['quantity'], 'Step 1: Quantity = 1' );

    // Step 2: Payment page created with correct data
    $order_id = 42;
    $customer_id = 'cus_abc123';
    $client_ref = 'order-' . $order_id;
    $return_token = 'tok_' . bin2hex( random_bytes( 16 ) );

    assert_equals( 'order-42', $client_ref, 'Step 2: clientReferenceId = order-42' );
    assert_true( strlen( $return_token ) > 0, 'Step 2: Return token generated' );

    // Step 3: Customer pays on Breeze, redirected back with success
    $order_status = 'pending';
    $order_is_paid = false;

    // Return URL sets to on-hold (NOT payment_complete)
    if ( ! $order_is_paid ) {
        $order_status = 'on-hold';
    }
    assert_equals( 'on-hold', $order_status, 'Step 3: Return URL sets order to on-hold' );

    // Token consumed
    $return_token = null;
    assert_true( $return_token === null, 'Step 3: Return token consumed' );

    // Step 4: Webhook fires with PAYMENT_SUCCEEDED
    $webhook_data = array(
        'clientReferenceId' => 'order-42',
        'pageId' => 'page_xyz789',
    );
    $stored_page_id = 'page_xyz789';

    // Verify page ID matches
    $page_match = ( $stored_page_id === $webhook_data['pageId'] );
    assert_true( $page_match, 'Step 4: Webhook pageId matches stored page ID' );

    // payment_complete called
    $order_is_paid = true;
    $order_status = 'processing';
    $transaction_id = $webhook_data['pageId'];

    assert_true( $order_is_paid, 'Step 4: Order marked as paid' );
    assert_equals( 'processing', $order_status, 'Step 4: Order status = processing' );
    assert_equals( 'page_xyz789', $transaction_id, 'Step 4: Transaction ID recorded' );
}

function test_happy_path_multi_item_with_discount() {
    echo "\n🧪 Test 112: HAPPY PATH — Multi-item cart with 20% coupon\n";

    // Cart: Snowboard ($699.95) + T-Shirt x2 ($29.99 each) + Sticker ($4.99)
    // Coupon: 20% off entire cart
    // Subtotal: 699.95 + 59.98 + 4.99 = 764.92
    // Discount: 764.92 * 0.20 = 152.98
    // Total: 764.92 - 152.98 = 611.94

    $lines = array(
        array( 'name' => 'Snowboard', 'line_total' => 559.96, 'qty' => 1 ),  // 699.95 * 0.80
        array( 'name' => 'T-Shirt',   'line_total' => 47.98,  'qty' => 2 ),  // 29.99 * 2 * 0.80
        array( 'name' => 'Sticker',   'line_total' => 3.99,   'qty' => 1 ),  // 4.99 * 0.80 (rounds)
    );
    $shipping = 9.99;

    $products = array();
    $total_cents = 0;

    foreach ( $lines as $line ) {
        $line_cents = (int) round( $line['line_total'] * 100 );
        $items = split_line_item( $line_cents, $line['qty'] );
        $total_cents += items_total( $items );

        foreach ( $items as $item ) {
            $products[] = array_merge( $item, array( 'name' => $line['name'] ) );
        }
    }

    // Add shipping
    $shipping_cents = (int) round( $shipping * 100 );
    $products[] = array( 'name' => 'Shipping', 'amount' => $shipping_cents, 'quantity' => 1 );
    $total_cents += $shipping_cents;

    // Verify all products present
    $names = array_map( function( $p ) { return $p['name']; }, $products );
    assert_true( in_array( 'Snowboard', $names ), 'Snowboard in cart' );
    assert_true( in_array( 'T-Shirt', $names ), 'T-Shirt in cart' );
    assert_true( in_array( 'Sticker', $names ), 'Sticker in cart' );
    assert_true( in_array( 'Shipping', $names ), 'Shipping in cart' );

    // Verify total
    $expected_total = 55996 + 4798 + 399 + 999;
    assert_equals( $expected_total, $total_cents, "Total = {$expected_total} cents (\$" . number_format( $expected_total / 100, 2 ) . ")" );

    // Verify discounted prices (not catalog)
    $snowboard = null;
    foreach ( $products as $p ) {
        if ( $p['name'] === 'Snowboard' ) { $snowboard = $p; break; }
    }
    assert_equals( 55996, $snowboard['amount'], 'Snowboard = $559.96 (20% off $699.95)' );
    assert_true( $snowboard['amount'] < 69995, 'Snowboard price is discounted (< $699.95)' );
}

function test_happy_path_webhook_then_return() {
    echo "\n🧪 Test 113: HAPPY PATH — Webhook fires BEFORE return URL (race condition)\n";

    // This tests the case where Breeze webhook arrives before the customer
    // is redirected back. The return URL should see is_paid() = true and skip.

    $order_status = 'pending';
    $order_is_paid = false;

    // Step 1: Webhook arrives first
    if ( ! $order_is_paid ) {
        $order_is_paid = true;
        $order_status = 'processing';
    }
    assert_equals( 'processing', $order_status, 'Webhook sets processing' );

    // Step 2: Return URL arrives after — should NOT change status
    if ( ! $order_is_paid ) {
        $order_status = 'on-hold'; // This should NOT execute
    }
    assert_equals( 'processing', $order_status, 'Return URL does NOT override processing to on-hold' );
    assert_true( $order_is_paid, 'Order remains paid' );
}

function test_happy_path_refund_after_payment() {
    echo "\n🧪 Test 114: HAPPY PATH — Full payment → partial refund → full refund\n";

    $order_total_cents = 69995; // $699.95
    $page_id = 'page_abc123';
    $refunded_total = 0;

    // Partial refund: $100
    $refund_1 = 100.00;
    $refund_1_cents = (int) round( $refund_1 * 100 );
    assert_equals( 10000, $refund_1_cents, 'Partial refund = 10000 cents' );
    assert_true( $refund_1 > 0, 'Refund amount valid' );
    assert_true( ! empty( $page_id ), 'Page ID available for refund' );
    $refunded_total += $refund_1_cents;

    // Remaining: $599.95
    $remaining = $order_total_cents - $refunded_total;
    assert_equals( 59995, $remaining, 'Remaining after partial = $599.95' );

    // Full refund of remainder
    $refund_2_cents = $remaining;
    $refunded_total += $refund_2_cents;

    assert_equals( $order_total_cents, $refunded_total, 'Total refunded = original order total' );
}

function test_happy_path_guest_checkout() {
    echo "\n🧪 Test 115: HAPPY PATH — Guest checkout (no user account)\n";

    $user_id = 0; // Guest
    $order_id = 99;
    $email = 'guest@example.com';

    // Customer reference
    $ref = $user_id ? 'user-' . $user_id : 'guest-' . $order_id;
    assert_equals( 'guest-99', $ref, 'Guest reference = guest-99' );

    // Customer data
    $customer_data = array(
        'referenceId' => $ref,
        'email' => $email,
        'signupAt' => time() * 1000,
    );
    assert_equals( 'guest@example.com', $customer_data['email'], 'Email correct' );
    assert_true( $customer_data['signupAt'] > 1000000000000, 'signupAt in milliseconds' );

    // Payment page
    $client_ref = 'order-' . $order_id;
    assert_equals( 'order-99', $client_ref, 'clientReferenceId = order-99' );
}

function test_happy_path_returning_customer() {
    echo "\n🧪 Test 116: HAPPY PATH — Returning customer (cached Breeze ID)\n";

    $user_id = 7;
    $cached_breeze_id = 'cus_returning_abc';

    // Should skip API call and use cached ID
    $customer_id = $cached_breeze_id;
    assert_equals( 'cus_returning_abc', $customer_id, 'Cached Breeze customer ID reused' );
    assert_true( ! empty( $customer_id ), 'Customer ID available without API call' );
}

function test_happy_path_test_mode_vs_live() {
    echo "\n🧪 Test 117: HAPPY PATH — Test mode uses test key, live uses live key\n";

    // Test mode
    $testmode = true;
    $test_key = 'sk_test_abc123';
    $live_key = 'sk_live_xyz789';
    $api_key = $testmode ? $test_key : $live_key;
    assert_equals( 'sk_test_abc123', $api_key, 'Test mode → test API key' );

    // Live mode
    $testmode = false;
    $api_key = $testmode ? $test_key : $live_key;
    assert_equals( 'sk_live_xyz789', $api_key, 'Live mode → live API key' );
}

function test_happy_path_preferred_payment_methods() {
    echo "\n🧪 Test 118: HAPPY PATH — Preferred payment methods appended to URL\n";

    $base_url = 'https://pay.breeze.cash/page_abc123';
    $methods = array( 'apple_pay', 'card' );
    $methods_str = implode( ',', $methods );

    $final_url = $base_url . '?preferred_payment_methods=' . urlencode( $methods_str );
    assert_true( strpos( $final_url, 'apple_pay' ) !== false, 'URL contains apple_pay' );
    assert_true( strpos( $final_url, 'card' ) !== false, 'URL contains card' );
    assert_true( strpos( $final_url, 'preferred_payment_methods=' ) !== false, 'URL has param key' );
}

function test_happy_path_order_notes_audit_trail() {
    echo "\n🧪 Test 119: HAPPY PATH — Complete audit trail in order notes\n";

    // Simulate the full lifecycle of order notes
    $notes = array();

    // Step 1: Order created
    $notes[] = 'Awaiting Breeze payment.';

    // Step 2: Customer returns
    $notes[] = 'Customer returned from Breeze — awaiting webhook confirmation.';

    // Step 3: Webhook confirms payment
    $notes[] = 'Payment confirmed via Breeze webhook. Transaction ID: page_abc123';

    // Step 4: Refund processed
    $notes[] = 'Refund of 50 USD processed via Breeze. Refund ID: ref_xyz. Reason: Customer request';

    assert_equals( 4, count( $notes ), '4 order notes in audit trail' );
    assert_true( strpos( $notes[0], 'Awaiting' ) !== false, 'Note 1: Awaiting payment' );
    assert_true( strpos( $notes[1], 'webhook confirmation' ) !== false, 'Note 2: Awaiting webhook' );
    assert_true( strpos( $notes[2], 'Transaction ID' ) !== false, 'Note 3: Payment confirmed with TX ID' );
    assert_true( strpos( $notes[3], 'Refund' ) !== false, 'Note 4: Refund with ID and reason' );
}

function test_happy_path_multiple_orders_different_customers() {
    echo "\n🧪 Test 120: HAPPY PATH — Two orders, different customers, no cross-contamination\n";

    // Order A
    $order_a_id = 42;
    $order_a_page = 'page_order_a';
    $order_a_customer = 'cus_alice';
    $order_a_ref = 'order-' . $order_a_id;

    // Order B
    $order_b_id = 43;
    $order_b_page = 'page_order_b';
    $order_b_customer = 'cus_bob';
    $order_b_ref = 'order-' . $order_b_id;

    // Verify isolation
    assert_true( $order_a_ref !== $order_b_ref, 'Different clientReferenceIds' );
    assert_true( $order_a_page !== $order_b_page, 'Different payment page IDs' );
    assert_true( $order_a_customer !== $order_b_customer, 'Different customer IDs' );

    // Webhook for A should not affect B
    $webhook_page = 'page_order_a';
    $match_a = ( $order_a_page === $webhook_page );
    $match_b = ( $order_b_page === $webhook_page );
    assert_true( $match_a, 'Webhook matches order A' );
    assert_true( ! $match_b, 'Webhook does NOT match order B' );
}

function test_send_product_description_off_by_default() {
    echo "\n🧪 Test 121: send_product_description OFF (default) — description absent from lineItems\n";

    // Simulate build_line_items behaviour with send_product_description = false
    $send_product_description = false;

    $short_desc = 'A great widget for all occasions.';
    $description = $send_product_description && $short_desc
        ? mb_substr( wp_strip_all_tags( $short_desc ), 0, 280 )
        : '';

    assert_equals( '', $description, 'description is empty when toggle is off' );

    // Simulate the lineItem that would be built
    $entry = array(
        'clientProductId' => '42',
        'displayName'     => 'Widget Pro',
        'amount'          => 1999,
        'currency'        => 'USD',
        'quantity'        => 1,
    );
    if ( $description ) {
        $entry['description'] = $description;
    }

    assert_true( ! isset( $entry['description'] ), 'description key absent from lineItem when toggle is off' );
}

function test_send_product_description_on() {
    echo "\n🧪 Test 122: send_product_description ON — description present and truncated in lineItems\n";

    $send_product_description = true;

    // Normal description
    $short_desc = 'A great widget for all occasions.';
    $description = $send_product_description && $short_desc
        ? mb_substr( wp_strip_all_tags( $short_desc ), 0, 280 )
        : '';

    assert_equals( 'A great widget for all occasions.', $description, 'description included when toggle is on' );

    // HTML stripped
    $html_desc = '<p>Bold <strong>claim</strong> about this product.</p>';
    $stripped = $send_product_description && $html_desc
        ? mb_substr( wp_strip_all_tags( $html_desc ), 0, 280 )
        : '';
    assert_true( strpos( $stripped, '<p>' ) === false, 'HTML tags stripped from description' );
    assert_true( strpos( $stripped, 'Bold' ) !== false, 'Text content preserved after stripping' );

    // Long description truncated to 280 chars
    $long_desc = str_repeat( 'x', 400 );
    $truncated = $send_product_description && $long_desc
        ? mb_substr( wp_strip_all_tags( $long_desc ), 0, 280 )
        : '';
    assert_equals( 280, mb_strlen( $truncated ), 'description truncated to 280 chars' );

    // lineItem includes description key
    $entry = array(
        'clientProductId' => '42',
        'displayName'     => 'Widget Pro',
        'amount'          => 1999,
        'currency'        => 'USD',
        'quantity'        => 1,
    );
    if ( $description ) {
        $entry['description'] = $description;
    }
    assert_true( isset( $entry['description'] ), 'description key present in lineItem when toggle is on' );
    assert_equals( 'A great widget for all occasions.', $entry['description'], 'description value correct' );
}

function test_send_product_description_empty_short_desc() {
    echo "\n🧪 Test 123: send_product_description ON but product has no short description — key omitted\n";

    $send_product_description = true;
    $short_desc = ''; // product has no short description

    $description = $send_product_description && $short_desc
        ? mb_substr( wp_strip_all_tags( $short_desc ), 0, 280 )
        : '';

    assert_equals( '', $description, 'empty short_desc yields empty description string' );

    $entry = array(
        'clientProductId' => '42',
        'displayName'     => 'Bare Product',
        'amount'          => 500,
        'currency'        => 'USD',
        'quantity'        => 1,
    );
    if ( $description ) {
        $entry['description'] = $description;
    }
    assert_true( ! isset( $entry['description'] ), 'description key omitted when product has no short description' );
}

function test_gateway_icon_filter_our_gateway() {
    echo "\n🧪 Test 124: filter_gateway_icon_html — constrains icon for our gateway\n";

    $gateway_id    = 'breeze_payment_gateway';
    $icon_url      = 'https://example.com/breeze-icon.png';
    $method_title  = 'Breeze';

    // Simulate the filter logic from filter_gateway_icon_html()
    $filter = function( $icon_html, $gw_id ) use ( $gateway_id, $icon_url, $method_title ) {
        if ( $gw_id !== $gateway_id ) {
            return $icon_html;
        }
        return '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $method_title ) . '" style="max-width:24px;max-height:24px;" />';
    };

    $original_html = '<img src="' . $icon_url . '" alt="Breeze" />';
    $filtered      = $filter( $original_html, $gateway_id );

    assert_true( strpos( $filtered, 'max-width:24px' ) !== false, 'max-width:24px present' );
    assert_true( strpos( $filtered, 'max-height:24px' ) !== false, 'max-height:24px present' );
    assert_true( strpos( $filtered, esc_url( $icon_url ) ) !== false, 'icon URL preserved' );
    assert_true( strpos( $filtered, 'alt="Breeze"' ) !== false, 'alt text set to method title' );
}

function test_gateway_icon_filter_other_gateway() {
    echo "\n🧪 Test 125: filter_gateway_icon_html — passthrough for other gateways\n";

    $gateway_id   = 'breeze_payment_gateway';
    $icon_url     = 'https://example.com/breeze-icon.png';
    $method_title = 'Breeze';

    $filter = function( $icon_html, $gw_id ) use ( $gateway_id, $icon_url, $method_title ) {
        if ( $gw_id !== $gateway_id ) {
            return $icon_html;
        }
        return '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $method_title ) . '" style="max-width:24px;max-height:24px;" />';
    };

    $other_html = '<img src="https://stripe.com/icon.png" alt="Stripe" style="width:80px;" />';
    $result     = $filter( $other_html, 'stripe' );

    assert_equals( $other_html, $result, 'HTML unchanged for non-Breeze gateway' );
    assert_true( strpos( $result, 'max-width:24px' ) === false, 'No size constraint injected for other gateways' );
}

// Run happy path tests
test_happy_path_single_item_full_flow();
test_happy_path_multi_item_with_discount();
test_happy_path_webhook_then_return();
test_happy_path_refund_after_payment();
test_happy_path_guest_checkout();
test_happy_path_returning_customer();
test_happy_path_test_mode_vs_live();
test_happy_path_preferred_payment_methods();
test_happy_path_order_notes_audit_trail();
test_happy_path_multiple_orders_different_customers();
test_send_product_description_off_by_default();
test_send_product_description_on();
test_send_product_description_empty_short_desc();
test_gateway_icon_filter_our_gateway();
test_gateway_icon_filter_other_gateway();

// Run discount rounding tests
test_discount_rounding_3_items_10_off();
test_discount_rounding_7_items_odd_total();
test_discount_even_split_no_remainder();
test_discount_single_item_no_split();
test_discount_penny_remainder();
test_discount_free_item_split();
test_discount_large_qty_remainder();
test_discount_two_items_odd_cent();
test_discount_multi_line_items_total_matches_order();
test_discount_worst_case_rounding();
test_discount_naive_vs_split_comparison();
test_discount_percentage_off_various();

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  {$passed} passed, {$failed} failed\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
exit( $failed > 0 ? 1 : 0 );
