<?php
/**
 * Tests for create_payment_for_order() and the private create_breeze_payment_page() it calls.
 *
 * Covers:
 *   - Invalid-order guard (not instanceof WC_Order) → WP_Error 'invalid_order'
 *   - Empty line items → WP_Error 'no_line_items'
 *   - API failure on POST /payment_pages → WP_Error 'breeze_page_create_failed'
 *   - Happy path, customer found via GET → customer.id in POST body
 *   - Happy path, customer NOT found via GET → full inline customer object in POST body
 *   - Guest user (user_id = 0) → referenceId = 'guest-{order_id}'
 *   - Order meta: _breeze_payment_page_id, _breeze_payment_page_ids (cumulative, idempotent)
 *   - Order meta: _breeze_return_token, _breeze_return_tokens (cumulative)
 *   - Order status set to 'pending', save() called
 *   - fail_return_url present in success result
 *   - Exception from build_line_items() (>19 items) → WP_Error 'breeze_payment_exception'
 *   - Crypto params appended to URL when payment_methods includes crypto_deposit
 *
 * Uses ReflectionClass::newInstanceWithoutConstructor() to skip the WP-heavy constructor.
 * Queue-based wp_remote_request() stub for multi-call sequences (GET /customers → POST /payment_pages).
 *
 * Run: php tests/test-create-payment-for-order.php
 */

// ─── Stubs / polyfills ────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {}
}
if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {}
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
        public function get_error_code()    { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
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
            public function error( $msg, $ctx = array() )   {}
            public function warning( $msg, $ctx = array() ) {}
            public function debug( $msg, $ctx = array() )   {}
            public function info( $msg, $ctx = array() )    {}
        };
    }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
        static $counter = 0;
        $counter++;
        return 'testtoken' . str_pad( $counter, 4, '0', STR_PAD_LEFT );
    }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) { return 'https://shop.test' . $path; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg() {
        $a = func_get_args();
        if ( is_array( $a[0] ) ) {
            $params = $a[0];
            $url    = isset( $a[1] ) ? (string) $a[1] : '';
        } else {
            $params = array( (string) $a[0] => $a[1] );
            $url    = isset( $a[2] ) ? (string) $a[2] : '';
        }
        $sep = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
        return $url . $sep . http_build_query( $params );
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string, $remove_breaks = false ) {
        $string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
        $string = strip_tags( $string );
        if ( $remove_breaks ) {
            $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
        }
        return trim( $string );
    }
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( $attachment_id ) {
        return $attachment_id > 0 ? "https://img.example.test/{$attachment_id}.jpg" : false;
    }
}

// Queue-based HTTP stub — populated before each test via queue_http(); reset via reset_http().
$http_log       = array();
$http_responses = array();

if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = array() ) {
        global $http_log, $http_responses;
        $http_log[] = array( 'url' => $url, 'args' => $args );
        $resp = array_shift( $http_responses );
        if ( null === $resp || false === $resp ) {
            return new WP_Error( 'transport_error', 'mock transport failure' );
        }
        return array( '_code' => $resp['code'], '_body' => $resp['body'] );
    }
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Order / product / item stubs ─────────────────────────────────────────────

class CPFO_Test_Product {
    private $id;
    private $desc;
    private $image_id;
    public function __construct( $id = 1, $desc = '', $image_id = 0 ) {
        $this->id       = $id;
        $this->desc     = $desc;
        $this->image_id = $image_id;
    }
    public function get_id()                { return $this->id; }
    public function get_short_description() { return $this->desc; }
    public function get_image_id()          { return $this->image_id; }
}

class CPFO_Test_Item {
    private $product;
    private $qty;
    private $total;
    private $name;
    public function __construct( $product, $qty, $total, $name = 'Test Product' ) {
        $this->product = $product;
        $this->qty     = $qty;
        $this->total   = $total;
        $this->name    = $name;
    }
    public function get_product()  { return $this->product; }
    public function get_quantity() { return $this->qty; }
    public function get_total()    { return $this->total; }
    public function get_name()     { return $this->name; }
}

/**
 * WC_Order stub. Must extend the stub WC_Order so instanceof checks pass.
 * Tracks update_meta_data(), save(), and update_status() calls for assertions.
 */
class CPFO_Test_Order extends WC_Order {
    public  $meta;
    public  $save_count;
    public  $status_log;
    private $id;
    private $email;
    private $first_name;
    private $last_name;
    private $user_id;
    private $currency;
    private $shipping_total;
    private $shipping_method;
    private $total_tax;
    private $items;

