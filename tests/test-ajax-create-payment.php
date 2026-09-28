<?php
/**
 * Tests for WC_Breeze_Modal_Checkout::ajax_create_payment()
 *
 * Loads the REAL WC_Breeze_Modal_Checkout class via ReflectionClass
 * (constructor skipped — it only registers WP hooks which are no-ops here).
 * wp_send_json_error/success are stubbed to throw exceptions so each
 * branch can be asserted without process termination.
 *
 * Covers:
 *  - Breeze nonce fail → json_error 403
 *  - WooCommerce checkout nonce fail → json_error 403
 *  - Cart empty → json_error
 *  - Gateway not available (payment_gateways() null) → json_error
 *  - create_order() throws Exception → json_error with exception message
 *  - create_order() returns WP_Error → json_error with WP_Error message
 *  - create_order() returns 0 (falsy) → json_error
 *  - wc_get_order() returns false after create → json_error
 *  - create_payment_for_order() returns WP_Error → order cancelled + json_error
 *  - Success → session set + json_success with paymentUrl/orderId/successUrl/failUrl
 *
 * Run: php tests/test-ajax-create-payment.php
 */

// ─── Constants ────────────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

// ─── Exception sentinels (replace wp_send_json_* termination) ─────────────────

class BreezeACPJsonError extends RuntimeException {
    public $data;
    public $status;
    public function __construct( $data = null, $status = null ) {
        $this->data   = $data;
        $this->status = $status;
        parent::__construct( isset( $data['message'] ) ? (string) $data['message'] : '' );
    }
}

class BreezeACPJsonSuccess extends RuntimeException {
    public $data;
    public function __construct( $data = null ) {
        $this->data = $data;
        parent::__construct( '' );
    }
}

// ─── Stubs / polyfills ────────────────────────────────────────────────────────

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

// Controllable: check_ajax_referer (Breeze modal nonce)
$_mock_nonce_ok = true;

if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action, $query_arg = false, $die = true ) {
        global $_mock_nonce_ok;
        return $_mock_nonce_ok ? 1 : false;
    }
}

// Controllable: wp_verify_nonce (WooCommerce checkout nonce)
$_mock_wc_nonce_ok = true;

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action = -1 ) {
        global $_mock_wc_nonce_ok;
        return $_mock_wc_nonce_ok ? 1 : false;
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) { return $value; }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) { return trim( (string) $str ); }
}

if ( ! function_exists( 'wp_parse_str' ) ) {
    function wp_parse_str( $string, &$array ) { parse_str( $string, $array ); }
}

// Controllable: WC() root object
$_mock_wc = null;

if ( ! function_exists( 'WC' ) ) {
    function WC() {
        global $_mock_wc;
        return $_mock_wc;
    }
}

// Controllable: wc_get_order() (the order fetched after create_order returns an id)
$_mock_created_order = null;

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $id ) {
        global $_mock_created_order;
        return $_mock_created_order;
    }
}

if ( ! function_exists( 'do_action' ) ) { function do_action() {} }

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '/' ) { return 'https://example.com' . $path; }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $args, $url = '' ) {
        return is_array( $args ) ? $url . '?' . http_build_query( $args ) : $url;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null, $status_code = null, $flags = 0 ) {
        throw new BreezeACPJsonError( $data, $status_code );
    }
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null, $status_code = null, $flags = 0 ) {
        throw new BreezeACPJsonSuccess( $data );
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $message;
        public function __construct( $code = '', $message = '' ) { $this->message = $message; }
        public function get_error_message() { return $this->message; }
    }
}

// ─── Gateway stub — needed for instanceof check inside get_gateway() ──────────

if ( ! class_exists( 'WC_Breeze_Payment_Gateway' ) ) {
    class WC_Breeze_Payment_Gateway {
        public function create_payment_for_order( $order ) { return null; }
    }
}

// ─── Mock classes ─────────────────────────────────────────────────────────────

// Gateway subclass with controllable create_payment_for_order() result.
class ACP_MockGateway extends WC_Breeze_Payment_Gateway {
    public $create_result;
    public function create_payment_for_order( $order ) {
        return $this->create_result;
    }
}

// Cart mock.
class Mock_ACP_Cart {
    public $empty;
    public function __construct( $empty = false ) { $this->empty = $empty; }
    public function is_empty() { return $this->empty; }
}

// Checkout mock: create_order() returns $create_result, or throws it if it is an Exception.
class Mock_ACP_Checkout {
    public $create_result;
    public function get_posted_data() { return array(); }
    public function create_order( $data ) {
        if ( $this->create_result instanceof Exception ) {
            throw $this->create_result;
        }
        return $this->create_result;
    }
}

// Payment-gateways manager mock.
class Mock_ACP_PaymentGateways {
    private $gateways;
    public function __construct( $gateways = array() ) { $this->gateways = $gateways; }
    public function payment_gateways() { return $this->gateways; }
}

