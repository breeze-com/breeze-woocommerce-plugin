<?php
/**
 * Tests for get_order_from_webhook() — webhook-to-order resolution and
 * the pageId cross-order replay-protection logic.
 *
 * Loads the REAL WC_Breeze_Payment_Gateway class and exercises the private
 * method via ReflectionMethod, stubbing wc_get_order() and a minimal order
 * object so no WordPress environment is needed.
 *
 * This is the security path: without the stored-pageId check a valid webhook
 * for order A could transition a different order B to paid status.
 *
 * Run: php tests/test-get-order-from-webhook.php
 */

// ─── Stubs/polyfills needed to load the real class standalone ────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
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
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}

// wc_get_order() stub — tests set $wc_order_stub before each call.
$wc_order_stub = false;
function wc_get_order( $id ) {
    global $wc_order_stub;
    return $wc_order_stub;
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Minimal order stub ───────────────────────────────────────────────────────

class Breeze_Webhook_Order_Stub {
    private $id;
    private $meta;

    public function __construct( $id, $meta = array() ) {
        $this->id   = $id;
        $this->meta = $meta;
    }

    public function get_id() { return $this->id; }

    public function get_meta( $key ) {
        return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
    }
}

// ─── Assert harness ───────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function check( $cond, $label ) {
    global $passed, $failed;
    if ( $cond ) { echo "  ✅ {$label}\n"; $passed++; }
    else         { echo "  ❌ {$label}\n"; $failed++; }
}

function check_eq( $expected, $actual, $label ) {
    check(
        $expected === $actual,
        sprintf( '%s (expected %s, got %s)', $label, var_export( $expected, true ), var_export( $actual, true ) )
    );
}

// ─── Test helpers ─────────────────────────────────────────────────────────────

function make_gateway() {
    $ref = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    return $ref->newInstanceWithoutConstructor();
}

/**
 * Invoke the private get_order_from_webhook() on a real gateway instance.
 */
function resolve_order( $gateway, $data ) {
    $method = new ReflectionMethod( 'WC_Breeze_Payment_Gateway', 'get_order_from_webhook' );
    $method->setAccessible( true );
    return $method->invoke( $gateway, $data );
}

$gw = make_gateway();

// ─── Group 1: clientReferenceId validation ────────────────────────────────────

echo "\n🧪 clientReferenceId validation\n";

check_eq( false, resolve_order( $gw, array() ),
    'Missing clientReferenceId key → false' );

check_eq( false, resolve_order( $gw, array( 'clientReferenceId' => 'order-abc' ) ),
    'Non-numeric ref after prefix strip (absint → 0) → false' );

check_eq( false, resolve_order( $gw, array( 'clientReferenceId' => 'order-0' ) ),
    'Zero order ID after prefix strip → false' );

// ─── Group 2: wc_get_order() lookup ──────────────────────────────────────────

echo "\n🧪 Order lookup via wc_get_order()\n";

$wc_order_stub = false;
check_eq( false, resolve_order( $gw, array( 'clientReferenceId' => 'order-42' ) ),
    'Valid ref but wc_get_order() returns false (order not found) → false' );

// The "order-" prefix is stripped but is not required; bare integers also resolve.
$wc_order_stub = new Breeze_Webhook_Order_Stub( 7 );
check_eq( true, resolve_order( $gw, array( 'clientReferenceId' => '7' ) ) instanceof Breeze_Webhook_Order_Stub,
    'clientReferenceId without "order-" prefix → order resolved' );

// ─── Group 3: No stored pageId — order returned without pageId gate ───────────

echo "\n🧪 No stored pageId (legacy orders) — order returned unconditionally\n";

$wc_order_stub = new Breeze_Webhook_Order_Stub( 10 ); // get_meta returns '' for all keys
check_eq( true, resolve_order( $gw, array(
    'clientReferenceId' => 'order-10',
) ) instanceof Breeze_Webhook_Order_Stub,
    'No _breeze_payment_page_id stored → order returned (no pageId check)' );

