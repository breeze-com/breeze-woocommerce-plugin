<?php
/**
 * Tests for get_order_from_subscription_webhook() and order_exists_with_invoice_id().
 *
 * Loads the REAL WC_Breeze_Payment_Gateway class and exercises both private
 * methods via ReflectionMethod, stubbing wc_get_order() and wc_get_orders()
 * so no WordPress environment is needed.
 *
 * get_order_from_subscription_webhook() prefers clientReferenceId (same
 * "order-{id}" format as payment webhooks) and cross-checks the resolved
 * order's _breeze_subscription_id before returning it — guarding against a
 * signed payload whose clientReferenceId resolves to an unrelated order.
 * order_exists_with_invoice_id() is the idempotency guard used by the
 * INVOICE_STATUS_UPDATED:PAID handler to prevent duplicate renewals.
 *
 * Run: php tests/test-get-order-from-subscription-webhook.php
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

// wc_get_orders() stub — tests set $wc_orders_stub before each call.
$wc_orders_stub = array();
function wc_get_orders( $args ) {
    global $wc_orders_stub;
    return $wc_orders_stub;
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Minimal order stub ───────────────────────────────────────────────────────

class Breeze_Sub_Webhook_Order_Stub {
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

function resolve_sub_order( $gateway, $data ) {
    $method = new ReflectionMethod( 'WC_Breeze_Payment_Gateway', 'get_order_from_subscription_webhook' );
    $method->setAccessible( true );
    return $method->invoke( $gateway, $data );
}

function invoice_exists( $gateway, $invoice_id ) {
    $method = new ReflectionMethod( 'WC_Breeze_Payment_Gateway', 'order_exists_with_invoice_id' );
    $method->setAccessible( true );
    return $method->invoke( $gateway, $invoice_id );
}

$gw = make_gateway();

// ─── Group 1: clientReferenceId parsing ───────────────────────────────────────

echo "\n🧪 clientReferenceId parsing\n";

check_eq( false, resolve_sub_order( $gw, array() ),
    'Empty payload (no clientReferenceId, no id) → false' );

check_eq( false, resolve_sub_order( $gw, array( 'clientReferenceId' => 'order-abc' ) ),
    'Non-numeric ref after prefix strip (absint → 0) → false' );

check_eq( false, resolve_sub_order( $gw, array( 'clientReferenceId' => 'order-0' ) ),
    'Zero order ID after prefix strip → false' );

// ─── Group 2: wc_get_order() lookup ──────────────────────────────────────────

echo "\n🧪 Order lookup via wc_get_order()\n";

$wc_order_stub  = false;
$wc_orders_stub = array();
check_eq( false, resolve_sub_order( $gw, array( 'clientReferenceId' => 'order-42', 'id' => 'sub_1' ) ),
    'Valid ref, wc_get_order() returns false, no fallback orders → false' );

// No 'id' in data → subscription_id is empty string → cross-check is skipped.
$wc_order_stub  = new Breeze_Sub_Webhook_Order_Stub( 10 );
$wc_orders_stub = array();
check_eq( true, resolve_sub_order( $gw, array( 'clientReferenceId' => 'order-10' ) ) instanceof Breeze_Sub_Webhook_Order_Stub,
    'Valid ref, order found, no id in data (no cross-check) → order returned' );

// ─── Group 3: subscription ID cross-check (security) ─────────────────────────

echo "\n🧪 Subscription ID cross-check\n";

$wc_order_stub  = new Breeze_Sub_Webhook_Order_Stub( 20, array( '_breeze_subscription_id' => 'sub_match' ) );
$wc_orders_stub = array();
check_eq( true, resolve_sub_order( $gw, array(
    'clientReferenceId' => 'order-20',
    'id'                => 'sub_match',
) ) instanceof Breeze_Sub_Webhook_Order_Stub,
    'id matches order\'s _breeze_subscription_id → order returned' );

// Cross-check fails; fallback meta query is empty → false.
$wc_order_stub  = new Breeze_Sub_Webhook_Order_Stub( 30, array( '_breeze_subscription_id' => 'sub_A' ) );
$wc_orders_stub = array();
check_eq( false, resolve_sub_order( $gw, array(
    'clientReferenceId' => 'order-30',
    'id'                => 'sub_DIFFERENT',
) ),
    'id mismatches order\'s _breeze_subscription_id, no fallback orders → false' );

// Cross-check fails; fallback meta query finds an order → that order returned.
$fallback_order = new Breeze_Sub_Webhook_Order_Stub( 31, array( '_breeze_subscription_id' => 'sub_DIFFERENT' ) );
$wc_order_stub  = new Breeze_Sub_Webhook_Order_Stub( 30, array( '_breeze_subscription_id' => 'sub_A' ) );
$wc_orders_stub = array( $fallback_order );
check_eq( true, resolve_sub_order( $gw, array(
    'clientReferenceId' => 'order-30',
    'id'                => 'sub_DIFFERENT',
) ) instanceof Breeze_Sub_Webhook_Order_Stub,
    'id mismatches, but fallback meta query finds an order → order returned' );

// ─── Group 4: fallback to subscription ID meta query ─────────────────────────

echo "\n🧪 Fallback to _breeze_subscription_id meta query\n";

$wc_order_stub  = false;
$wc_orders_stub = array( new Breeze_Sub_Webhook_Order_Stub( 50, array( '_breeze_subscription_id' => 'sub_2' ) ) );
check_eq( true, resolve_sub_order( $gw, array( 'id' => 'sub_2' ) ) instanceof Breeze_Sub_Webhook_Order_Stub,
    'No clientReferenceId, id present, meta query finds order → order returned' );

$wc_order_stub  = false;
$wc_orders_stub = array();
check_eq( false, resolve_sub_order( $gw, array() ),
    'No clientReferenceId, no id → false' );

// ─── Group 5: order_exists_with_invoice_id() ─────────────────────────────────

echo "\n🧪 order_exists_with_invoice_id() — idempotency guard\n";

$wc_orders_stub = array();
check_eq( false, invoice_exists( $gw, 'inv_999' ),
    'wc_get_orders() returns empty → false (no duplicate prevention triggered)' );

$wc_orders_stub = array( 42 ); // ids returned when 'return' => 'ids'
check_eq( true, invoice_exists( $gw, 'inv_ABC' ),
    'wc_get_orders() returns non-empty → true (invoice already recorded)' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
