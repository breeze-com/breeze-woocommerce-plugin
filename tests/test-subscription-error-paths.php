<?php
/**
 * Tests for error paths in Breeze subscription checkout.
 *
 * Covers branches not exercised by test-subscriptions.php:
 *   - WC_Breeze_Subscription_Gateway::process_payment() — null order (wc_get_order false)
 *   - resolve_breeze_customer_id() — no email + no user_id → false without API call
 *   - resolve_breeze_customer_id() — API returns 200 but empty id → false
 *   - create_subscription_checkout() — customer resolve fails → failure + notice
 *   - create_subscription_checkout() — subscription POST fails (non-2xx) → failure + notice
 *   - create_subscription_checkout() — subscription POST returns no url → failure + notice
 *
 * C1 real-class pattern: ReflectionClass::newInstanceWithoutConstructor(),
 * ReflectionMethod for protected/private methods, queue-based HTTP stub.
 *
 * Run: php tests/test-subscription-error-paths.php
 */

// ─── Stubs / polyfills ────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        public $id = '';
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
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $s, $remove_breaks = false ) {
        $s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $s );
        $s = strip_tags( $s );
        if ( $remove_breaks ) {
            $s = preg_replace( '/[\r\n\t ]+/', ' ', $s );
        }
        return trim( $s );
    }
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( $id ) {
        return $id > 0 ? "https://img.example.test/{$id}.jpg" : false;
    }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $length = 12, $special = true, $extra = true ) {
        return substr( str_repeat( 'a', max( (int) $length, 1 ) ), 0, (int) $length );
    }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '/' ) { return 'https://example.test' . $path; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg() {
        $argv = func_get_args();
        if ( is_array( $argv[0] ) ) {
            list( $params, $url ) = $argv;
            $sep = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
            return rtrim( $url, '?' ) . $sep . http_build_query( $params );
        }
        list( $key, $value, $url ) = $argv;
        $sep = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
        return rtrim( $url, '?' ) . $sep . urlencode( $key ) . '=' . urlencode( $value );
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) { return strip_tags( trim( (string) $s ) ); }
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
            public function info( $m, $c = array() ) {}
        };
    }
}

// ─── Test-specific controlled stubs ───────────────────────────────────────────

$sep_notices    = array();
$sep_order_stub = false;   // controlled return value for wc_get_order()
$sep_post_meta  = array(); // controlled store for get_post_meta()
$sep_http_log   = array();
$sep_http_queue = array();

if ( ! function_exists( 'wc_add_notice' ) ) {
    function wc_add_notice( $message, $type = 'success' ) {
        global $sep_notices;
        $sep_notices[] = array( 'message' => $message, 'type' => $type );
    }
}
if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $id ) {
        global $sep_order_stub;
        return $sep_order_stub;
    }
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) {
        global $sep_post_meta;
        $val = isset( $sep_post_meta[ $post_id ][ $key ] ) ? $sep_post_meta[ $post_id ][ $key ] : '';
        return $single ? $val : array( $val );
    }
}
if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = array() ) {
        global $sep_http_log, $sep_http_queue;
        $sep_http_log[] = array( 'url' => $url, 'args' => $args );
        $resp = array_shift( $sep_http_queue );
        if ( null === $resp || false === $resp ) {
            return new WP_Error( 'transport_error', 'mock queue exhausted' );
        }
        return array( '_code' => $resp['code'], '_body' => $resp['body'] );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $r ) {
        return isset( $r['_code'] ) ? $r['_code'] : 0;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $r ) {
        return isset( $r['_body'] ) ? $r['_body'] : '';
    }
}

// ─── Load real gateway classes ─────────────────────────────────────────────────

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';
require_once __DIR__ . '/../includes/class-wc-breeze-subscription-gateway.php';

// ─── Mock order + item ─────────────────────────────────────────────────────────

class SEP_Item {
    private $product_ref;
    private $quantity;
    public function __construct( $product_ref, $quantity = 1 ) {
        $this->product_ref = $product_ref;
        $this->quantity    = $quantity;
    }
    public function get_product_id() { return $this->product_ref; }
    public function get_quantity()   { return $this->quantity; }
}

class SEP_Order extends WC_Order {
    private $id;
    private $email;
    private $customer_id;
    private $first_name;
    private $last_name;
    private $items;
    private $meta      = array();
    public  $save_count = 0;
    public  $status_log = array();

