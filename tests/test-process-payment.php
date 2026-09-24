<?php
/**
 * Tests for WC_Breeze_Payment_Gateway::process_payment().
 *
 * Covers:
 *   - wc_get_order() returns null          → failure array + wc_add_notice 'error'
 *   - create_payment_for_order() → WP_Error → failure array + wc_add_notice with message
 *   - create_payment_for_order() → success  → 'success' array with redirect URL
 *
 * Non-subscription routing only (subscription paths covered by test-subscriptions.php).
 *
 * Uses the C1 real-class pattern: subclass overrides create_payment_for_order()
 * so process_payment() can be tested without the HTTP layer.
 *
 * Run: php tests/test-process-payment.php
 */

// ─── Stubs / polyfills ────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        public $id          = '';
        public $description = '';
    }
}
if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {}
}
if ( ! class_exists( 'WC_Log_Levels' ) ) {
    class WC_Log_Levels { const DEBUG = 'debug'; }
}
if ( ! class_exists( 'WC_Admin_Settings' ) ) {
    class WC_Admin_Settings { public static function add_error( $msg ) {} }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
        public $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code()    { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $v ) { return $v instanceof WP_Error; }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'wc_get_logger' ) ) {
    function wc_get_logger() {
        return new class {
            public function error( $m, $c = array() ) {}
            public function debug( $m, $c = array() ) {}
        };
    }
}

// Controllable wc_get_order() stub.
$_pp_test_order = null;
if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $order_id ) {
        global $_pp_test_order;
        return $_pp_test_order;
    }
}

// Call-tracking wc_add_notice() stub.
$_pp_notices = array();
if ( ! function_exists( 'wc_add_notice' ) ) {
    function wc_add_notice( $message, $notice_type = 'success' ) {
        global $_pp_notices;
        $_pp_notices[] = array( 'message' => $message, 'type' => $notice_type );
    }
}

// get_post_meta() always returns '' so no item is treated as a subscription item.
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) { return ''; }
}

// Minimal HTTP stubs (required by the file even though we override create_payment_for_order).
if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = array() ) { return new WP_Error( 'not_used', '' ); }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $r ) { return 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $r ) { return ''; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
}

// ─── Load real gateway class ──────────────────────────────────────────────────

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Testable subclass ────────────────────────────────────────────────────────
// Overrides create_payment_for_order() so process_payment() can be driven
// without the HTTP layer; get_subscription_context() remains the real one.

class PP_TestGateway extends WC_Breeze_Payment_Gateway {
    /** Set this before each call to control what create_payment_for_order returns. */
    public $cpf_return;

    public function create_payment_for_order( $order ) {
        return $this->cpf_return;
    }
}

// ─── Order / item mocks ───────────────────────────────────────────────────────

class PP_Item {
    public function get_product_id() { return 99; }
    public function get_quantity()   { return 1; }
}

// Extends the stub WC_Order so instanceof checks in create_payment_for_order pass
// (the override never uses these, but the real method is still guarded).
class PP_Order extends WC_Order {
    public function get_id()    { return 1; }
    public function get_items() { return array( new PP_Item() ); }
}

// ─── Assert harness ───────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function pp_check( $cond, $label ) {
    global $passed, $failed;
    if ( $cond ) { echo "  ✅ {$label}\n"; $passed++; }
    else         { echo "  ❌ {$label}\n"; $failed++; }
}
function pp_check_eq( $expected, $actual, $label ) {
    pp_check(
        $expected === $actual,
        sprintf( '%s (expected %s, got %s)', $label, var_export( $expected, true ), var_export( $actual, true ) )
    );
}

// ─── Helper ───────────────────────────────────────────────────────────────────

