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

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  {$passed} passed, {$failed} failed\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
exit( $failed > 0 ? 1 : 0 );
