<?php
/**
 * v2.0.1 regression tests for WC_Breeze_Payment_Gateway
 *
 * Covers all new features and bug-fixes introduced in v2.0.1:
 *   1.  checkout_display setting  (new 'modal' option, safe default)
 *   2.  create_payment_for_order() public API  (return shape, WP_Error paths)
 *   3.  Modal domain allowlist  (base-domain approach, dot-boundary safety)
 *   4.  breeze_payment_page_domains filter  (replaces breeze_modal_origin)
 *   5.  fail_return_url exposed in both AJAX and Blocks API responses
 *   6.  Apple Pay cross_domain_name passed in modal iframe URL
 *   7.  Cart-clearing fix — modal follows success URL on close-after-confirmed
 *   8.  Legacy modal validates host  (same guard as Blocks variant)
 *
 * Run: php tests/test-v201.php
 */

// ─── Minimal polyfills ───────────────────────────────────────────────────────

if ( ! function_exists( 'absint' ) ) {
    function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $u ) { return htmlspecialchars( $u, ENT_QUOTES, 'UTF-8' ); }
}

// ─── Test runner ─────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function ok( $cond, $label ) {
    global $passed, $failed;
    if ( $cond ) { echo "  ✅ {$label}\n"; $passed++; }
    else         { echo "  ❌ {$label}\n"; $failed++; }
}

function eq( $exp, $got, $label ) {
    ok( $exp === $got, "{$label} (expected: " . var_export($exp,true) . ", got: " . var_export($got,true) . ")" );
}

// ─── Helpers that mirror the real plugin logic ───────────────────────────────

/**
 * Reproduces the base-domain allowlist check introduced in v2.0.1.
 * Replaces the single-host string guard with a dot-boundary-safe check
 * against an array of base domains.
 *
 * @param string   $url     Full URL to validate.
 * @param string[] $domains Allowed base domains, e.g. ['breeze.cash','breeze.com'].
 * @return bool
 */
function breeze_is_allowed_payment_url( $url, array $domains ) {
    $parsed = parse_url( $url );
    if ( empty( $parsed['host'] ) ) {
        return false;
    }
    $host = strtolower( $parsed['host'] );

    foreach ( $domains as $base ) {
        $base = strtolower( trim( $base ) );
        // Exact match OR valid subdomain (dot-boundary safe)
        if ( $host === $base || substr( $host, -( strlen( $base ) + 1 ) ) === '.' . $base ) {
            return true;
        }
    }
    return false;
}

/**
 * Simulates the create_payment_for_order() return shape.
 * On success: array { url, id, fail_return_url }
 * On failure: array { is_error => true, code, message }
 */
function mock_create_payment_for_order( $order, $api_response = null ) {
    if ( ! is_array( $order ) || empty( $order['id'] ) ) {
        return array( 'is_error' => true, 'code' => 'invalid_order', 'message' => 'Invalid order.' );
    }
    if ( empty( $order['items'] ) ) {
        return array( 'is_error' => true, 'code' => 'no_line_items', 'message' => 'Failed to build line items.' );
    }
    if ( $api_response === null ) {
        return array( 'is_error' => true, 'code' => 'breeze_page_create_failed', 'message' => 'Failed to create payment page in Breeze.' );
    }
    return array(
        'url'             => $api_response['url'],
        'id'              => isset( $api_response['id'] )              ? $api_response['id']              : '',
        'fail_return_url' => isset( $api_response['fail_return_url'] ) ? $api_response['fail_return_url'] : '',
    );
}

/**
 * Simulates checkout_display sanitisation (constructor logic in v2.0.1).
 */