    public function __construct( $id, array $args = array() ) {
        $this->id          = $id;
        $this->email       = isset( $args['email'] ) ? $args['email'] : '';
        $this->customer_id = isset( $args['customer_id'] ) ? $args['customer_id'] : 0;
        $this->first_name  = isset( $args['first_name'] ) ? $args['first_name'] : 'Jane';
        $this->last_name   = isset( $args['last_name'] ) ? $args['last_name'] : 'Doe';
        $this->items       = isset( $args['items'] ) ? $args['items'] : array();
    }
    public function get_id()                  { return $this->id; }
    public function get_items()               { return $this->items; }
    public function get_billing_email()       { return $this->email; }
    public function get_customer_id()         { return $this->customer_id; }
    public function get_billing_first_name()  { return $this->first_name; }
    public function get_billing_last_name()   { return $this->last_name; }
    public function get_currency()            { return 'USD'; }
    public function get_shipping_total()      { return '0'; }
    public function get_shipping_method()     { return ''; }
    public function get_total_tax()           { return '0'; }
    public function get_meta( $key )          { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function save()                    { $this->save_count++; }
    public function update_status( $status, $note = '' ) { $this->status_log[] = $status; }
    public function add_order_note( $note )   {}
}

// ─── Assert harness ────────────────────────────────────────────────────────────

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

// ─── Helpers ──────────────────────────────────────────────────────────────────

function sep_reset() {
    global $sep_notices, $sep_order_stub, $sep_post_meta, $sep_http_log, $sep_http_queue;
    $sep_notices    = array();
    $sep_order_stub = false;
    $sep_post_meta  = array();
    $sep_http_log   = array();
    $sep_http_queue = array();
}

function sep_push_http( $code, $body ) {
    global $sep_http_queue;
    $sep_http_queue[] = array( 'code' => $code, 'body' => $body );
}

function sep_make_gw( array $overrides = array() ) {
    $ref      = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gw       = $ref->newInstanceWithoutConstructor();
    $defaults = array(
        'api_base_url' => 'https://api.breeze.cash',
        'api_key'      => 'sk_test_SEP',
        'id'           => 'breeze_payment_gateway',
        'debug'        => false,
        'log'          => null,
    );
    $props = array_merge( $defaults, $overrides );
    foreach ( $props as $name => $val ) {
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

function sep_make_sub_gw() {
    $ref = new ReflectionClass( 'WC_Breeze_Subscription_Gateway' );
    $gw  = $ref->newInstanceWithoutConstructor();
    // Inject the gateway id; process_payment() null-order branch needs no other props.
    $cls = $ref;
    while ( $cls && ! $cls->hasProperty( 'id' ) ) { $cls = $cls->getParentClass(); }
    if ( $cls ) {
        $p = $cls->getProperty( 'id' );
        $p->setAccessible( true );
        $p->setValue( $gw, 'breeze_subscription_gateway' );
    }
    return $gw;
}

// Walk up the class hierarchy to invoke a protected or private method.
function sep_invoke( $instance, $method_name, array $args = array() ) {
    $cls = new ReflectionClass( get_class( $instance ) );
    while ( $cls && ! $cls->hasMethod( $method_name ) ) {
        $cls = $cls->getParentClass();
    }
    if ( ! $cls ) {
        throw new RuntimeException( "Method {$method_name} not found on " . get_class( $instance ) );
    }
    $method = $cls->getMethod( $method_name );
    $method->setAccessible( true );
    return $method->invokeArgs( $instance, $args );
}

// Register a subscription product in the post-meta stub (product_ref 201 by default).
function sep_register_sub_product() {
    global $sep_post_meta;
    $sep_post_meta[201]['_breeze_price_id']   = 'price_TEST';
    $sep_post_meta[201]['_breeze_product_id'] = 'prod_TEST';
}

// Build a valid single-subscription order (one sub item on product 201).
function sep_sub_order( $id, array $args = array() ) {
    $defaults = array(
        'email'    => 'buyer@sub.example',
        'items'    => array( new SEP_Item( 201 ) ),
    );
    return new SEP_Order( $id, array_merge( $defaults, $args ) );
}

// ─── Group 1: WC_Breeze_Subscription_Gateway::process_payment() — null order ──

echo "\n🧪 Group 1: process_payment() — wc_get_order returns false → failure + notice\n";

sep_reset();
// $sep_order_stub is false; wc_get_order(999) returns false for any id.
$sub_gw  = sep_make_sub_gw();
$result1 = $sub_gw->process_payment( 999 );

check_eq( 'failure', $result1['result'], 'result is failure' );
check( ! empty( $result1['messages'] ), 'messages array is non-empty' );
check( false !== strpos( $result1['messages'][0], 'Invalid order' ), 'messages[0] contains "Invalid order"' );
check( ! empty( $sep_notices ) && 'error' === $sep_notices[0]['type'], 'wc_add_notice called with type error' );

// ─── Group 2: resolve_breeze_customer_id() — no email + no user_id → false ────

echo "\n🧪 Group 2: resolve_breeze_customer_id() — no email + no user_id → false, no HTTP call\n";

sep_reset();
$gw2    = sep_make_gw();
$order2 = new SEP_Order( 2, array( 'email' => '', 'customer_id' => 0 ) );
$res2   = sep_invoke( $gw2, 'resolve_breeze_customer_id', array( $order2 ) );

check( false === $res2, 'returns false when order has no email and no customer_id' );
check_eq( 0, count( $sep_http_log ), 'no HTTP request made (early return before API call)' );

// ─── Group 3: resolve_breeze_customer_id() — API returns 200 with empty id ────

echo "\n🧪 Group 3: resolve_breeze_customer_id() — API returns 200 but empty id → false\n";

sep_reset();
sep_push_http( 200, '{"id":""}' );
$gw3    = sep_make_gw();
$order3 = new SEP_Order( 3, array( 'email' => 'guest@example.com', 'customer_id' => 0 ) );
$res3   = sep_invoke( $gw3, 'resolve_breeze_customer_id', array( $order3 ) );

check( false === $res3, 'returns false when API id field is empty string' );
check_eq( 1, count( $sep_http_log ), 'exactly one HTTP request was made' );
check( false !== strpos( $sep_http_log[0]['url'], '/v1/customers' ), 'request was to /v1/customers' );

// ─── Group 4: create_subscription_checkout() — customer resolve fails ─────────

echo "\n🧪 Group 4: create_subscription_checkout() — no email/customer_id → customer fails → failure\n";

sep_reset();
sep_register_sub_product();
$gw4 = sep_make_gw();
// Order passes get_subscription_context() (has sub product 201) but has no email/user →
// resolve_breeze_customer_id() returns false immediately without an API call.
$order4 = sep_sub_order( 4, array( 'email' => '', 'customer_id' => 0 ) );
$res4   = sep_invoke( $gw4, 'create_subscription_checkout', array( $order4 ) );

check_eq( 'failure', $res4['result'], 'result is failure when customer cannot be resolved' );
check( ! empty( $res4['messages'] ), 'messages array is non-empty' );
check( ! empty( $sep_notices ), 'wc_add_notice was called' );
check_eq( 'error', $sep_notices[0]['type'], 'notice type is error' );

// ─── Group 5: create_subscription_checkout() — subscription POST fails (500) ──

echo "\n🧪 Group 5: create_subscription_checkout() — customer OK, /v1/subscriptions returns 500\n";

sep_reset();
sep_register_sub_product();
// Queue: customer upsert succeeds, subscription POST fails.
sep_push_http( 200, '{"id":"cus_OK"}' );
sep_push_http( 500, '{"error":"server_error"}' );
$gw5    = sep_make_gw();
$order5 = sep_sub_order( 5 );
$res5   = sep_invoke( $gw5, 'create_subscription_checkout', array( $order5 ) );

check_eq( 'failure', $res5['result'], 'result is failure when subscription POST returns 500' );
check( ! empty( $res5['messages'] ), 'messages array is non-empty' );
check( ! empty( $sep_notices ), 'wc_add_notice was called' );
$sub_calls = array_filter( $sep_http_log, function ( $e ) {
    return false !== strpos( $e['url'], '/v1/subscriptions' );
} );
check( ! empty( $sub_calls ), '/v1/subscriptions was called (customer step did not short-circuit)' );

// ─── Group 6: create_subscription_checkout() — subscription POST returns no url

echo "\n🧪 Group 6: create_subscription_checkout() — subscription POST returns 200 but no url\n";

sep_reset();
sep_register_sub_product();
// Queue: customer upsert succeeds, subscription returns 200 with id but no url.
sep_push_http( 200, '{"id":"cus_OK"}' );
sep_push_http( 200, '{"id":"sub_X"}' );
$gw6    = sep_make_gw();
$order6 = sep_sub_order( 6 );
$res6   = sep_invoke( $gw6, 'create_subscription_checkout', array( $order6 ) );

check_eq( 'failure', $res6['result'], 'result is failure when subscription response has no url' );
check( ! empty( $res6['messages'] ), 'messages array is non-empty' );
check( ! empty( $sep_notices ), 'wc_add_notice was called' );

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
