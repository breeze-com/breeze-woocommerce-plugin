<?php
/**
 * Tests for process_payment() routing in WC_Breeze_Payment_Gateway.
 *
 * Covers the four routing branches of process_payment():
 *   - Invalid order ID (wc_get_order returns false) → failure + error notice
 *   - Non-subscription order with no valid line items → WP_Error from
 *     create_payment_for_order() → failure + error notice
 *   - Non-subscription order + successful API call → success result with
 *     redirect URL; order updated to pending and page-ID stored
 *   - Non-subscription order + API failure on /v1/payment_pages → failure + notice
 *
 * Loads the REAL WC_Breeze_Payment_Gateway class via ReflectionClass.
 * Uses a URL-keyed wp_remote_request() stub so GET /v1/customers and
 * POST /v1/payment_pages return distinct canned responses.
 *
 * Run: php tests/test-process-payment.php
 */

// ─── Polyfills / stubs ────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    // Handles the array form used by create_breeze_payment_page().
    function add_query_arg( $args, $url = '' ) {
        if ( is_array( $args ) ) {
            $sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
            return $url . $sep . http_build_query( $args );
        }
        // Scalar (key, value, url) form.
        $a = func_get_args();
        $u = isset( $a[2] ) ? (string) $a[2] : '';
        $sep = ( false === strpos( $u, '?' ) ) ? '?' : '&';
        return $u . $sep . http_build_query( array( $a[0] => $a[1] ) );
    }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '/' ) { return 'https://example.test' . $path; }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $length = 12, $special = true, $extra = true ) {
        return str_repeat( 'x', (int) $length );
    }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( $id ) {
        return ( $id > 0 ) ? 'https://img.example.test/' . $id . '.jpg' : false;
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
}
if ( ! function_exists( 'get_post_meta' ) ) {
    // Non-subscription products: no Breeze price/product ID.
    function get_post_meta( $post_id, $key, $single = false ) { return $single ? '' : array( '' ); }
}
if ( ! function_exists( 'wc_add_notice' ) ) {
    function wc_add_notice( $message, $type = 'success' ) {
        global $_tpp_notices;
        $_tpp_notices[] = array( 'message' => $message, 'type' => $type );
    }
}
if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $id ) {
        global $_tpp_orders;
        return isset( $_tpp_orders[ $id ] ) ? $_tpp_orders[ $id ] : false;
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $v ) { return $v instanceof WP_Error; }
}
if ( ! function_exists( 'wc_get_logger' ) ) {
    function wc_get_logger() {
        return new class {
            public function debug() {}
            public function info() {}
            public function warning() {}
            public function error() {}
        };
    }
}
if ( ! function_exists( 'wp_remote_request' ) ) {
    // URL-keyed stub: matches the first registered fragment found in the URL.
    function wp_remote_request( $url, $args = array() ) {
        global $_tpp_responses, $_tpp_default_response, $_tpp_http_log;
        $_tpp_http_log[] = array(
            'url'    => $url,
            'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
        );
        foreach ( $_tpp_responses as $fragment => $resp ) {
            if ( false !== strpos( $url, $fragment ) ) {
                return $resp;
            }
        }
        return $_tpp_default_response;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return isset( $response['code'] ) ? (int) $response['code'] : 200;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        return isset( $response['body'] ) ? $response['body'] : '{}';
    }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
        private $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
    }
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        public $id                 = '';
        public $title              = '';
        public $description        = '';
        public $enabled            = 'yes';
        public $supports           = array();
        public $has_fields         = false;
        public $icon               = '';
        public $method_title       = '';
        public $method_description = '';
        protected $settings        = array();
        public function get_option( $key, $default = '' ) {
            return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
        }
        public function init_settings() {}
        public function init_form_fields() {}
    }
}
// Bare WC_Order class; MockTPPOrder extends it so instanceof checks pass.
if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {}
}
if ( ! class_exists( 'WC_Log_Levels' ) ) {
    class WC_Log_Levels { const DEBUG = 'debug'; }
}
if ( ! class_exists( 'WC_Admin_Settings' ) ) {
    class WC_Admin_Settings { public static function add_error( $msg ) {} }
}
if ( ! defined( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_URL' ) ) {
    define( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_URL', 'https://example.test/plugin/' );
}
if ( ! function_exists( 'esc_url' ) )    { function esc_url( $u ) { return htmlspecialchars( $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) )   { function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'wpautop' ) )    { function wpautop( $s ) { return $s; } }

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Mock order / item / product ─────────────────────────────────────────────

/**
 * Extends WC_Order so the instanceof guard in create_payment_for_order() passes.
 */
class MockTPPOrder extends WC_Order {
    private $id;
    private $meta            = array();
    private $notes           = array();
    private $status          = 'pending';
    private $items           = array();
    private $billing_email   = 'buyer@example.test';
    private $billing_first   = 'Jane';
    private $billing_last    = 'Doe';
    private $user_id         = 0;
    private $currency        = 'USD';
    private $shipping_total  = 0.0;
    private $shipping_method = '';
    private $total_tax       = 0.0;

    public function __construct( $id ) { $this->id = $id; }
    public function get_id()                { return $this->id; }
    public function get_billing_email()     { return $this->billing_email; }
    public function get_billing_first_name(){ return $this->billing_first; }
    public function get_billing_last_name() { return $this->billing_last; }
    public function get_user_id()           { return $this->user_id; }
    public function get_customer_id()       { return $this->user_id; }
    public function get_currency()          { return $this->currency; }
    public function get_shipping_total()    { return $this->shipping_total; }
    public function get_shipping_method()   { return $this->shipping_method; }
    public function get_total_tax()         { return $this->total_tax; }
    public function get_meta( $key ) {
        return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
    }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function save() {}
    public function add_order_note( $note ) { $this->notes[] = $note; }
    public function update_status( $status, $note = '' ) {
        $this->status = $status;
        if ( $note ) { $this->notes[] = $note; }
    }
    public function get_status()  { return $this->status; }
    public function get_items()   { return $this->items; }
    public function add_item( $item ) { $this->items[] = $item; }
}

class MockTPPOrderItem {
    private $product_id;
    private $product;
    private $qty;
    private $total;
    private $name;
    public function __construct( $name, $qty, $total, $product_id, $product = null ) {
        $this->name       = $name;
        $this->qty        = $qty;
        $this->total      = $total;
        $this->product_id = $product_id;
        $this->product    = $product;
    }
    public function get_product_id() { return $this->product_id; }
    public function get_product()    { return $this->product; }
    public function get_quantity()   { return $this->qty; }
    public function get_total()      { return $this->total; }
    public function get_name()       { return $this->name; }
}

class MockTPPProduct {
    private $id;
    public function __construct( $id = 1 ) { $this->id = $id; }
    public function get_id()                { return $this->id; }
    public function get_short_description() { return ''; }
    public function get_image_id()          { return 0; }
}

// ─── Test harness ─────────────────────────────────────────────────────────────

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

// ─── Globals ─────────────────────────────────────────────────────────────────

$_tpp_notices          = array();
$_tpp_orders           = array();
$_tpp_responses        = array();
$_tpp_default_response = array( 'code' => 200, 'body' => '{}' );
$_tpp_http_log         = array();

function reset_tpp_state() {
    global $_tpp_notices, $_tpp_orders, $_tpp_responses, $_tpp_http_log;
    $_tpp_notices   = array();
    $_tpp_orders    = array();
    $_tpp_responses = array();
    $_tpp_http_log  = array();
}

function make_tpp_gateway( array $overrides = array() ) {
    $defaults = array(
        'api_base_url'               => 'https://api.breeze.cash',
        'api_key'                    => 'sk_test_key',
        'debug'                      => false,
        'log'                        => null,
        'payment_methods'            => array(),
        'crypto_network'             => '',
        'crypto_token'               => '',
        'merchant_calculated_tax'    => false,
        'flexible_amount_max'        => '',
        'flexible_amount_percentage' => '',
        'flexible_amount_fixed'      => '',
        'id'                         => 'breeze_payment_gateway',
        'send_product_description'   => false,
        'checkout_display'           => 'redirect',
    );
    $props = array_merge( $defaults, $overrides );
    $gw    = ( new ReflectionClass( 'WC_Breeze_Payment_Gateway' ) )->newInstanceWithoutConstructor();
    $ref   = new ReflectionClass( $gw );
    foreach ( $props as $prop => $val ) {
        $cls = $ref;
        while ( $cls && ! $cls->hasProperty( $prop ) ) {
            $cls = $cls->getParentClass();
        }
        if ( $cls ) {
            $p = $cls->getProperty( $prop );
            $p->setAccessible( true );
            $p->setValue( $gw, $val );
        }
    }
    return $gw;
}

function make_tpp_order( $id, $with_item = false ) {
    global $_tpp_orders;
    $order = new MockTPPOrder( $id );
    if ( $with_item ) {
        $product = new MockTPPProduct( 42 );
        $order->add_item( new MockTPPOrderItem( 'Widget', 1, 10.00, 42, $product ) );
    }
    $_tpp_orders[ $id ] = $order;
    return $order;
}

function set_tpp_payment_page_responses( $page_id = 'pp_1', $page_url = 'https://pay.breeze.cash/p/pp_1' ) {
    global $_tpp_responses;
    // Customer lookup — no existing customer; inline-creation path fires.
    $_tpp_responses['/v1/customers'] = array( 'code' => 200, 'body' => '{}' );
    // Payment page creation — happy path.
    $_tpp_responses['/v1/payment_pages'] = array(
        'code' => 200,
        'body' => json_encode( array( 'data' => array( 'id' => $page_id, 'url' => $page_url ) ) ),
    );
}

// ─── Test 1: Invalid order ID → failure + error notice ───────────────────────

echo "\n🧪 Test 1: process_payment() — invalid order ID (wc_get_order returns false)\n";

reset_tpp_state();
// Nothing registered in $_tpp_orders for id 9999.
$result1 = make_tpp_gateway()->process_payment( 9999 );

check_eq( 'failure', $result1['result'], 'result is failure' );
check( ! empty( $_tpp_notices ), 'error notice was added' );
check_eq( 'error', $_tpp_notices[0]['type'], 'notice type is error' );
check( false !== strpos( $_tpp_notices[0]['message'], 'Invalid order' ), 'notice mentions "Invalid order"' );

// ─── Test 2: No line items → WP_Error from create_payment_for_order → failure ─

echo "\n🧪 Test 2: process_payment() — order with no line items → failure + error notice\n";

reset_tpp_state();
// Order exists but has no items — build_line_items() returns [], triggering WP_Error.
make_tpp_order( 10, false );
$result2 = make_tpp_gateway()->process_payment( 10 );

check_eq( 'failure', $result2['result'], 'WP_Error path: result is failure' );
check( ! empty( $_tpp_notices ), 'WP_Error path: notice was added' );
check_eq( 'error', $_tpp_notices[0]['type'], 'WP_Error path: notice type is error' );

// ─── Test 3: Valid order + API success → success + redirect URL ───────────────

echo "\n🧪 Test 3: process_payment() — API success → success result with redirect URL\n";

reset_tpp_state();
$order3 = make_tpp_order( 20, true ); // order with one £10 line item
set_tpp_payment_page_responses( 'pp_happy', 'https://pay.breeze.cash/p/pp_happy' );

$result3 = make_tpp_gateway()->process_payment( 20 );

check_eq( 'success', $result3['result'], 'API success: result is success' );
check( isset( $result3['redirect'] ), 'API success: redirect key is present' );
check( false !== strpos( $result3['redirect'], 'pay.breeze.cash' ), 'API success: redirect is a Breeze URL' );
check_eq( 'pending', $order3->get_status(), 'API success: order status set to pending' );
check_eq( 'pp_happy', $order3->get_meta( '_breeze_payment_page_id' ), 'API success: payment page id stored on order' );

// ─── Test 4: Valid order + API failure → failure + error notice ───────────────

echo "\n🧪 Test 4: process_payment() — /v1/payment_pages 500 → failure + error notice\n";

reset_tpp_state();
make_tpp_order( 30, true );
global $_tpp_responses;
$_tpp_responses['/v1/customers']     = array( 'code' => 200, 'body' => '{}' );
$_tpp_responses['/v1/payment_pages'] = array( 'code' => 500, 'body' => '{"error":"internal server error"}' );

$result4 = make_tpp_gateway()->process_payment( 30 );

check_eq( 'failure', $result4['result'], 'API failure: result is failure' );
check( ! empty( $_tpp_notices ), 'API failure: notice was added' );
check_eq( 'error', $_tpp_notices[0]['type'], 'API failure: notice type is error' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