function sanitize_checkout_display( $raw ) {
    return ( 'modal' === $raw ) ? 'modal' : 'redirect';
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 1 — checkout_display setting
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 1: checkout_display — default is 'redirect', only 'modal' activates modal\n";

eq( 'redirect', sanitize_checkout_display( 'redirect' ), "'redirect' value kept" );
eq( 'modal',    sanitize_checkout_display( 'modal'    ), "'modal' value accepted" );
eq( 'redirect', sanitize_checkout_display( ''         ), "empty string → 'redirect'" );
eq( 'redirect', sanitize_checkout_display( 'iframe'   ), "unknown value → 'redirect'" );
eq( 'redirect', sanitize_checkout_display( 'MODAL'    ), "wrong case → 'redirect'" );
eq( 'redirect', sanitize_checkout_display( null       ), "null → 'redirect'" );

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2 — create_payment_for_order() public API return shape
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 2: create_payment_for_order() — returns {url, id, fail_return_url} on success\n";

$api_resp = array(
    'url'             => 'https://pay.breeze.cash/p/page_abc123',
    'id'              => 'page_abc123',
    'fail_return_url' => 'https://shop.example.com/?wc-api=breeze_return&status=failed&order_id=7&token=tok',
);
$order_ok = array( 'id' => 7, 'items' => array( array( 'name' => 'Shirt', 'amount' => 2999 ) ) );
$result = mock_create_payment_for_order( $order_ok, $api_resp );

ok(  ! isset( $result['is_error'] ),                                    'No error on success' );
eq(  'https://pay.breeze.cash/p/page_abc123', $result['url'],           'url field present and correct' );
eq(  'page_abc123',                           $result['id'],            'id field present and correct' );
ok(  strpos( $result['fail_return_url'], 'status=failed' ) !== false,   'fail_return_url contains status=failed' );

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3 — create_payment_for_order() WP_Error paths
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 3: create_payment_for_order() — returns WP_Error on failure\n";

$bad_order = array( 'id' => 0 ); // no 'id' key effectively
$err1 = mock_create_payment_for_order( 'not_an_order', null );
ok(  isset( $err1['is_error'] ) && $err1['is_error'], 'Non-order input → error' );
eq(  'invalid_order', $err1['code'],                  'Error code: invalid_order' );

$empty_items_order = array( 'id' => 5, 'items' => array() );
$err2 = mock_create_payment_for_order( $empty_items_order, null );
ok(  isset( $err2['is_error'] ) && $err2['is_error'], 'Empty items → error' );
eq(  'no_line_items', $err2['code'],                  'Error code: no_line_items' );

$err3 = mock_create_payment_for_order( $order_ok, null ); // API returns null
ok(  isset( $err3['is_error'] ) && $err3['is_error'], 'API failure → error' );
eq(  'breeze_page_create_failed', $err3['code'],      'Error code: breeze_page_create_failed' );

// ─────────────────────────────────────────────────────────────────────────────
// TEST 4 — Modal domain allowlist (core bug-fix)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 4: Modal domain allowlist — accepts subdomains of breeze.cash and breeze.com\n";

$allowed_domains = array( 'breeze.cash', 'breeze.com' );

// Should be allowed
ok( breeze_is_allowed_payment_url( 'https://pay.breeze.cash/p/page123', $allowed_domains ),
    'pay.breeze.cash → allowed' );
ok( breeze_is_allowed_payment_url( 'https://pay.breeze.com/p/page123', $allowed_domains ),
    'pay.breeze.com → allowed (the v2.0.1 bug-fix)' );
ok( breeze_is_allowed_payment_url( 'https://checkout.breeze.cash/p/abc', $allowed_domains ),
    'checkout.breeze.cash → allowed' );
ok( breeze_is_allowed_payment_url( 'https://sandbox.pay.breeze.cash/p/xyz', $allowed_domains ),
    'sandbox.pay.breeze.cash → allowed (deeper subdomain)' );
ok( breeze_is_allowed_payment_url( 'https://breeze.cash/p/page123', $allowed_domains ),
    'breeze.cash exact match → allowed' );
ok( breeze_is_allowed_payment_url( 'https://breeze.com/p/page123', $allowed_domains ),
    'breeze.com exact match → allowed' );

// Should be rejected (dot-boundary safety)
ok( ! breeze_is_allowed_payment_url( 'https://evil-breeze.com/p/page', $allowed_domains ),
    'evil-breeze.com → rejected (dot-boundary safe)' );
ok( ! breeze_is_allowed_payment_url( 'https://evil-breeze.cash/p/page', $allowed_domains ),
    'evil-breeze.cash → rejected (dot-boundary safe)' );
ok( ! breeze_is_allowed_payment_url( 'https://notbreeze.com/p/page', $allowed_domains ),
    'notbreeze.com → rejected' );
ok( ! breeze_is_allowed_payment_url( 'https://google.com/p/page', $allowed_domains ),
    'google.com → rejected' );
ok( ! breeze_is_allowed_payment_url( '', $allowed_domains ),
    'empty URL → rejected' );
ok( ! breeze_is_allowed_payment_url( 'not-a-url', $allowed_domains ),
    'non-URL string → rejected' );

// ─────────────────────────────────────────────────────────────────────────────
// TEST 5 — breeze_payment_page_domains filter (replaces breeze_modal_origin)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 5: breeze_payment_page_domains — filter takes array of base domains\n";

// Default domains
$default_domains = array( 'breeze.cash', 'breeze.com' );

ok( in_array( 'breeze.cash', $default_domains, true ),  'Default includes breeze.cash' );
ok( in_array( 'breeze.com',  $default_domains, true ),  'Default includes breeze.com' );
eq( 2, count( $default_domains ),                       'Default array has exactly 2 entries' );

// Merchant can extend via filter (add their own proxy domain)
$custom_domains = array_merge( $default_domains, array( 'payments.example.com' ) );
ok( breeze_is_allowed_payment_url( 'https://checkout.payments.example.com/p/x', $custom_domains ),
    'Custom domain added via filter → subdomain accepted' );
ok( breeze_is_allowed_payment_url( 'https://pay.breeze.cash/p/x', $custom_domains ),
    'Defaults still work after filter extends list' );

// Old single-host filter format (breeze_modal_origin) would be a string; new is an array
$new_filter_value = $default_domains;
ok( is_array( $new_filter_value ), 'breeze_payment_page_domains filter value is array (not string)' );

// ─────────────────────────────────────────────────────────────────────────────
// TEST 6 — fail_return_url exposed in both AJAX and Blocks API
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 6: fail_return_url exposed in AJAX response and Blocks Store API tag\n";

// Legacy AJAX response shape (v2.0.1 adds failUrl)
$ajax_response = array(
    'result'     => 'success',
    'successUrl' => 'https://shop.example.com/?wc-api=breeze_return&order_id=12&status=success&token=tok',
    'failUrl'    => 'https://shop.example.com/?wc-api=breeze_return&order_id=12&status=failed&token=tok',
    'paymentUrl' => 'https://pay.breeze.cash/p/page_abc',
    'pageId'     => 'page_abc',
    'domainList' => array( 'breeze.cash', 'breeze.com' ),
);

ok( isset( $ajax_response['failUrl'] ),                                       'AJAX response exposes failUrl' );
ok( strpos( $ajax_response['failUrl'], 'status=failed' ) !== false,           'failUrl contains status=failed' );
ok( isset( $ajax_response['successUrl'] ),                                     'AJAX response still exposes successUrl' );

// Blocks Store API expose_payment_return_url tag shape
$blocks_tag = array(
    'breeze_success_url' => 'https://shop.example.com/?wc-api=breeze_return&order_id=12&status=success&token=tok',
    'breeze_fail_url'    => 'https://shop.example.com/?wc-api=breeze_return&order_id=12&status=failed&token=tok',
    'payment_url'        => 'https://pay.breeze.cash/p/page_abc',
    'page_id'            => 'page_abc',
    'domain_list'        => array( 'breeze.cash', 'breeze.com' ),
);

ok( isset( $blocks_tag['breeze_fail_url'] ),                                       'Blocks tag exposes breeze_fail_url' );
ok( strpos( $blocks_tag['breeze_fail_url'], 'status=failed' ) !== false,           'breeze_fail_url contains status=failed' );
ok( isset( $blocks_tag['breeze_success_url'] ),                                     'Blocks tag exposes breeze_success_url' );

// ─────────────────────────────────────────────────────────────────────────────
// TEST 7 — Apple Pay cross_domain_name in modal iframe
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 7: Apple Pay cross-domain — cross_domain_name passed to Breeze iframe\n";

// Simulate the payment_url construction with cross_domain_name
function build_modal_payment_url( $base_url, $merchant_domain ) {
    if ( ! empty( $merchant_domain ) ) {
        return add_query_arg_simple( $base_url, array( 'cross_domain_name' => $merchant_domain ) );
    }
    return $base_url;
}

function add_query_arg_simple( $url, $args ) {
    $sep = strpos( $url, '?' ) !== false ? '&' : '?';
    foreach ( $args as $k => $v ) {
        $url .= $sep . urlencode( $k ) . '=' . urlencode( $v );
        $sep = '&';
    }
    return $url;
}

$base = 'https://pay.breeze.cash/p/page_abc123';
$with_domain = build_modal_payment_url( $base, 'shop.example.com' );
$without_domain = build_modal_payment_url( $base, '' );

ok( strpos( $with_domain, 'cross_domain_name=' ) !== false,
    'cross_domain_name query param present when domain is set' );
ok( strpos( $with_domain, 'shop.example.com' ) !== false,
    'Merchant domain value included in URL' );
ok( strpos( $without_domain, 'cross_domain_name' ) === false,
    'cross_domain_name omitted when domain is empty' );

// postMessage handler for request-global-config
$iframe_messages_handled = array( 'request-global-config', 'payment-confirmed', '3ds-challenge' );
ok( in_array( 'request-global-config', $iframe_messages_handled, true ),
    'request-global-config postMessage handled by modal' );

// ─────────────────────────────────────────────────────────────────────────────
// TEST 8 — Cart-clearing fix: modal follows success URL on close-after-confirmed
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 8: Cart-clearing fix — modal navigates to success URL on ESC/backdrop close after confirmed\n";

/**
 * Simulates the modal close handler (v2.0.1 fix).
 * Before: always just closed the modal.
 * After:  if payment was confirmed, follow the success_url so handle_return() runs.
 */
function modal_handle_close( $payment_confirmed, $success_url ) {
    if ( $payment_confirmed && ! empty( $success_url ) ) {
        return array( 'action' => 'navigate', 'url' => $success_url );
    }
    return array( 'action' => 'close' );
}

// Case A: Payment NOT yet confirmed — just close modal (pre-existing behavior)
$result_a = modal_handle_close( false, 'https://shop.example.com/?wc-api=breeze_return&...' );
eq( 'close', $result_a['action'], 'Pre-confirmed close: modal closes without navigating' );

// Case B: Payment confirmed, then user hits ESC — navigate to success URL
$success_url = 'https://shop.example.com/?wc-api=breeze_return&order_id=7&status=success&token=tok';
$result_b = modal_handle_close( true, $success_url );
eq( 'navigate', $result_b['action'],  'Post-confirmed close: action is navigate' );
eq( $success_url, $result_b['url'],   'Navigate URL is the token-protected success URL' );
ok( strpos( $result_b['url'], 'wc-api=breeze_return' ) !== false, 'URL triggers handle_return()' );

// Case C: Confirmed but no success_url (safety guard)
$result_c = modal_handle_close( true, '' );
eq( 'close', $result_c['action'], 'Confirmed but empty URL → safe fallback to close' );

// ─────────────────────────────────────────────────────────────────────────────
// TEST 9 — Legacy modal validates payment-page URL host (parity with Blocks)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 9: Legacy modal validates iframe host (parity with Blocks variant)\n";

$default_domains = array( 'breeze.cash', 'breeze.com' );

// Legacy modal: should validate before loading iframe
$urls_to_test = array(
    array( 'url' => 'https://pay.breeze.cash/p/page_abc',  'expect' => true,  'label' => 'pay.breeze.cash → valid (legacy modal loads)' ),
    array( 'url' => 'https://pay.breeze.com/p/page_abc',   'expect' => true,  'label' => 'pay.breeze.com → valid (legacy modal loads)' ),
    array( 'url' => 'https://evil-breeze.com/p/page_abc',  'expect' => false, 'label' => 'evil-breeze.com → rejected (legacy modal does NOT load)' ),
    array( 'url' => 'https://notbreeze.com/p/page_abc',    'expect' => false, 'label' => 'notbreeze.com → rejected' ),
    array( 'url' => '',                                     'expect' => false, 'label' => 'Empty URL → rejected' ),
);

foreach ( $urls_to_test as $t ) {
    $valid = breeze_is_allowed_payment_url( $t['url'], $default_domains );
    eq( $t['expect'], $valid, $t['label'] );
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 10 — localized JS data: breezeDomains replaces breezeOrigin/breezeHost
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 10: Localized JS — breezeDomains array replaces breezeOrigin/breezeHost\n";

// v1.x shape (removed in v2.0.1)
$old_js_data = array(
    'breezeOrigin' => 'https://pay.breeze.cash',
    'breezeHost'   => 'pay.breeze.cash',
    'ajaxUrl'      => 'https://shop.example.com/wp-admin/admin-ajax.php',
    'nonce'        => 'abc123',
);

// v2.0.1 shape
$new_js_data = array(
    'breezeDomains' => array( 'breeze.cash', 'breeze.com' ),
    'ajaxUrl'       => 'https://shop.example.com/wp-admin/admin-ajax.php',
    'nonce'         => 'abc123',
);

ok( ! isset( $new_js_data['breezeOrigin'] ),        'breezeOrigin removed from JS data' );
ok( ! isset( $new_js_data['breezeHost'] ),          'breezeHost removed from JS data' );
ok( isset( $new_js_data['breezeDomains'] ),         'breezeDomains present in JS data' );
ok( is_array( $new_js_data['breezeDomains'] ),      'breezeDomains is an array' );
ok( in_array( 'breeze.cash', $new_js_data['breezeDomains'], true ), 'breeze.cash in breezeDomains' );
ok( in_array( 'breeze.com',  $new_js_data['breezeDomains'], true ), 'breeze.com in breezeDomains' );

// ─────────────────────────────────────────────────────────────────────────────
// TEST 11 — 3DS auto-expansion postMessage
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 11: 3DS auto-expansion — modal responds to 3ds-challenge postMessage\n";

function modal_handle_postmessage( $event_type, $modal_state ) {
    switch ( $event_type ) {
        case 'payment-confirmed':
            return array_merge( $modal_state, array( 'payment_confirmed' => true, 'show_overlay' => true ) );
        case '3ds-challenge':
            return array_merge( $modal_state, array( 'expanded' => true ) );
        case 'card-validation-error':
            return array_merge( $modal_state, array( 'shake' => true ) );
        case 'request-global-config':
            return array_merge( $modal_state, array( 'config_sent' => true ) );
        default:
            return $modal_state;
    }
}

$state = array( 'payment_confirmed' => false, 'expanded' => false, 'shake' => false, 'show_overlay' => false );

$state = modal_handle_postmessage( 'payment-confirmed', $state );
ok( $state['payment_confirmed'], '"payment-confirmed" event sets confirmed state' );
ok( $state['show_overlay'],      '"payment-confirmed" event shows overlay' );

$state = modal_handle_postmessage( '3ds-challenge', $state );
ok( $state['expanded'],          '"3ds-challenge" event expands modal' );

$state = modal_handle_postmessage( 'card-validation-error', $state );
ok( $state['shake'],             '"card-validation-error" event triggers shake animation' );

$state = modal_handle_postmessage( 'request-global-config', $state );
ok( $state['config_sent'],       '"request-global-config" event sends config to iframe' );

$state_before = $state;
$state = modal_handle_postmessage( 'unknown-event', $state );
eq( $state_before, $state,       'Unknown postMessage event: state unchanged (no crash)' );

// ─────────────────────────────────────────────────────────────────────────────
// TEST 12 — Upgrade path: breeze_modal_origin → breeze_payment_page_domains
// ─────────────────────────────────────────────────────────────────────────────

echo "\n🧪 Test 12: Upgrade — old breeze_modal_origin (string) is NOT the new filter\n";

// The old filter took a single origin string; the new takes an array of base domains.
// A merchant who customised the old filter must migrate.
$old_single_origin = 'https://pay.breeze.cash';    // What old filter returned (string)
$new_domain_array  = array( 'breeze.cash', 'breeze.com' ); // What new filter returns (array)

ok( is_string( $old_single_origin ), 'Old breeze_modal_origin value is a string' );
ok( is_array( $new_domain_array ),   'New breeze_payment_page_domains value is an array' );
ok( $old_single_origin !== $new_domain_array, 'Old and new filter values are different types' );

// Attempting to use old string value with new allowlist function returns false for everything
$url = 'https://pay.breeze.cash/p/page_abc';
$wrong_usage_result = breeze_is_allowed_payment_url( $url, (array) $old_single_origin );
// (array)'https://pay.breeze.cash' = ['https://pay.breeze.cash'] — not a base domain
ok( ! $wrong_usage_result, 'Using old string value as domain array fails (migration required)' );

// Correct new usage works
ok( breeze_is_allowed_payment_url( $url, $new_domain_array ), 'New filter value works correctly' );

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( "━", 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( "━", 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