$wc_order_stub = new Breeze_Webhook_Order_Stub( 11, array(
    '_breeze_payment_page_id' => '', // explicitly empty string — falsy
) );
check_eq( true, resolve_order( $gw, array(
    'clientReferenceId' => 'order-11',
) ) instanceof Breeze_Webhook_Order_Stub,
    'Empty string _breeze_payment_page_id (falsy) → order returned (no pageId check)' );

// ─── Group 4: Stored pageId present, webhook omits pageId ────────────────────

echo "\n🧪 Stored pageId present, webhook omits pageId → rejected\n";

$wc_order_stub = new Breeze_Webhook_Order_Stub( 20, array(
    '_breeze_payment_page_id' => 'pp_stored',
) );
check_eq( false, resolve_order( $gw, array(
    'clientReferenceId' => 'order-20',
    // no pageId key
) ), 'pageId key absent from webhook data → false' );

check_eq( false, resolve_order( $gw, array(
    'clientReferenceId' => 'order-20',
    'pageId'            => '',
) ), 'pageId key present but empty string → false' );

// ─── Group 5: Stored pageId present, webhook pageId does not match ────────────

echo "\n🧪 pageId mismatch → rejected\n";

$wc_order_stub = new Breeze_Webhook_Order_Stub( 30, array(
    '_breeze_payment_page_id' => 'pp_correct',
    // no cumulative list — falls back to single stored ID
) );
check_eq( false, resolve_order( $gw, array(
    'clientReferenceId' => 'order-30',
    'pageId'            => 'pp_wrong',
) ), 'pageId mismatch, no cumulative list → false' );

$wc_order_stub = new Breeze_Webhook_Order_Stub( 31, array(
    '_breeze_payment_page_id'  => 'pp_current',
    '_breeze_payment_page_ids' => array( 'pp_old', 'pp_current' ),
) );
check_eq( false, resolve_order( $gw, array(
    'clientReferenceId' => 'order-31',
    'pageId'            => 'pp_completely_different',
) ), 'pageId not found in cumulative list → false' );

// ─── Group 6: Stored pageId matches → order returned ─────────────────────────

echo "\n🧪 pageId matches → order returned\n";

// Single stored ID, no cumulative list.
$wc_order_stub = new Breeze_Webhook_Order_Stub( 40, array(
    '_breeze_payment_page_id' => 'pp_exact',
) );
check_eq( true, resolve_order( $gw, array(
    'clientReferenceId' => 'order-40',
    'pageId'            => 'pp_exact',
) ) instanceof Breeze_Webhook_Order_Stub,
    'pageId matches single _breeze_payment_page_id → order returned' );

// Cumulative list — webhook sends the most-recent entry.
$wc_order_stub = new Breeze_Webhook_Order_Stub( 41, array(
    '_breeze_payment_page_id'  => 'pp_latest',
    '_breeze_payment_page_ids' => array( 'pp_first', 'pp_latest' ),
) );
check_eq( true, resolve_order( $gw, array(
    'clientReferenceId' => 'order-41',
    'pageId'            => 'pp_latest',
) ) instanceof Breeze_Webhook_Order_Stub,
    'pageId matches latest entry in cumulative list → order returned' );

// Cumulative list — webhook sends an older entry (retry / out-of-order delivery).
$wc_order_stub = new Breeze_Webhook_Order_Stub( 42, array(
    '_breeze_payment_page_id'  => 'pp_v3',
    '_breeze_payment_page_ids' => array( 'pp_v1', 'pp_v2', 'pp_v3' ),
) );
check_eq( true, resolve_order( $gw, array(
    'clientReferenceId' => 'order-42',
    'pageId'            => 'pp_v1',
) ) instanceof Breeze_Webhook_Order_Stub,
    'pageId matches older entry in cumulative list (retry path) → order returned' );

// Empty cumulative list falls back to single stored ID.
$wc_order_stub = new Breeze_Webhook_Order_Stub( 43, array(
    '_breeze_payment_page_id'  => 'pp_single',
    '_breeze_payment_page_ids' => array(), // empty array → treated as "no list"
) );
check_eq( true, resolve_order( $gw, array(
    'clientReferenceId' => 'order-43',
    'pageId'            => 'pp_single',
) ) instanceof Breeze_Webhook_Order_Stub,
    'Empty _breeze_payment_page_ids falls back to single stored ID → order returned' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
