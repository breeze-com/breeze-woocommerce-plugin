<?php
/**
 * Tests for WC_Breeze_Payment_Gateway::create_payment_for_order().
 *
 * Covers:
 *   - Non-WC_Order input                → WP_Error 'invalid_order'
 *   - WC_Order with no items            → WP_Error 'no_line_items'
 *   - POST /payment_pages API failure   → WP_Error 'breeze_page_create_failed'
 *   - Success with existing customer    → array (url/id/fail_return_url), meta + status
 *   - Success with new guest customer   → inline customer payload (referenceId/email)
 *   - payment_methods configured        → preferred_payment_methods param appended to url
 *   - Return token stored single + list (_breeze_return_token, _breeze_return_tokens)
 *   - Payment page id stored single + list (_breeze_payment_page_id, _breeze_payment_page_ids)
 *   - update_status('pending') called on success
 *
 * Uses the C1 real-class pattern: ReflectionClass::newInstanceWithoutConstructor(),
 * property injection via reflection, queue-based HTTP stub, direct public method call.
 *
 * Run: php tests/test-create-payment-for-order.php
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
// Minimal WC_Order base so instanceof checks in create_payment_for_order() pass.
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

// ─── Queue-based HTTP mock ────────────────────────────────────────────────────
// Each test group resets the queue. Each create_payment_for_order() success
// consumes two entries: GET /customers then POST /payment_pages.

$http_log   = array();
$http_queue = array();

if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = array() ) {
        global $http_log, $http_queue;
        $http_log[] = array( 'url' => $url, 'args' => $args );
        $resp = array_shift( $http_queue );
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

// ─── Load real gateway class ──────────────────────────────────────────────────

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Order / item / product mocks ─────────────────────────────────────────────

class CPFProduct {
    private $id;
    public function __construct( $id ) { $this->id = $id; }
    public function get_id()               { return $this->id; }
    public function get_short_description() { return ''; }
    public function get_image_id()         { return 0; }
}

class CPFItem {
    private $product;
    private $quantity;
    private $total;
    private $name;
    public function __construct( $product, $quantity, $total, $name = 'Widget' ) {
        $this->product  = $product;
        $this->quantity = $quantity;
        $this->total    = $total;
        $this->name     = $name;
    }
    public function get_product()  { return $this->product; }
    public function get_quantity() { return $this->quantity; }
    public function get_total()    { return $this->total; }
    public function get_name()     { return $this->name; }
}

// Extends the stub WC_Order so instanceof checks in create_payment_for_order() pass.
class CPFOrder extends WC_Order {
    private $id;
    private $items;
    private $meta         = array();
    private $user_id;
    private $billing_email;
    public  $save_count   = 0;
    public  $status_log   = array();

    public function __construct( $id, $items = array(), $user_id = 0, $email = 'buyer@example.test' ) {
        $this->id            = $id;
        $this->items         = $items;
        $this->user_id       = $user_id;
        $this->billing_email = $email;
    }
    public function get_id()                 { return $this->id; }
    public function get_items()              { return $this->items; }
    public function get_user_id()            { return $this->user_id; }
    public function get_billing_email()      { return $this->billing_email; }
    public function get_billing_first_name() { return 'Jane'; }
    public function get_billing_last_name()  { return 'Doe'; }
    public function get_currency()           { return 'USD'; }
    public function get_shipping_total()     { return '0'; }
    public function get_shipping_method()    { return ''; }
    public function get_total_tax()          { return '0'; }
    public function get_meta( $key ) {
        return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
    }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function save()                   { $this->save_count++; }
    public function update_status( $status, $note = '' ) { $this->status_log[] = $status; }
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

// ─── Helpers ──────────────────────────────────────────────────────────────────

function cpf_make_gw( array $overrides = array() ) {
    $ref      = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gw       = $ref->newInstanceWithoutConstructor();
    $defaults = array(
        'api_base_url' => 'https://api.breeze.cash',
        'api_key'      => 'sk_test_KEY',
        'id'           => 'breeze_payment_gateway',
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

function cpf_one_item() {
    return array( new CPFItem( new CPFProduct( 101 ), 1, '10.00', 'Widget' ) );
}

function cpf_reset_http() {
    global $http_log, $http_queue;
    $http_log   = array();
    $http_queue = array();
}

function cpf_push_http( $code, $body ) {
    global $http_queue;
    $http_queue[] = array( 'code' => $code, 'body' => $body );
}

// Returns the JSON-decoded body of the last POST request captured by the mock.
function cpf_last_post_body() {
    global $http_log;
    foreach ( array_reverse( $http_log ) as $entry ) {
        $method = isset( $entry['args']['method'] ) ? strtoupper( $entry['args']['method'] ) : '';
        if ( 'POST' === $method ) {
            return json_decode( $entry['args']['body'], true );
        }
    }
    return null;
}

// ─── Group 1: Early exits ─────────────────────────────────────────────────────

echo "\n🧪 Group 1: early exits\n";

// Non-WC_Order argument → WP_Error 'invalid_order'.
cpf_reset_http();
$result1a = cpf_make_gw()->create_payment_for_order( new stdClass() );
check( is_wp_error( $result1a ), 'non-WC_Order → WP_Error' );
check_eq( 'invalid_order', $result1a->get_error_code(), 'error code is invalid_order' );

// WC_Order with empty items → build_line_items() returns [] → WP_Error 'no_line_items'.
cpf_reset_http();
$order1b = new CPFOrder( 1, array() );
$result1b = cpf_make_gw()->create_payment_for_order( $order1b );
check( is_wp_error( $result1b ), 'WC_Order with no items → WP_Error' );
check_eq( 'no_line_items', $result1b->get_error_code(), 'error code is no_line_items' );

// ─── Group 2: Payment page API failure ────────────────────────────────────────

echo "\n🧪 Group 2: POST /payment_pages failure → WP_Error\n";

cpf_reset_http();
cpf_push_http( 200, '{"data":{}}' );      // GET /customers — no existing customer
cpf_push_http( 400, '{"error":"fail"}' ); // POST /payment_pages — API error

$order2 = new CPFOrder( 2, cpf_one_item() );
$result2 = cpf_make_gw()->create_payment_for_order( $order2 );
check( is_wp_error( $result2 ), 'API failure → WP_Error' );
check_eq( 'breeze_page_create_failed', $result2->get_error_code(), 'error code is breeze_page_create_failed' );

// ─── Group 3: Success — existing Breeze customer ──────────────────────────────

echo "\n🧪 Group 3: success — existing Breeze customer\n";

cpf_reset_http();
cpf_push_http( 200, '{"data":{"id":"cus_EXISTING"}}' ); // GET /customers
cpf_push_http( 200, '{"data":{"url":"https://pay.breeze.cash/p/page_ABC","id":"page_ABC"}}' ); // POST /payment_pages

$order3  = new CPFOrder( 3, cpf_one_item() );
$result3 = cpf_make_gw()->create_payment_for_order( $order3 );

check( is_array( $result3 ), 'success → array returned' );
check( isset( $result3['url'] ), 'result has url key' );
check( isset( $result3['id'] ), 'result has id key' );
check( isset( $result3['fail_return_url'] ), 'result has fail_return_url key' );
check_eq( 'page_ABC', $result3['id'], 'returned id matches page id' );
check( false !== strpos( $result3['url'], 'pay.breeze.cash' ), 'url contains pay.breeze.cash' );

// Order meta: payment page stored (single key + cumulative list).
check_eq( 'page_ABC', $order3->get_meta( '_breeze_payment_page_id' ), '_breeze_payment_page_id stored' );
check( is_array( $order3->get_meta( '_breeze_payment_page_ids' ) ), '_breeze_payment_page_ids is array' );
check(
    in_array( 'page_ABC', $order3->get_meta( '_breeze_payment_page_ids' ), true ),
    'page_ABC in cumulative page ids list'
);

// Order meta: return token stored (single key + cumulative list).
check( '' !== $order3->get_meta( '_breeze_return_token' ), '_breeze_return_token stored' );
check( is_array( $order3->get_meta( '_breeze_return_tokens' ) ), '_breeze_return_tokens is array' );
check(
    in_array( $order3->get_meta( '_breeze_return_token' ), $order3->get_meta( '_breeze_return_tokens' ), true ),
    'return token included in cumulative token list'
);

// Order lifecycle: status set to pending, save called at least twice.
check( in_array( 'pending', $order3->status_log, true ), 'update_status("pending") called' );
check( $order3->save_count >= 2, 'save() called at least twice (token write + page-id write)' );

// POST /payment_pages body: existing customer passed by id.
$post3 = cpf_last_post_body();
check( isset( $post3['customer']['id'] ), 'POST body has customer.id for existing customer' );
check_eq( 'cus_EXISTING', $post3['customer']['id'], 'customer.id matches GET /customers response' );

// ─── Group 4: Success — new guest customer ────────────────────────────────────

echo "\n🧪 Group 4: success — new guest customer (inline customer payload)\n";

cpf_reset_http();
cpf_push_http( 200, '{}' ); // GET /customers returns empty — no existing customer
cpf_push_http( 200, '{"data":{"url":"https://pay.breeze.cash/p/page_NEW","id":"page_NEW"}}' );

$order4 = new CPFOrder( 4, cpf_one_item(), 0, 'guest@example.test' ); // user_id=0 → guest
cpf_make_gw()->create_payment_for_order( $order4 );
$post4 = cpf_last_post_body();

check( ! isset( $post4['customer']['id'] ), 'no customer.id in POST body for new customer' );
check( isset( $post4['customer']['referenceId'] ), 'inline customer has referenceId' );
check_eq( 'guest-4', $post4['customer']['referenceId'], 'guest referenceId is guest-{order_id}' );
check_eq( 'guest@example.test', $post4['customer']['email'], 'billing email in inline customer payload' );

// ─── Group 5: payment_methods appended to redirect URL ───────────────────────

echo "\n🧪 Group 5: payment_methods appended as query param\n";

cpf_reset_http();
cpf_push_http( 200, '{"data":{"id":"cus_PM"}}' );
cpf_push_http( 200, '{"data":{"url":"https://pay.breeze.cash/p/page_PM","id":"page_PM"}}' );

$order5  = new CPFOrder( 5, cpf_one_item() );
$result5 = cpf_make_gw( array( 'payment_methods' => array( 'card', 'apple_pay' ) ) )->create_payment_for_order( $order5 );

check( is_array( $result5 ), 'payment_methods set → success' );
check( false !== strpos( $result5['url'], 'preferred_payment_methods' ), 'url has preferred_payment_methods param' );
check( false !== strpos( $result5['url'], 'card' ), 'card present in url' );
check( false !== strpos( $result5['url'], 'apple_pay' ), 'apple_pay present in url' );

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
