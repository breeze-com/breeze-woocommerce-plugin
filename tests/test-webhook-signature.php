<?php
/**
 * Tests for webhook HMAC signature verification.
 *
 * Loads the REAL WC_Breeze_Payment_Gateway class and exercises the actual
 * verify_webhook_signature() + ksort_recursive() implementation (via
 * reflection, since both are private), so the assertions track production
 * behavior rather than a copy of it. This is the path that decides whether
 * a payment.succeeded webhook is trusted — a spoofable signature check
 * would let anyone mark orders as paid.
 *
 * Run: php tests/test-webhook-signature.php
 */

// ─── Stubs/polyfills needed to load the real class standalone ────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    // Minimal base so the gateway class can be parsed/loaded without WooCommerce.
    class WC_Payment_Gateway {}
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Tiny assert harness (matches the rest of the suite's output style) ──────

$passed = 0;
$failed = 0;

function check( $cond, $label ) {
    global $passed, $failed;
    if ( $cond ) { echo "  ✅ {$label}\n"; $passed++; }
    else         { echo "  ❌ {$label}\n"; $failed++; }
}

function check_eq( $expected, $actual, $label ) {
    check( $expected === $actual, sprintf( '%s (expected %s, got %s)', $label, var_export( $expected, true ), var_export( $actual, true ) ) );
}

// ─── Test helpers ────────────────────────────────────────────────────────────

const TEST_SECRET = 'whsec_test_4f8a2b1c9d0e';

/**
 * Build a gateway instance without running the WP-dependent constructor,
 * with the given webhook secret injected.
 */
function make_gateway( $secret ) {
    $ref     = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gateway = $ref->newInstanceWithoutConstructor();

    $prop = $ref->getProperty( 'webhook_secret' );
    $prop->setAccessible( true );
    $prop->setValue( $gateway, $secret );

    return $gateway;
}

/**
 * Call the private verify_webhook_signature() on the real instance.
 */
function verify( $gateway, $webhook_data ) {
    $method = new ReflectionMethod( 'WC_Breeze_Payment_Gateway', 'verify_webhook_signature' );
    $method->setAccessible( true );
    return $method->invoke( $gateway, $webhook_data );
}

/**
 * Recursively ksort a copy of the data (independent reimplementation,
 * used only to produce signatures the way the Breeze API would).
 */
function canonical_sort( $array ) {
    if ( ! is_array( $array ) ) {
        return $array;
    }
    ksort( $array );
    foreach ( $array as $key => $value ) {
        $array[ $key ] = canonical_sort( $value );
    }
    return $array;
}

/**
 * Sign data the way the Breeze API does: recursive key sort, compact JSON
 * with unescaped slashes/unicode, HMAC-SHA256, base64.
 */
function breeze_sign( $data, $secret ) {
    $json = json_encode( canonical_sort( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    return base64_encode( hash_hmac( 'sha256', $json, $secret, true ) );
}

$gateway = make_gateway( TEST_SECRET );

$data = array(
    'clientReferenceId' => 'order-123',
    'amount'            => 1049,
    'currency'          => 'USD',
    'paymentPageId'     => 'pp_abc123',
);

// ─── Valid signatures are accepted ───────────────────────────────────────────

echo "\n🧪 Webhook signature: valid signatures accepted\n";

check_eq( true, verify( $gateway, array(
    'signature' => breeze_sign( $data, TEST_SECRET ),
    'data'      => $data,
) ), 'Correctly signed payload → accepted' );

// Same payload with data keys in a different order must still verify:
// the gateway canonicalizes via ksort_recursive() before hashing.
$shuffled = array(
    'paymentPageId'     => 'pp_abc123',
    'currency'          => 'USD',
    'clientReferenceId' => 'order-123',
    'amount'            => 1049,
);
check_eq( true, verify( $gateway, array(
    'signature' => breeze_sign( $data, TEST_SECRET ),
    'data'      => $shuffled,
) ), 'Same data, different key order → accepted (ksort_recursive canonicalization)' );

// Nested objects must be sorted recursively, not just at the top level.
$nested_sorted = array(
    'amount'   => 500,
    'customer' => array( 'email' => 'a@b.c', 'name' => 'Ada' ),
);
$nested_shuffled = array(
    'customer' => array( 'name' => 'Ada', 'email' => 'a@b.c' ),
    'amount'   => 500,
);
check_eq( true, verify( $gateway, array(
    'signature' => breeze_sign( $nested_sorted, TEST_SECRET ),
    'data'      => $nested_shuffled,
) ), 'Nested keys in different order → accepted (recursive sort)' );

// Slashes and unicode must round-trip unescaped (Breeze signs the raw chars).
$unicode_data = array(
    'clientReferenceId' => 'order-456',
    'description'       => 'Café order — https://shop.example.com/item/42',
);
check_eq( true, verify( $gateway, array(
    'signature' => breeze_sign( $unicode_data, TEST_SECRET ),
    'data'      => $unicode_data,
) ), 'URL slashes + unicode in data → accepted (unescaped JSON encoding)' );

// ─── Invalid signatures are rejected ─────────────────────────────────────────

echo "\n🧪 Webhook signature: forgeries and tampering rejected\n";

$tampered          = $data;
$tampered['amount'] = 1;
check_eq( false, verify( $gateway, array(
    'signature' => breeze_sign( $data, TEST_SECRET ),
    'data'      => $tampered,
) ), 'Tampered data (amount changed) → rejected' );

check_eq( false, verify( $gateway, array(
    'signature' => breeze_sign( $data, 'whsec_wrong_secret' ),
    'data'      => $data,
) ), 'Signed with wrong secret → rejected' );

check_eq( false, verify( $gateway, array(
    'signature' => 'not-even-base64-hmac',
    'data'      => $data,
) ), 'Garbage signature → rejected' );

check_eq( false, verify( $gateway, array(
    'signature' => '',
    'data'      => $data,
) ), 'Empty signature → rejected' );

check_eq( false, verify( $gateway, array(
    'data' => $data,
) ), 'Missing signature key → rejected' );

check_eq( false, verify( $gateway, array(
    'signature' => breeze_sign( array(), TEST_SECRET ),
    'data'      => array(),
) ), 'Empty data → rejected even if signature matches empty payload' );

// ─── No configured secret means nothing verifies ─────────────────────────────

echo "\n🧪 Webhook signature: missing secret fails closed\n";

$no_secret_gateway = make_gateway( '' );
check_eq( false, verify( $no_secret_gateway, array(
    'signature' => breeze_sign( $data, TEST_SECRET ),
    'data'      => $data,
) ), 'No webhook secret configured → rejected (fail closed, no spoofable bypass)' );

check_eq( false, verify( $no_secret_gateway, array(
    'signature' => breeze_sign( $data, '' ),
    'data'      => $data,
) ), 'No secret configured + signature made with empty secret → still rejected' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
