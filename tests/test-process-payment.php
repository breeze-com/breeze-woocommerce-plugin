<?php
/**
 * Tests for process_payment() — the WooCommerce payment routing entry point.
 *
 * Covers:
 *   - Invalid order (wc_get_order returns false)
 *   - Subscription routing — four error paths: mixed cart, multiple sub items,
 *     qty > 1, missing Breeze product ID
 *   - Subscription routing — success path (delegates to create_subscription_checkout)
 *   - Non-subscription — WP_Error from create_payment_for_order
 *   - Non-subscription — success result with redirect URL
 *
 * Uses a testable subclass to stub create_payment_for_order() and
 * create_subscription_checkout() so only the routing logic in process_payment()
 * is under test. The subscription context is driven by get_post_meta() stubs
 * (controls which cart items are treated as subscription products).
 *
 * Run: php tests/test-process-payment.php
 */

// ─── Stubs / polyfills needed to load the real class standalone ───────────────

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

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code()    { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

// wc_get_order() — tests set $wc_order_stub before each scenario.
$wc_order_stub = false;
if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $id ) {
        global $wc_order_stub;
        return $wc_order_stub;
    }
}

// wc_add_notice() — captured so tests can assert type and message.
$wc_notices = array();
if ( ! function_exists( 'wc_add_notice' ) ) {
    function wc_add_notice( $message, $notice_type = 'success' ) {
        global $wc_notices;
        $wc_notices[] = array( 'message' => $message, 'type' => $notice_type );
    }
}

// get_post_meta() — driven by a global map: "$post_id:$meta_key" => value.
$post_meta_map = array();
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) {
        global $post_meta_map;
        $k = "{$post_id}:{$key}";
        return isset( $post_meta_map[ $k ] ) ? $post_meta_map[ $k ] : '';
    }
}