// Session mock.
class Mock_ACP_Session {
    public $log = array();
    public function set( $key, $value ) { $this->log[] = array( 'key' => $key, 'value' => $value ); }
}

// Order mock returned by wc_get_order() after create_order() succeeds.
class Mock_ACP_Order {
    public $id;
    public $token;
    public $status_log = array();
    public function __construct( $id, $token = 'tok_abc' ) {
        $this->id    = $id;
        $this->token = $token;
    }
    public function get_id() { return $this->id; }
    public function get_meta( $key ) { return '_breeze_return_token' === $key ? $this->token : ''; }
    public function update_status( $status, $note = '' ) {
        $this->status_log[] = array( 'status' => $status, 'note' => $note );
    }
}

// WC() root mock.  checkout_obj and pg_obj are public so tests can adjust them after construction.
class Mock_ACP_WC {
    public $cart;
    public $session;
    public $checkout_obj;
    public $pg_obj;

    public function __construct( $cart = null, $session = null, $checkout_obj = null, $pg_obj = null ) {
        $this->cart         = $cart;
        $this->session      = $session;
        $this->checkout_obj = $checkout_obj;
        $this->pg_obj       = $pg_obj;
    }

    public function checkout()         { return $this->checkout_obj; }
    public function payment_gateways() { return $this->pg_obj; }
}

require_once __DIR__ . '/../includes/class-wc-breeze-modal-checkout.php';

// ─── Assert harness ───────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function acp_check( $cond, $label ) {
    global $passed, $failed;
    if ( $cond ) { echo "  ✅ {$label}\n"; $passed++; }
    else         { echo "  ❌ {$label}\n"; $failed++; }
}

function acp_check_eq( $expected, $actual, $label ) {
    acp_check(
        $expected === $actual,
        sprintf( '%s (expected %s, got %s)', $label, var_export( $expected, true ), var_export( $actual, true ) )
    );
}

function make_acp_modal() {
    return ( new ReflectionClass( 'WC_Breeze_Modal_Checkout' ) )->newInstanceWithoutConstructor();
}

// Build a wired WC mock.  Callers can set ->checkout_obj->create_result after construction.
function make_acp_wc( $pg_obj = null, $cart_empty = false ) {
    return new Mock_ACP_WC(
        new Mock_ACP_Cart( $cart_empty ),
        new Mock_ACP_Session(),
        new Mock_ACP_Checkout(),
        $pg_obj
    );
}

// Wrap a single gateway in a payment-gateways manager keyed by the gateway id.
function make_acp_pg( $gateway ) {
    return new Mock_ACP_PaymentGateways( array( 'breeze_payment_gateway' => $gateway ) );
}

// ─── Tests ────────────────────────────────────────────────────────────────────

// ------------------------------------------------------------------
// Group 1: Breeze nonce fails → 403
// ------------------------------------------------------------------
echo "\n🧪 ajax_create_payment: Breeze nonce fail → 403\n";
$_mock_nonce_ok    = false;
$_mock_wc_nonce_ok = true;
$_mock_wc          = null;
$_POST             = array( 'nonce' => 'bad-nonce' );

$ex = null;
try { make_acp_modal()->ajax_create_payment(); }
catch ( BreezeACPJsonError $e ) { $ex = $e; }

acp_check( $ex instanceof BreezeACPJsonError, 'Breeze nonce fail → BreezeACPJsonError thrown' );
acp_check_eq( 403, $ex ? $ex->status : null, 'Breeze nonce fail → status 403' );
acp_check( $ex && false !== strpos( $ex->getMessage(), 'Security check failed' ), 'Breeze nonce fail → security message' );

// ------------------------------------------------------------------
// Group 2: WC checkout nonce fails → 403
// ------------------------------------------------------------------
echo "\n🧪 ajax_create_payment: WC nonce fail → 403\n";
$_mock_nonce_ok    = true;
$_mock_wc_nonce_ok = false;
$_mock_wc          = null;
$_POST             = array( 'nonce' => 'test-nonce', 'woocommerce-process-checkout-nonce' => 'bad-wc-nonce' );

$ex = null;
try { make_acp_modal()->ajax_create_payment(); }
catch ( BreezeACPJsonError $e ) { $ex = $e; }

acp_check( $ex instanceof BreezeACPJsonError, 'WC nonce fail → BreezeACPJsonError thrown' );
acp_check_eq( 403, $ex ? $ex->status : null, 'WC nonce fail → status 403' );
acp_check( $ex && false !== strpos( $ex->getMessage(), 'process your order' ), 'WC nonce fail → order-process message' );