function pp_make_gw() {
    $ref = new ReflectionClass( 'PP_TestGateway' );
    $gw  = $ref->newInstanceWithoutConstructor();
    // Inject the minimum properties process_payment() reads.
    foreach ( array( 'api_base_url' => 'https://api.breeze.cash', 'api_key' => 'sk_test_PP', 'id' => 'breeze_payment_gateway' ) as $name => $val ) {
        $cls = $ref;
        while ( $cls && ! $cls->hasProperty( $name ) ) { $cls = $cls->getParentClass(); }
        if ( $cls ) {
            $p = $cls->getProperty( $name );
            $p->setAccessible( true );
            $p->setValue( $gw, $val );
        }
    }
    return $gw;
}

function pp_reset() {
    global $_pp_notices, $_pp_test_order;
    $_pp_notices    = array();
    $_pp_test_order = null;
}

// ─── Group 1: wc_get_order() returns null → invalid order ────────────────────

echo "\n🧪 Group 1: wc_get_order() returns null → invalid order failure\n";

pp_reset();
$_pp_test_order = null;

$gw1    = pp_make_gw();
$result = $gw1->process_payment( 999 );

pp_check( is_array( $result ), 'result is an array' );
pp_check_eq( 'failure', $result['result'], 'result[result] is failure' );
pp_check( array_key_exists( 'messages', $result ), 'result has messages key' );
pp_check( is_array( $result['messages'] ), 'result[messages] is array' );
pp_check_eq( 'Invalid order.', $result['messages'][0], 'result[messages][0] is "Invalid order."' );
pp_check( count( $_pp_notices ) === 1, 'exactly one notice added' );
pp_check_eq( 'error', $_pp_notices[0]['type'], 'notice type is error' );
pp_check_eq( 'Payment error: invalid order.', $_pp_notices[0]['message'], 'notice message is "Payment error: invalid order."' );

// ─── Group 2: create_payment_for_order() → WP_Error → failure ────────────────

echo "\n🧪 Group 2: create_payment_for_order() returns WP_Error → failure + notice\n";

pp_reset();
$_pp_test_order = new PP_Order();

$gw2          = pp_make_gw();
$gw2->cpf_return = new WP_Error( 'breeze_page_create_failed', 'API unavailable' );

$result2 = $gw2->process_payment( 1 );

pp_check_eq( 'failure', $result2['result'], 'result[result] is failure' );
pp_check( array_key_exists( 'messages', $result2 ), 'result has messages key' );
pp_check( is_array( $result2['messages'] ), 'result[messages] is array' );
pp_check_eq( 'API unavailable', $result2['messages'][0], 'result[messages][0] matches WP_Error message' );
pp_check( count( $_pp_notices ) === 1, 'exactly one notice added' );
pp_check_eq( 'error', $_pp_notices[0]['type'], 'notice type is error' );
pp_check( false !== strpos( $_pp_notices[0]['message'], 'Payment error:' ), 'notice text contains "Payment error:"' );
pp_check( false !== strpos( $_pp_notices[0]['message'], 'API unavailable' ), 'notice text contains WP_Error message' );

// ─── Group 3: create_payment_for_order() → success → redirect ────────────────

echo "\n🧪 Group 3: create_payment_for_order() returns success → redirect result\n";

pp_reset();
$_pp_test_order = new PP_Order();

$gw3             = pp_make_gw();
$gw3->cpf_return = array(
    'url'             => 'https://pay.breeze.cash/p/page_XYZ',
    'id'              => 'page_XYZ',
    'fail_return_url' => 'https://example.test/checkout',
);

$result3 = $gw3->process_payment( 1 );

pp_check_eq( 'success', $result3['result'], 'result[result] is success' );
pp_check( array_key_exists( 'redirect', $result3 ), 'result has redirect key' );
pp_check_eq( 'https://pay.breeze.cash/p/page_XYZ', $result3['redirect'], 'redirect URL matches payment page URL' );
pp_check( ! array_key_exists( 'messages', $result3 ), 'result has no messages key on success' );
pp_check( count( $_pp_notices ) === 0, 'no notices added on success' );

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