// HTTP stubs — required by breeze_api_request() loaded with the real class.
if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = array() ) {
        return array( '_code' => 200, '_body' => '{}' );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return isset( $response['_code'] ) ? $response['_code'] : 0;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        return isset( $response['_body'] ) ? $response['_body'] : '';
    }
}
if ( ! function_exists( 'wc_get_logger' ) ) {
    function wc_get_logger() {
        return new class {
            public function error( $msg, $ctx = array() ) {}
            public function warning( $msg, $ctx = array() ) {}
            public function debug( $msg, $ctx = array() ) {}
        };
    }
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Testable subclass ────────────────────────────────────────────────────────
//
// Overrides create_payment_for_order() and create_subscription_checkout() so
// process_payment()'s routing logic can be tested in isolation.

class Testable_Process_Payment_Gateway extends WC_Breeze_Payment_Gateway {
    public $cpfo_return = null;
    public $csc_return  = null;
    public $csc_calls   = 0;

    public function create_payment_for_order( $order ) {
        return $this->cpfo_return;
    }

    protected function create_subscription_checkout( $order ) {
        $this->csc_calls++;
        return $this->csc_return;
    }
}

// ─── Order / item stubs ───────────────────────────────────────────────────────

class PP_Item_Stub {
    private $product_id;
    private $qty;
    public function __construct( $product_id, $qty = 1 ) {
        $this->product_id = $product_id;
        $this->qty        = $qty;
    }
    public function get_product_id() { return $this->product_id; }
    public function get_quantity()   { return $this->qty; }
}

class PP_Order_Stub {
    private $id;
    private $items;
    public function __construct( $id, array $items = array() ) {
        $this->id    = $id;
        $this->items = $items;
    }
    public function get_id()    { return $this->id; }
    public function get_items() { return $this->items; }
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

function make_pp_gateway() {
    $ref = new ReflectionClass( 'Testable_Process_Payment_Gateway' );
    return $ref->newInstanceWithoutConstructor();
}

function reset_pp_state() {
    global $wc_order_stub, $wc_notices, $post_meta_map;
    $wc_order_stub  = false;
    $wc_notices     = array();
    $post_meta_map  = array();
}

// ─── Group 1: invalid order ───────────────────────────────────────────────────

echo "\n🧪 process_payment() — invalid order\n";

reset_pp_state();
$gw     = make_pp_gateway();
$result = $gw->process_payment( 999 );

check_eq( 'failure', $result['result'],         'Invalid order → result = failure' );
check_eq( 'Invalid order.', $result['messages'][0], 'Invalid order → correct message' );
check_eq( 1, count( $wc_notices ),              'Invalid order → one wc_add_notice call' );
check_eq( 'error', $wc_notices[0]['type'],      'Invalid order → notice type = error' );

// ─── Group 2: subscription routing — error paths ─────────────────────────────

echo "\n🧪 process_payment() — subscription routing errors\n";

// 2a. Mixed cart: one subscription item + one regular item.
reset_pp_state();
$post_meta_map['10:_breeze_price_id']    = 'price_abc';
$post_meta_map['10:_breeze_product_id']  = 'prod_abc';
// product 11 has no _breeze_price_id → treated as regular item
$gw = make_pp_gateway();
$wc_order_stub = new PP_Order_Stub( 1, array(
    new PP_Item_Stub( 10 ),
    new PP_Item_Stub( 11 ),
) );
$result = $gw->process_payment( 1 );

check_eq( 'failure', $result['result'],     'Mixed cart → result = failure' );
check(
    false !== strpos( $result['messages'][0], 'Subscription items must be purchased separately' ),
    'Mixed cart → error message mentions "separately"'
);
check_eq( 1, count( $wc_notices ), 'Mixed cart → wc_add_notice called' );

// 2b. Multiple distinct subscription products.
reset_pp_state();
$post_meta_map['20:_breeze_price_id']   = 'price_x';
$post_meta_map['20:_breeze_product_id'] = 'prod_x';
$post_meta_map['21:_breeze_price_id']   = 'price_y';
$post_meta_map['21:_breeze_product_id'] = 'prod_y';
$gw = make_pp_gateway();
$wc_order_stub = new PP_Order_Stub( 2, array(
    new PP_Item_Stub( 20 ),
    new PP_Item_Stub( 21 ),
) );
$result = $gw->process_payment( 2 );

check_eq( 'failure', $result['result'], 'Multiple sub products → result = failure' );
check(
    false !== strpos( $result['messages'][0], 'Only one subscription item' ),
    'Multiple sub products → error message mentions "Only one subscription item"'
);

// 2c. Single subscription product with quantity > 1.
reset_pp_state();
$post_meta_map['30:_breeze_price_id']   = 'price_z';
$post_meta_map['30:_breeze_product_id'] = 'prod_z';
$gw = make_pp_gateway();
$wc_order_stub = new PP_Order_Stub( 3, array(
    new PP_Item_Stub( 30, 2 ),
) );
$result = $gw->process_payment( 3 );

check_eq( 'failure', $result['result'], 'Sub qty > 1 → result = failure' );
check(
    false !== strpos( $result['messages'][0], 'Only one subscription item' ),
    'Sub qty > 1 → error message mentions "Only one subscription item"'
);

// 2d. Subscription item missing _breeze_product_id (misconfigured product).
reset_pp_state();
$post_meta_map['40:_breeze_price_id'] = 'price_q';
// no _breeze_product_id for product 40
$gw = make_pp_gateway();
$wc_order_stub = new PP_Order_Stub( 4, array(
    new PP_Item_Stub( 40 ),
) );
$result = $gw->process_payment( 4 );

check_eq( 'failure', $result['result'], 'Missing product_id → result = failure' );
check(
    false !== strpos( $result['messages'][0], 'misconfigured' ),
    'Missing product_id → error message mentions "misconfigured"'
);

// ─── Group 3: subscription routing — success (delegates to create_subscription_checkout) ──

echo "\n🧪 process_payment() — subscription routing success\n";

reset_pp_state();
$post_meta_map['50:_breeze_price_id']   = 'price_sub';
$post_meta_map['50:_breeze_product_id'] = 'prod_sub';
$gw = make_pp_gateway();
$gw->csc_return = array(
    'result'   => 'success',
    'redirect' => 'https://checkout.breeze.cash/sub/abc',
);
$wc_order_stub = new PP_Order_Stub( 5, array(
    new PP_Item_Stub( 50 ),
) );
$result = $gw->process_payment( 5 );

check_eq( 1, $gw->csc_calls, 'Valid subscription → create_subscription_checkout called once' );
check_eq( 'success', $result['result'],   'Valid subscription → result = success' );
check_eq(
    'https://checkout.breeze.cash/sub/abc',
    $result['redirect'],
    'Valid subscription → redirect URL from create_subscription_checkout'
);

// ─── Group 4: non-subscription — WP_Error from create_payment_for_order ──────

echo "\n🧪 process_payment() — non-subscription WP_Error\n";

reset_pp_state();
$gw = make_pp_gateway();
$gw->cpfo_return = new WP_Error( 'api_failure', 'Breeze API unavailable' );
// No subscription items → get_items returns regular items with no price_id.
$wc_order_stub = new PP_Order_Stub( 6, array(
    new PP_Item_Stub( 99 ),
) );
$result = $gw->process_payment( 6 );

check_eq( 'failure', $result['result'],                  'WP_Error → result = failure' );
check_eq( 'Breeze API unavailable', $result['messages'][0], 'WP_Error → message from WP_Error' );
check_eq( 1, count( $wc_notices ),                       'WP_Error → wc_add_notice called' );
check_eq( 'error', $wc_notices[0]['type'],               'WP_Error → notice type = error' );

// ─── Group 5: non-subscription — success ─────────────────────────────────────

echo "\n🧪 process_payment() — non-subscription success\n";

reset_pp_state();
$gw = make_pp_gateway();
$gw->cpfo_return = array(
    'url'             => 'https://checkout.breeze.cash/pp/xyz',
    'id'              => 'pp_xyz',
    'fail_return_url' => 'https://example.com/checkout',
);
$wc_order_stub = new PP_Order_Stub( 7, array(
    new PP_Item_Stub( 98 ),
) );
$result = $gw->process_payment( 7 );

check_eq( 'success', $result['result'],                      'Success → result = success' );
check_eq( 'https://checkout.breeze.cash/pp/xyz', $result['redirect'], 'Success → redirect = payment page URL' );
check_eq( 0, count( $wc_notices ),                           'Success → no wc_add_notice call' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
