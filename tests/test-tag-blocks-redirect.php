<?php
/**
 * Tests for WC_Breeze_Modal_Checkout::tag_blocks_redirect()
 *
 * Loads the REAL WC_Breeze_Modal_Checkout class via reflection
 * (constructor skipped — it only registers WP hooks which are no-ops here).
 * Exercises every branch of tag_blocks_redirect() and the private
 * build_return_url() helper it delegates to.
 *
 * Covers:
 *  - Non-array $result → returned unchanged (pass-through)
 *  - Array without 'redirect' key → returned unchanged
 *  - Order not found (wc_get_order returns null) → returned unchanged
 *  - Order has wrong payment method → returned unchanged
 *  - Gateway not available (WC()->payment_gateways() null) → returned unchanged
 *  - Gateway missing from registry → returned unchanged
 *  - Gateway checkout_display !== 'modal' → returned unchanged
 *  - Happy path, no return token → breeze_modal true, URLs empty
 *  - Happy path, return token set → URLs contain expected params
 *  - WC()->session present → session->set() called with order id
 *
 * Run: php tests/test-tag-blocks-redirect.php
 */

// ─── Stubs / polyfills ────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { return $value; }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'https://example.com' . $path;
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $args, $url = '' ) {
        if ( is_array( $args ) ) {
            $sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
            return $url . $sep . http_build_query( $args );
        }
        return $url;
    }
}

// Global controllable mock for wc_get_order().
$_mock_order = null;

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $order_id ) {
        global $_mock_order;
        return $_mock_order;
    }
}

// Global controllable mock for WC().
$_mock_wc = null;

if ( ! function_exists( 'WC' ) ) {
    function WC() {
        global $_mock_wc;
        return $_mock_wc;
    }
}

// Minimal WC_Breeze_Payment_Gateway stub (only checkout_display needed here).
if ( ! class_exists( 'WC_Breeze_Payment_Gateway' ) ) {
    class WC_Breeze_Payment_Gateway {
        public $checkout_display = 'modal';
    }
}

// WC payment-gateways manager stub — payment_gateways() returns the gateways array.
class Mock_WC_Payment_Gateways {
    private $gateways;
    public function __construct( array $gateways = array() ) {
        $this->gateways = $gateways;
    }
    public function payment_gateways() {
        return $this->gateways;
    }
}

// WC() root mock — holds the payment-gateways manager and a session.
class Mock_WC {
    public $pg;
    public $session = null;
    public function __construct( $pg = null ) {
        $this->pg = $pg;
    }
    public function payment_gateways() {
        return $this->pg;
    }
}

// Session stub with a call log so tests can assert set() was invoked.
class Mock_Session {
    public $calls = array();
    public function set( $key, $value ) {
        $this->calls[] = array( 'key' => $key, 'value' => $value );
    }
    public function get( $key ) { return null; }
}

// Order stub.
class Mock_Order {
    public $payment_method = 'breeze_payment_gateway';
    public $id             = 42;
    public $return_token   = '';

    public function get_payment_method() { return $this->payment_method; }
    public function get_id()             { return $this->id; }
    public function get_meta( $key ) {
        if ( '_breeze_return_token' === $key ) { return $this->return_token; }
        return '';
    }
}

require_once __DIR__ . '/../includes/class-wc-breeze-modal-checkout.php';

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

// ─── Helper: build an instance without running the private constructor ─────────

function make_modal() {
    $ref = new ReflectionClass( 'WC_Breeze_Modal_Checkout' );
    return $ref->newInstanceWithoutConstructor();
}

// ─── Helper: build a fully-wired WC() mock with the given gateway ─────────────

function wire_gateway( $gateway ) {
    global $_mock_wc;
    $pg       = new Mock_WC_Payment_Gateways( array( 'breeze_payment_gateway' => $gateway ) );
    $_mock_wc = new Mock_WC( $pg );
}

// ─── 1. Non-array $result → pass-through ─────────────────────────────────────

echo "\n🧪 1. Non-array \$result → pass-through\n";

$modal  = make_modal();
$result = $modal->tag_blocks_redirect( 'not-an-array', 1 );

check_eq( 'not-an-array', $result, 'string result returned unchanged' );

// ─── 2. Array without redirect key → pass-through ────────────────────────────

echo "\n🧪 2. Array without 'redirect' key → pass-through\n";

$modal  = make_modal();
$input  = array( 'other' => 'value' );
$result = $modal->tag_blocks_redirect( $input, 1 );

check_eq( $input, $result, 'array without redirect key returned unchanged' );

// ─── 3. Order not found → pass-through ───────────────────────────────────────

echo "\n🧪 3. Order not found → pass-through\n";

global $_mock_order, $_mock_wc;
$_mock_order = null;

$modal  = make_modal();
$input  = array( 'redirect' => 'https://site.example.com/pay' );
$result = $modal->tag_blocks_redirect( $input, 99 );

check_eq( $input, $result, 'result unchanged when wc_get_order returns null' );
check( ! isset( $result['breeze_modal'] ), 'breeze_modal not added when order not found' );

// ─── 4. Wrong payment method → pass-through ──────────────────────────────────

echo "\n🧪 4. Order has wrong payment method → pass-through\n";

$order                 = new Mock_Order();
$order->payment_method = 'paypal';
$_mock_order           = $order;

$modal  = make_modal();
$input  = array( 'redirect' => 'https://site.example.com/pay' );
$result = $modal->tag_blocks_redirect( $input, 42 );

check_eq( $input, $result, 'result unchanged when order payment method does not match' );
check( ! isset( $result['breeze_modal'] ), 'breeze_modal not added when payment method is wrong' );