// ------------------------------------------------------------------
// Group 3: Cart empty → json_error
// ------------------------------------------------------------------
echo "\n🧪 ajax_create_payment: cart empty → json_error\n";
$_mock_nonce_ok    = true;
$_mock_wc_nonce_ok = true;
$_mock_wc          = make_acp_wc( null, /* cart_empty */ true );
$_POST             = array( 'nonce' => 'test-nonce', 'woocommerce-process-checkout-nonce' => 'wc-nonce' );

$ex = null;
try { make_acp_modal()->ajax_create_payment(); }
catch ( BreezeACPJsonError $e ) { $ex = $e; }

acp_check( $ex instanceof BreezeACPJsonError, 'cart empty → BreezeACPJsonError thrown' );
acp_check( $ex && false !== strpos( $ex->getMessage(), 'cart is empty' ), 'cart empty → message' );

// ------------------------------------------------------------------
// Group 4: Gateway not available (payment_gateways() null) → json_error
// ------------------------------------------------------------------
echo "\n🧪 ajax_create_payment: gateway unavailable → json_error\n";
$_mock_nonce_ok    = true;
$_mock_wc_nonce_ok = true;
$_mock_wc          = make_acp_wc( /* pg_obj */ null, /* cart_empty */ false );
$_POST             = array( 'nonce' => 'test-nonce', 'woocommerce-process-checkout-nonce' => 'wc-nonce' );

$ex = null;
try { make_acp_modal()->ajax_create_payment(); }
catch ( BreezeACPJsonError $e ) { $ex = $e; }

acp_check( $ex instanceof BreezeACPJsonError, 'gateway null → BreezeACPJsonError thrown' );
acp_check( $ex && false !== strpos( $ex->getMessage(), 'not available' ), 'gateway null → message' );

// ------------------------------------------------------------------
// Group 5: create_order() throws Exception → json_error
// ------------------------------------------------------------------
echo "\n🧪 ajax_create_payment: create_order() throws → json_error\n";
$_mock_nonce_ok    = true;
$_mock_wc_nonce_ok = true;
$wc5               = make_acp_wc( make_acp_pg( new ACP_MockGateway() ) );
$wc5->checkout_obj->create_result = new Exception( 'DB connection failed' );
$_mock_wc          = $wc5;
$_POST             = array( 'nonce' => 'test-nonce', 'woocommerce-process-checkout-nonce' => 'wc-nonce' );

$ex = null;
try { make_acp_modal()->ajax_create_payment(); }
catch ( BreezeACPJsonError $e ) { $ex = $e; }

acp_check( $ex instanceof BreezeACPJsonError, 'create_order exception → BreezeACPJsonError thrown' );
acp_check( $ex && false !== strpos( $ex->getMessage(), 'DB connection failed' ), 'create_order exception → exception message forwarded' );

// ------------------------------------------------------------------
// Group 6: create_order() returns WP_Error → json_error
// ------------------------------------------------------------------
echo "\n🧪 ajax_create_payment: create_order() returns WP_Error → json_error\n";
$_mock_nonce_ok    = true;
$_mock_wc_nonce_ok = true;
$wc6               = make_acp_wc( make_acp_pg( new ACP_MockGateway() ) );
$wc6->checkout_obj->create_result = new WP_Error( 'order_failed', 'Checkout validation failed' );
$_mock_wc          = $wc6;
$_POST             = array( 'nonce' => 'test-nonce', 'woocommerce-process-checkout-nonce' => 'wc-nonce' );

$ex = null;
try { make_acp_modal()->ajax_create_payment(); }
catch ( BreezeACPJsonError $e ) { $ex = $e; }

acp_check( $ex instanceof BreezeACPJsonError, 'create_order WP_Error → BreezeACPJsonError thrown' );
acp_check( $ex && false !== strpos( $ex->getMessage(), 'Checkout validation failed' ), 'create_order WP_Error → WP_Error message forwarded' );

// ------------------------------------------------------------------
// Group 7: create_order() returns 0 (falsy) → json_error
// ------------------------------------------------------------------
echo "\n🧪 ajax_create_payment: create_order() returns 0 → json_error\n";
$_mock_nonce_ok    = true;
$_mock_wc_nonce_ok = true;
$wc7               = make_acp_wc( make_acp_pg( new ACP_MockGateway() ) );
$wc7->checkout_obj->create_result = 0;
$_mock_wc          = $wc7;
$_POST             = array( 'nonce' => 'test-nonce', 'woocommerce-process-checkout-nonce' => 'wc-nonce' );

$ex = null;
try { make_acp_modal()->ajax_create_payment(); }
catch ( BreezeACPJsonError $e ) { $ex = $e; }

acp_check( $ex instanceof BreezeACPJsonError, 'create_order 0 → BreezeACPJsonError thrown' );
acp_check( $ex && false !== strpos( $ex->getMessage(), 'Order could not be created' ), 'create_order 0 → message' );