    public function __construct( $args = array() ) {
        $this->id              = isset( $args['id'] )              ? $args['id']              : 1;
        $this->email           = isset( $args['email'] )           ? $args['email']           : 'buyer@test.com';
        $this->first_name      = isset( $args['first_name'] )      ? $args['first_name']      : 'Test';
        $this->last_name       = isset( $args['last_name'] )       ? $args['last_name']       : 'Buyer';
        $this->user_id         = isset( $args['user_id'] )         ? $args['user_id']         : 0;
        $this->currency        = isset( $args['currency'] )        ? $args['currency']        : 'USD';
        $this->shipping_total  = isset( $args['shipping_total'] )  ? $args['shipping_total']  : 0.0;
        $this->shipping_method = isset( $args['shipping_method'] ) ? $args['shipping_method'] : '';
        $this->total_tax       = isset( $args['total_tax'] )       ? $args['total_tax']       : 0.0;
        $this->items           = isset( $args['items'] )           ? $args['items']           : array();
        $this->meta            = isset( $args['meta'] )            ? $args['meta']            : array();
        $this->save_count      = 0;
        $this->status_log      = array();
    }

    public function get_id()                  { return $this->id; }
    public function get_billing_email()        { return $this->email; }
    public function get_billing_first_name()   { return $this->first_name; }
    public function get_billing_last_name()    { return $this->last_name; }
    public function get_user_id()              { return $this->user_id; }
    public function get_currency()             { return $this->currency; }
    public function get_shipping_total()       { return $this->shipping_total; }
    public function get_shipping_method()      { return $this->shipping_method; }
    public function get_total_tax()            { return $this->total_tax; }
    public function get_items()                { return $this->items; }