// ─── 5. Gateway manager unavailable (WC()->payment_gateways() null) ───────────

echo "\n🧪 5. Gateway manager unavailable → pass-through\n";

$order                 = new Mock_Order();
$order->payment_method = 'breeze_payment_gateway';
$_mock_order           = $order;
$_mock_wc              = new Mock_WC( null ); // payment_gateways() returns null

$modal  = make_modal();
$input  = array( 'redirect' => 'https://site.example.com/pay' );
$result = $modal->tag_blocks_redirect( $input, 42 );

check_eq( $input, $result, 'result unchanged when WC()->payment_gateways() is null' );
check( ! isset( $result['breeze_modal'] ), 'breeze_modal not added when gateway manager is null' );

// ─── 6. Gateway not in registry → pass-through ───────────────────────────────

echo "\n🧪 6. Gateway not registered → pass-through\n";

$_mock_order           = new Mock_Order();
$pg                    = new Mock_WC_Payment_Gateways( array() ); // empty — no breeze key
$_mock_wc              = new Mock_WC( $pg );

$modal  = make_modal();
$input  = array( 'redirect' => 'https://site.example.com/pay' );
$result = $modal->tag_blocks_redirect( $input, 42 );

check_eq( $input, $result, 'result unchanged when gateway not found in registry' );
check( ! isset( $result['breeze_modal'] ), 'breeze_modal not added when gateway missing from registry' );

// ─── 7. checkout_display !== modal → pass-through ────────────────────────────

echo "\n🧪 7. Gateway checkout_display is not modal → pass-through\n";

$_mock_order           = new Mock_Order();
$gw                    = new WC_Breeze_Payment_Gateway();
$gw->checkout_display  = 'redirect';
wire_gateway( $gw );

$modal  = make_modal();
$input  = array( 'redirect' => 'https://site.example.com/pay' );
$result = $modal->tag_blocks_redirect( $input, 42 );

check_eq( $input, $result, 'result unchanged when checkout_display is not modal' );
check( ! isset( $result['breeze_modal'] ), 'breeze_modal not added when display mode is redirect' );

// ─── 8. Happy path, no return token → empty URLs ─────────────────────────────

echo "\n🧪 8. Happy path — return token absent → breeze_modal true, URLs empty\n";

$order               = new Mock_Order();
$order->return_token = ''; // no token
$_mock_order         = $order;

$gw                   = new WC_Breeze_Payment_Gateway();
$gw->checkout_display = 'modal';
wire_gateway( $gw );
$_mock_wc->session    = null; // no session

$modal  = make_modal();
$input  = array( 'redirect' => 'https://pay.breeze.cash/page/abc' );
$result = $modal->tag_blocks_redirect( $input, 42 );

check( isset( $result['breeze_modal'] ),             'breeze_modal key added' );
check_eq( true, $result['breeze_modal'],              'breeze_modal is true' );
check_eq( '', $result['breeze_success_url'],          'success URL is empty when no return token' );
check_eq( '', $result['breeze_fail_url'],             'fail URL is empty when no return token' );
check( isset( $result['redirect'] ),                  'original redirect key preserved' );

// ─── 9. Happy path, return token set → URLs contain expected params ───────────

echo "\n🧪 9. Happy path — return token set → URLs built correctly\n";

$order               = new Mock_Order();
$order->id           = 42;
$order->return_token = 'tok-xyz-123';
$_mock_order         = $order;

$gw                   = new WC_Breeze_Payment_Gateway();
$gw->checkout_display = 'modal';
wire_gateway( $gw );
$_mock_wc->session    = null;

$modal  = make_modal();
$input  = array( 'redirect' => 'https://pay.breeze.cash/page/abc' );
$result = $modal->tag_blocks_redirect( $input, 42 );

check( isset( $result['breeze_modal'] ),                                                 'breeze_modal key present' );
check_eq( true, $result['breeze_modal'],                                                  'breeze_modal is true' );

$success_url = $result['breeze_success_url'];
check( strpos( $success_url, 'wc-api=breeze_return' ) !== false,  'success URL has wc-api param' );
check( strpos( $success_url, 'order_id=42' ) !== false,           'success URL has order_id' );
check( strpos( $success_url, 'status=success' ) !== false,        'success URL has status=success' );
check( strpos( $success_url, 'token=tok-xyz-123' ) !== false,     'success URL has correct token' );

$fail_url = $result['breeze_fail_url'];
check( strpos( $fail_url, 'status=failed' ) !== false,            'fail URL has status=failed' );
check( strpos( $fail_url, 'token=tok-xyz-123' ) !== false,        'fail URL has correct token' );
check( strpos( $fail_url, 'order_id=42' ) !== false,              'fail URL has order_id' );

// ─── 10. Session present → session->set() called with order id ───────────────

echo "\n🧪 10. WC session present → session->set() receives order id\n";

$order               = new Mock_Order();
$order->id           = 7;
$order->return_token = 'tok-sess-test';
$_mock_order         = $order;

$gw                   = new WC_Breeze_Payment_Gateway();
$gw->checkout_display = 'modal';
wire_gateway( $gw );

$session           = new Mock_Session();
$_mock_wc->session = $session;

$modal  = make_modal();
$input  = array( 'redirect' => 'https://pay.breeze.cash/page/sess' );
$result = $modal->tag_blocks_redirect( $input, 7 );

check( count( $session->calls ) === 1,                                     'session->set() called once' );
check_eq( 'breeze_modal_pending_order', $session->calls[0]['key'],         'session key is breeze_modal_pending_order' );
check_eq( 7, $session->calls[0]['value'],                                  'session value is the order id (int)' );

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