// ------------------------------------------------------------------
// Group 8: wc_get_order() returns false after create → json_error
// ------------------------------------------------------------------
echo "\n🧪 ajax_create_payment: wc_get_order() returns false → json_error\n";
$_mock_nonce_ok      = true;
$_mock_wc_nonce_ok   = true;
$_mock_created_order = false;
$wc8                 = make_acp_wc( make_acp_pg( new ACP_MockGateway() ) );
$wc8->checkout_obj->create_result = 42;
$_mock_wc            = $wc8;
$_POST               = array( 'nonce' => 'test-nonce', 'woocommerce-process-checkout-nonce' => 'wc-nonce' );

$ex = null;
try { make_acp_modal()->ajax_create_payment(); }
catch ( BreezeACPJsonError $e ) { $ex = $e; }

acp_check( $ex instanceof BreezeACPJsonError, 'wc_get_order false → BreezeACPJsonError thrown' );
acp_check( $ex && false !== strpos( $ex->getMessage(), 'Order not found' ), 'wc_get_order false → message' );

// ------------------------------------------------------------------
// Group 9: create_payment_for_order() returns WP_Error → cancel + json_error
// ------------------------------------------------------------------
echo "\n🧪 ajax_create_payment: create_payment_for_order() WP_Error → cancel order + json_error\n";
$_mock_nonce_ok    = true;
$_mock_wc_nonce_ok = true;
$order9            = new Mock_ACP_Order( 99, '' );
$_mock_created_order = $order9;
$gateway9          = new ACP_MockGateway();
$gateway9->create_result = new WP_Error( 'api_fail', 'Breeze API unavailable' );
$wc9               = make_acp_wc( make_acp_pg( $gateway9 ) );
$wc9->checkout_obj->create_result = 99;
$_mock_wc          = $wc9;
$_POST             = array( 'nonce' => 'test-nonce', 'woocommerce-process-checkout-nonce' => 'wc-nonce' );

$ex = null;
try { make_acp_modal()->ajax_create_payment(); }
catch ( BreezeACPJsonError $e ) { $ex = $e; }

acp_check( $ex instanceof BreezeACPJsonError, 'CPO WP_Error → BreezeACPJsonError thrown' );
acp_check(
    count( $order9->status_log ) === 1 && $order9->status_log[0]['status'] === 'cancelled',
    'CPO WP_Error → order cancelled'
);
acp_check( $ex && false !== strpos( $ex->getMessage(), 'Breeze API unavailable' ), 'CPO WP_Error → WP_Error message forwarded' );

// ------------------------------------------------------------------
// Group 10: Success → session set + json_success with all 4 keys
// ------------------------------------------------------------------
echo "\n🧪 ajax_create_payment: success → session set + json_success\n";
$_mock_nonce_ok    = true;
$_mock_wc_nonce_ok = true;
$order10           = new Mock_ACP_Order( 77, 'tok_xyz' );
$_mock_created_order = $order10;
$gateway10         = new ACP_MockGateway();
$gateway10->create_result = array( 'url' => 'https://pay.breeze.cash/checkout/xyz' );
$session10         = new Mock_ACP_Session();
$wc10              = new Mock_ACP_WC(
    new Mock_ACP_Cart( false ),
    $session10,
    new Mock_ACP_Checkout(),
    make_acp_pg( $gateway10 )
);
$wc10->checkout_obj->create_result = 77;
$_mock_wc          = $wc10;
$_POST             = array( 'nonce' => 'test-nonce', 'woocommerce-process-checkout-nonce' => 'wc-nonce' );

$ex = null;
try { make_acp_modal()->ajax_create_payment(); }
catch ( BreezeACPJsonSuccess $e ) { $ex = $e; }

acp_check( $ex instanceof BreezeACPJsonSuccess, 'success → BreezeACPJsonSuccess thrown' );
$data10 = $ex ? $ex->data : array();
acp_check_eq(
    'https://pay.breeze.cash/checkout/xyz',
    isset( $data10['paymentUrl'] ) ? $data10['paymentUrl'] : null,
    'success → paymentUrl'
);
acp_check_eq( 77, isset( $data10['orderId'] ) ? $data10['orderId'] : null, 'success → orderId' );
acp_check(
    isset( $data10['successUrl'] ) && false !== strpos( $data10['successUrl'], 'success' ),
    'success → successUrl contains status=success'
);
acp_check(
    isset( $data10['failUrl'] ) && false !== strpos( $data10['failUrl'], 'failed' ),
    'success → failUrl contains status=failed'
);
acp_check(
    count( $session10->log ) === 1 &&
    $session10->log[0]['key'] === 'breeze_modal_pending_order' &&
    $session10->log[0]['value'] === 77,
    'success → session set with pending order id'
);

// ─── Results ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '-', 50 ) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