    public function get_meta( $key ) {
        return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
    }
    public function update_meta_data( $key, $value ) {
        $this->meta[ $key ] = $value;
    }
    public function save() {
        $this->save_count++;
    }
    public function update_status( $status, $note = '' ) {
        $this->status_log[] = $status;
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

// ─── Helpers ─────────────────────────────────────────────────────────────────

function reset_http() {
    global $http_log, $http_responses;
    $http_log       = array();
    $http_responses = array();
}

function queue_http( $code, $body_array ) {
    global $http_responses;
    $http_responses[] = array( 'code' => $code, 'body' => json_encode( $body_array ) );
}

function make_cpfo_gateway( $overrides = array() ) {
    $ref = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gw  = $ref->newInstanceWithoutConstructor();

    $defaults = array(
        'api_base_url'               => 'https://api.breeze.cash',
        'api_key'                    => 'test_key',
        'debug'                      => false,
        'log'                        => null,
        'merchant_calculated_tax'    => false,
        'payment_methods'            => array(),
        'crypto_network'             => '',
        'crypto_token'               => '',
        'flexible_amount_percentage' => '',
        'flexible_amount_fixed'      => '',
        'flexible_amount_max'        => '',
        'send_product_description'   => false,
    );

    $settings = array_merge( $defaults, $overrides );
    foreach ( $settings as $prop => $val ) {
        $p = $ref->getProperty( $prop );
        $p->setAccessible( true );
        $p->setValue( $gw, $val );
    }

    return $gw;
}

function make_one_item( $product_id = 1, $total = '9.99' ) {
    $product = new CPFO_Test_Product( $product_id );
    return new CPFO_Test_Item( $product, 1, $total );
}

// ─── Group 1: invalid-order guard ────────────────────────────────────────────

echo "\n🧪 create_payment_for_order() — invalid-order guard\n";

reset_http();
$gw     = make_cpfo_gateway();
$result = $gw->create_payment_for_order( null );
check( is_wp_error( $result ), 'null order → WP_Error' );
check_eq( 'invalid_order', $result->get_error_code(), 'null: error code is invalid_order' );

reset_http();
$result = $gw->create_payment_for_order( new stdClass() );
check( is_wp_error( $result ), 'stdClass (not WC_Order) → WP_Error' );
check_eq( 'invalid_order', $result->get_error_code(), 'stdClass: error code is invalid_order' );
check_eq( 0, count( $http_log ), 'Invalid order guard fires before any HTTP call' );

// ─── Group 2: empty line items ────────────────────────────────────────────────

echo "\n🧪 create_payment_for_order() — empty line items\n";

reset_http();
$gw    = make_cpfo_gateway();
$order = new CPFO_Test_Order( array( 'items' => array() ) );
$result = $gw->create_payment_for_order( $order );
check( is_wp_error( $result ), 'No items → WP_Error' );
check_eq( 'no_line_items', $result->get_error_code(), 'No items: error code is no_line_items' );
check_eq( 0, count( $http_log ), 'No HTTP calls when line items are empty' );

// ─── Group 3: API failure on POST /payment_pages ──────────────────────────────

echo "\n🧪 create_payment_for_order() — API failure\n";

reset_http();
$gw    = make_cpfo_gateway();
$order = new CPFO_Test_Order( array( 'items' => array( make_one_item() ) ) );
queue_http( 200, array( 'data' => array() ) );             // GET /customers — no id
queue_http( 500, array( 'error' => 'server_error' ) );    // POST /payment_pages — failure
$result = $gw->create_payment_for_order( $order );
check( is_wp_error( $result ), 'API failure → WP_Error' );
check_eq( 'breeze_page_create_failed', $result->get_error_code(), 'API failure: error code is breeze_page_create_failed' );

// ─── Group 4: happy path — customer found via GET ─────────────────────────────

echo "\n🧪 create_payment_for_order() — happy path, customer found\n";

reset_http();
$gw    = make_cpfo_gateway();
$order = new CPFO_Test_Order( array(
    'id'      => 42,
    'email'   => 'buyer@test.com',
    'user_id' => 7,
    'items'   => array( make_one_item( 10 ) ),
) );
queue_http( 200, array( 'data' => array( 'id' => 'cust_abc' ) ) );   // GET → found
queue_http( 200, array( 'data' => array(                              // POST → success
    'id'  => 'pp_xyz',
    'url' => 'https://pay.breeze.cash/pp_xyz',
) ) );

$result = $gw->create_payment_for_order( $order );
check( ! is_wp_error( $result ), 'Customer-found path: not WP_Error' );
check( is_array( $result ), 'Customer-found path: result is array' );
check( isset( $result['url'] ),           'Result has url key' );
check( isset( $result['id'] ),            'Result has id key' );
check_eq( 'pp_xyz', $result['id'],        'Result id matches API response' );
check( isset( $result['fail_return_url'] ), 'Result has fail_return_url key' );
check( '' !== $result['fail_return_url'], 'fail_return_url is non-empty' );

$posted = json_decode( $http_log[1]['args']['body'], true );
check( isset( $posted['customer']['id'] ),                 'POST body: customer.id present (found path)' );
check_eq( 'cust_abc', $posted['customer']['id'],           'POST body: customer.id is cust_abc' );
check( ! isset( $posted['customer']['referenceId'] ),      'POST body: no referenceId on customer-found path' );

// ─── Group 5: happy path — customer NOT found (registered user) ───────────────

echo "\n🧪 create_payment_for_order() — happy path, customer not found, registered user\n";

reset_http();
$gw    = make_cpfo_gateway();
$order = new CPFO_Test_Order( array(
    'id'         => 77,
    'email'      => 'new@test.com',
    'first_name' => 'Jane',
    'last_name'  => 'Doe',
    'user_id'    => 5,
    'items'      => array( make_one_item( 11, '15.00' ) ),
) );
queue_http( 200, array( 'data' => array() ) );    // GET → no id
queue_http( 200, array( 'data' => array(
    'id'  => 'pp_new',
    'url' => 'https://pay.breeze.cash/pp_new',
) ) );

$result = $gw->create_payment_for_order( $order );
check( ! is_wp_error( $result ), 'Not-found path: not WP_Error' );
$posted = json_decode( $http_log[1]['args']['body'], true );
check( ! isset( $posted['customer']['id'] ),          'POST body: no customer.id on not-found path' );
check( isset( $posted['customer']['referenceId'] ),   'POST body: referenceId present' );
check_eq( 'user-5', $posted['customer']['referenceId'], 'POST body: referenceId = user-{user_id}' );
check_eq( 'new@test.com', $posted['customer']['email'],  'POST body: customer email' );
check_eq( 'Jane', $posted['customer']['firstName'],      'POST body: firstName' );
check_eq( 'Doe',  $posted['customer']['lastName'],       'POST body: lastName' );

// ─── Group 6: guest user (user_id = 0) → referenceId = 'guest-{order_id}' ───

echo "\n🧪 create_payment_for_order() — guest user referenceId\n";

reset_http();
$gw    = make_cpfo_gateway();
$order = new CPFO_Test_Order( array(
    'id'      => 333,
    'user_id' => 0,
    'items'   => array( make_one_item( 15, '25.00' ) ),
) );
queue_http( 200, array( 'data' => array() ) );
queue_http( 200, array( 'data' => array( 'id' => 'pp_guest', 'url' => 'https://pay.breeze.cash/pp_guest' ) ) );

$gw->create_payment_for_order( $order );
$posted = json_decode( $http_log[1]['args']['body'], true );
check_eq( 'guest-333', $posted['customer']['referenceId'], 'Guest: referenceId = guest-{order_id}' );

// ─── Group 7: order meta written correctly ────────────────────────────────────

echo "\n🧪 create_payment_for_order() — order meta\n";

reset_http();
$gw    = make_cpfo_gateway();
$order = new CPFO_Test_Order( array( 'id' => 99, 'items' => array( make_one_item( 12, '20.00' ) ) ) );
queue_http( 200, array( 'data' => array() ) );
queue_http( 200, array( 'data' => array( 'id' => 'pp_meta', 'url' => 'https://pay.breeze.cash/pp_meta' ) ) );

$result = $gw->create_payment_for_order( $order );
check( ! is_wp_error( $result ), 'Meta test: not WP_Error' );

check_eq( 'pp_meta', $order->meta['_breeze_payment_page_id'],  '_breeze_payment_page_id set to page id' );

$ids = $order->meta['_breeze_payment_page_ids'];
check( is_array( $ids ),                           '_breeze_payment_page_ids is array' );
check( in_array( 'pp_meta', $ids, true ),          '_breeze_payment_page_ids contains the new id' );

$token = $order->meta['_breeze_return_token'];
check( isset( $token ) && '' !== $token,           '_breeze_return_token set and non-empty' );

$tokens = $order->meta['_breeze_return_tokens'];
check( is_array( $tokens ),                        '_breeze_return_tokens is array' );
check( in_array( $token, $tokens, true ),          '_breeze_return_tokens contains the return token' );

check( in_array( 'pending', $order->status_log, true ), 'Order status updated to pending' );
check( $order->save_count > 0,                          'save() called at least once' );

// ─── Group 8: cumulative page-IDs idempotency ─────────────────────────────────

echo "\n🧪 create_payment_for_order() — cumulative IDs idempotency\n";

reset_http();
$gw    = make_cpfo_gateway();
$order = new CPFO_Test_Order( array(
    'id'    => 55,
    'items' => array( make_one_item( 13, '5.00' ) ),
    'meta'  => array( '_breeze_payment_page_ids' => array( 'pp_existing' ) ),
) );
queue_http( 200, array( 'data' => array() ) );
queue_http( 200, array( 'data' => array( 'id' => 'pp_existing', 'url' => 'https://pay.breeze.cash/pp_existing' ) ) );

$gw->create_payment_for_order( $order );
$ids   = $order->meta['_breeze_payment_page_ids'];
$dupes = array_filter( $ids, function ( $x ) { return $x === 'pp_existing'; } );
check_eq( 1, count( $dupes ), 'Duplicate page ID not appended to cumulative list' );

// ─── Group 9: exception → WP_Error 'breeze_payment_exception' ────────────────

echo "\n🧪 create_payment_for_order() — exception from build_line_items() → WP_Error\n";

reset_http();
$gw    = make_cpfo_gateway();
// 20 distinct products → build_line_items() throws (max 19 product entries).
$items = array();
for ( $i = 1; $i <= 20; $i++ ) {
    $items[] = new CPFO_Test_Item( new CPFO_Test_Product( 100 + $i ), 1, '1.00', "Product {$i}" );
}
$order  = new CPFO_Test_Order( array( 'items' => $items ) );
$result = $gw->create_payment_for_order( $order );
check( is_wp_error( $result ), '20 items → WP_Error (exception caught)' );
check_eq( 'breeze_payment_exception', $result->get_error_code(), 'Exception: error code is breeze_payment_exception' );
check_eq( 0, count( $http_log ), 'No HTTP calls on exception path' );

// ─── Group 10: crypto params appended when payment_methods = [crypto_deposit] ─

echo "\n🧪 create_payment_for_order() — crypto params appended to URL\n";

reset_http();
$gw    = make_cpfo_gateway( array(
    'payment_methods' => array( 'crypto_deposit' ),
    'crypto_network'  => 'eth',
    'crypto_token'    => 'usdt',
) );
$order = new CPFO_Test_Order( array(
    'id'    => 200,
    'items' => array( make_one_item( 14, '10.00' ) ),
) );
queue_http( 200, array( 'data' => array() ) );
queue_http( 200, array( 'data' => array( 'id' => 'pp_crypto', 'url' => 'https://pay.breeze.cash/pp_crypto' ) ) );

$result = $gw->create_payment_for_order( $order );
check( ! is_wp_error( $result ), 'Crypto params: success result' );
$url = $result['url'];
check( false !== strpos( $url, 'network=ETH' ), 'URL contains network=ETH (uppercased)' );
check( false !== strpos( $url, 'token=USDT' ),  'URL contains token=USDT (uppercased)' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 40 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 40 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
