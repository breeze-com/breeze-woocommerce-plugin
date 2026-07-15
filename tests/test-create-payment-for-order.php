<?php
/**
 * Tests for create_payment_for_order().
 *
 * Covers:
 *   - Invalid-order guard: non-WC_Order arguments → WP_Error('invalid_order')
 *   - Empty line-items guard: order with no valid products → WP_Error('no_line_items')
 *   - API-failure guard: POST returns non-2xx → WP_Error('breeze_page_create_failed')
 *   - Happy path: correct URL/id/fail_return_url returned; order meta and status updated
 *   - Customer-lookup branch: found customer uses id; unknown customer uses referenceId
 *   - Crypto/payment-method URL params: network and token appended only when crypto method selected
 *   - Exception path: too many line items → WP_Error('breeze_payment_exception')
 *   - Retry idempotency: second call appends new page-ID without duplicating existing ones
 *
 * Loads the REAL WC_Breeze_Payment_Gateway class via ReflectionClass.
 * Stubs wp_remote_request() with a queue so each enqueued response is
 * consumed in call order (GET /customers first, POST /payment_pages second).
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
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) { return trim( strip_tags( $str ) ); }
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
        return $attachment_id > 0 ? 'https://img.example.test/' . $attachment_id . '.jpg' : false;
    }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
        $chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $password .= $chars[ rand( 0, strlen( $chars ) - 1 ) ];
        }
        return $password;
    }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'https://example.test' . $path;
    }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg() {
        $func_args = func_get_args();
        if ( is_array( $func_args[0] ) ) {
            $params = $func_args[0];
            $url    = isset( $func_args[1] ) ? (string) $func_args[1] : '';
        } else {
            $params = array( $func_args[0] => $func_args[1] );
            $url    = isset( $func_args[2] ) ? (string) $func_args[2] : '';
        }
        $sep = ( strpos( $url, '?' ) === false ) ? '?' : '&';
        return $url . $sep . http_build_query( $params );
    }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
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

// Queue-based HTTP mock — each enqueued entry is consumed on the next
// wp_remote_request() call, in order.  false in the queue yields a WP_Error.
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
            public function info( $msg, $ctx = array() ) {}
        };
    }
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Order / item / product stubs ─────────────────────────────────────────────

class WC_CPFO_Product {
    private $id;
    public function __construct( $id ) { $this->id = $id; }
    public function get_id()                { return $this->id; }
    public function get_short_description() { return ''; }
    public function get_image_id()          { return 0; }
}

class WC_CPFO_Item {
    private $product_id;
    private $qty;
    private $total;
    private $name;
    public function __construct( $product_id, $qty, $total, $name = 'Test Product' ) {
        $this->product_id = $product_id;
        $this->qty        = $qty;
        $this->total      = $total;
        $this->name       = $name;
    }
    public function get_product()  { return new WC_CPFO_Product( $this->product_id ); }
    public function get_quantity() { return $this->qty; }
    public function get_total()    { return $this->total; }
    public function get_name()     { return $this->name; }
}

/**
 * Full order stub that extends WC_Order (satisfies the instanceof guard),
 * tracks meta mutations and status changes for assertions.
 */
class WC_CPFO_Order extends WC_Order {
    private $id;
    private $meta               = array();
    private $items              = array();
    private $currency           = 'USD';
    private $shipping_total     = 0;
    private $shipping_method    = '';
    private $billing_email      = 'buyer@example.test';
    private $billing_first_name = 'Jane';
    private $billing_last_name  = 'Doe';
    private $user_id            = 0;
    private $total_tax          = 0.0;

    public $save_count  = 0;
    public $status_log  = array();

    public function __construct( $id = 1, $opts = array() ) {
        $this->id = $id;
        if ( isset( $opts['meta'] ) )             { $this->meta             = $opts['meta']; }
        if ( isset( $opts['items'] ) )            { $this->items            = $opts['items']; }
        if ( isset( $opts['currency'] ) )         { $this->currency         = $opts['currency']; }
        if ( isset( $opts['shipping_total'] ) )   { $this->shipping_total   = $opts['shipping_total']; }
        if ( isset( $opts['billing_email'] ) )    { $this->billing_email    = $opts['billing_email']; }
        if ( isset( $opts['billing_first_name'] ) ) { $this->billing_first_name = $opts['billing_first_name']; }
        if ( isset( $opts['billing_last_name'] ) )  { $this->billing_last_name  = $opts['billing_last_name']; }
        if ( isset( $opts['user_id'] ) )          { $this->user_id          = $opts['user_id']; }
        if ( isset( $opts['total_tax'] ) )        { $this->total_tax        = $opts['total_tax']; }
    }

    public function get_id()                { return $this->id; }
    public function get_billing_email()     { return $this->billing_email; }
    public function get_billing_first_name() { return $this->billing_first_name; }
    public function get_billing_last_name() { return $this->billing_last_name; }
    public function get_user_id()           { return $this->user_id; }
    public function get_total_tax()         { return $this->total_tax; }
    public function get_items()             { return $this->items; }
    public function get_currency()          { return $this->currency; }
    public function get_shipping_total()    { return $this->shipping_total; }
    public function get_shipping_method()   { return ''; }

    public function get_meta( $key ) {
        return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
    }
    public function update_meta_data( $key, $val ) {
        $this->meta[ $key ] = $val;
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

/**
 * Instantiate the gateway without running its WP-heavy constructor.
 * Injects required private properties; public properties use their defaults.
 */
function make_cpfo_gateway( $overrides = array() ) {
    $ref = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gw  = $ref->newInstanceWithoutConstructor();

    $setp = function( $name, $val ) use ( $ref, $gw ) {
        $p = $ref->getProperty( $name );
        $p->setAccessible( true );
        $p->setValue( $gw, $val );
    };

    $setp( 'api_base_url', 'https://api.breeze.cash' );
    $setp( 'api_key',      'test_key' );

    if ( isset( $overrides['payment_methods'] ) ) {
        $setp( 'payment_methods', $overrides['payment_methods'] );
    }
    if ( isset( $overrides['crypto_network'] ) ) {
        $setp( 'crypto_network', $overrides['crypto_network'] );
    }
    if ( isset( $overrides['crypto_token'] ) ) {
        $setp( 'crypto_token', $overrides['crypto_token'] );
    }

    return $gw;
}

/** Reset HTTP log and enqueue a fresh set of mock responses. */
function reset_http( $responses = array() ) {
    global $http_log, $http_responses;
    $http_log       = array();
    $http_responses = $responses;
}

/** Convenience: one item for a WC_CPFO_Order. */
function cpfo_item( $product_id, $qty, $total ) {
    return new WC_CPFO_Item( $product_id, $qty, $total );
}

/**
 * Canned HTTP responses used in happy-path tests.
 * GET /customers → no existing customer; POST /payment_pages → success.
 */
function happy_path_responses( $page_id = 'pp_test', $page_url = 'https://pay.breeze.cash/pp_test' ) {
    return array(
        // GET /v1/customers — no match
        array( 'code' => 200, 'body' => '{}' ),
        // POST /v1/payment_pages — success
        array(
            'code' => 200,
            'body' => json_encode( array( 'data' => array( 'url' => $page_url, 'id' => $page_id ) ) ),
        ),
    );
}

$gw = make_cpfo_gateway();

// ─── Group 1: Invalid-order guard ────────────────────────────────────────────

echo "\n🧪 create_payment_for_order() — invalid-order guard\n";

reset_http();
$result = $gw->create_payment_for_order( null );
check( is_wp_error( $result ), 'null → WP_Error returned' );
check_eq( 'invalid_order', $result->get_error_code(), 'null → error code is invalid_order' );

reset_http();
$result = $gw->create_payment_for_order( new stdClass() );
check( is_wp_error( $result ), 'stdClass → WP_Error returned' );
check_eq( 'invalid_order', $result->get_error_code(), 'stdClass → error code is invalid_order' );

reset_http();
$result = $gw->create_payment_for_order( 42 );
check( is_wp_error( $result ), 'integer → WP_Error returned' );
check_eq( 'invalid_order', $result->get_error_code(), 'integer → error code is invalid_order' );

check_eq( 0, count( $http_log ), 'Guard fires before any API call' );

// ─── Group 2: Empty line-items guard ─────────────────────────────────────────

echo "\n🧪 create_payment_for_order() — empty line-items guard\n";

reset_http();
$order  = new WC_CPFO_Order( 1, array( 'items' => array() ) );
$result = $gw->create_payment_for_order( $order );
check( is_wp_error( $result ), 'No items → WP_Error returned' );
check_eq( 'no_line_items', $result->get_error_code(), 'No items → error code is no_line_items' );
check_eq( 0, count( $http_log ), 'No API calls before empty line-items guard' );

// ─── Group 3: API failure → breeze_page_create_failed ────────────────────────

echo "\n🧪 create_payment_for_order() — API failure path\n";

// GET customers OK, POST payment_pages returns 500.
reset_http( array(
    array( 'code' => 200, 'body' => '{}' ),
    array( 'code' => 500, 'body' => '{"error":"server_error"}' ),
) );
$order  = new WC_CPFO_Order( 2, array( 'items' => array( cpfo_item( 1, 1, 10.00 ) ) ) );
$result = $gw->create_payment_for_order( $order );
check( is_wp_error( $result ), 'POST 500 → WP_Error returned' );
check_eq( 'breeze_page_create_failed', $result->get_error_code(), 'POST 500 → error code is breeze_page_create_failed' );

// Transport error on POST.
reset_http( array(
    array( 'code' => 200, 'body' => '{}' ),
    false,  // transport failure on the POST
) );
$order  = new WC_CPFO_Order( 3, array( 'items' => array( cpfo_item( 1, 1, 10.00 ) ) ) );
$result = $gw->create_payment_for_order( $order );
check( is_wp_error( $result ), 'Transport error on POST → WP_Error returned' );
check_eq( 'breeze_page_create_failed', $result->get_error_code(), 'Transport error on POST → code is breeze_page_create_failed' );

// ─── Group 4: Happy path ──────────────────────────────────────────────────────

echo "\n🧪 create_payment_for_order() — happy path\n";

reset_http( happy_path_responses() );
$order  = new WC_CPFO_Order( 10, array( 'items' => array( cpfo_item( 5, 2, 20.00 ) ) ) );
$result = $gw->create_payment_for_order( $order );

check( ! is_wp_error( $result ), 'Success: result is not WP_Error' );
check_eq( 'https://pay.breeze.cash/pp_test', $result['url'], 'Success: result[url] matches payment page URL' );
check_eq( 'pp_test', $result['id'], 'Success: result[id] matches payment page ID' );
check(
    isset( $result['fail_return_url'] ) && strpos( $result['fail_return_url'], 'status=failed' ) !== false,
    'Success: fail_return_url contains status=failed'
);

// Meta and status mutations.
check_eq( 'pp_test', $order->get_meta( '_breeze_payment_page_id' ), 'Meta _breeze_payment_page_id saved' );
check_eq( array( 'pp_test' ), $order->get_meta( '_breeze_payment_page_ids' ), 'Meta _breeze_payment_page_ids = [pp_test]' );
check(
    ! empty( $order->get_meta( '_breeze_return_token' ) ),
    'Meta _breeze_return_token saved'
);
check_eq( array( 'pending' ), $order->status_log, 'update_status called with pending' );
check( $order->save_count >= 1, 'save() called at least once' );
check_eq( 2, count( $http_log ), 'Two API calls: GET /customers then POST /payment_pages' );

// ─── Group 5: Customer-lookup branching ──────────────────────────────────────

echo "\n🧪 create_payment_for_order() — customer lookup branch\n";

// No existing customer → POST body must use referenceId (inline creation).
reset_http( array(
    array( 'code' => 200, 'body' => '{}' ),
    array( 'code' => 200, 'body' => json_encode( array( 'data' => array( 'url' => 'https://pay.breeze.cash/pp_new', 'id' => 'pp_new' ) ) ) ),
) );
$order  = new WC_CPFO_Order( 20, array( 'items' => array( cpfo_item( 1, 1, 5.00 ) ), 'user_id' => 7 ) );
$result = $gw->create_payment_for_order( $order );
$post_body = json_decode( $http_log[1]['args']['body'], true );
check( isset( $post_body['customer']['referenceId'] ), 'Unknown customer → customer.referenceId in POST body' );
check(
    $post_body['customer']['referenceId'] === 'user-7',
    'Unknown customer with user_id=7 → referenceId = user-7'
);

// Existing customer found → POST body must use id (not full object).
reset_http( array(
    array( 'code' => 200, 'body' => json_encode( array( 'data' => array( 'id' => 'cust_existing' ) ) ) ),
    array( 'code' => 200, 'body' => json_encode( array( 'data' => array( 'url' => 'https://pay.breeze.cash/pp_cust', 'id' => 'pp_cust' ) ) ) ),
) );
$order  = new WC_CPFO_Order( 21, array( 'items' => array( cpfo_item( 1, 1, 5.00 ) ) ) );
$result = $gw->create_payment_for_order( $order );
$post_body = json_decode( $http_log[1]['args']['body'], true );
check_eq( 'cust_existing', $post_body['customer']['id'], 'Existing customer → customer.id = cust_existing in POST body' );
check( ! isset( $post_body['customer']['referenceId'] ), 'Existing customer → no referenceId in POST body' );

// ─── Group 6: Crypto / payment-method URL params ─────────────────────────────

echo "\n🧪 create_payment_for_order() — crypto URL params\n";

// crypto method + network → URL has ?...network=BINANCE.
$gw_crypto = make_cpfo_gateway( array(
    'payment_methods' => array( 'crypto' ),
    'crypto_network'  => 'BINANCE',
    'crypto_token'    => 'USDT',
) );
reset_http( happy_path_responses( 'pp_crypto', 'https://pay.breeze.cash/pp_crypto' ) );
$order  = new WC_CPFO_Order( 30, array( 'items' => array( cpfo_item( 1, 1, 10.00 ) ) ) );
$result = $gw_crypto->create_payment_for_order( $order );
check(
    strpos( $result['url'], 'network=BINANCE' ) !== false,
    'crypto method + network=BINANCE → URL contains network=BINANCE'
);
check(
    strpos( $result['url'], 'token=USDT' ) !== false,
    'crypto method + token=USDT → URL contains token=USDT'
);
check(
    strpos( $result['url'], 'preferred_payment_methods=crypto' ) !== false,
    'crypto method → URL contains preferred_payment_methods=crypto'
);

// Non-crypto method (e.g. apple_pay) → no network/token appended.
$gw_apple = make_cpfo_gateway( array(
    'payment_methods' => array( 'apple_pay' ),
    'crypto_network'  => 'BINANCE',
) );
reset_http( happy_path_responses( 'pp_apple', 'https://pay.breeze.cash/pp_apple' ) );
$order  = new WC_CPFO_Order( 31, array( 'items' => array( cpfo_item( 1, 1, 10.00 ) ) ) );
$result = $gw_apple->create_payment_for_order( $order );
check(
    strpos( $result['url'], 'network=' ) === false,
    'apple_pay method → URL does NOT contain network= param'
);

// ─── Group 7: Exception → WP_Error('breeze_payment_exception') ───────────────

echo "\n🧪 create_payment_for_order() — build_line_items exception\n";

// 20 product items each needing one entry → 20th pushes past the 19-entry limit.
$too_many = array();
for ( $i = 1; $i <= 20; $i++ ) {
    $too_many[] = cpfo_item( $i, 1, 5.00 );
}
reset_http();
$order  = new WC_CPFO_Order( 40, array( 'items' => $too_many ) );
$result = $gw->create_payment_for_order( $order );
check( is_wp_error( $result ), '20 items → WP_Error returned (exception caught)' );
check_eq( 'breeze_payment_exception', $result->get_error_code(), '20 items → error code is breeze_payment_exception' );
check(
    strpos( $result->get_error_message(), 'too many line items' ) !== false,
    '20 items → error message mentions "too many line items"'
);
check_eq( 0, count( $http_log ), '20 items → no API calls before exception' );

// ─── Group 8: Retry idempotency — page-ID list accumulates without duplicates ─

echo "\n🧪 create_payment_for_order() — retry idempotency\n";

// Order already has pp_first in its page-IDs list; second call adds pp_second.
reset_http( happy_path_responses( 'pp_second', 'https://pay.breeze.cash/pp_second' ) );
$order = new WC_CPFO_Order( 50, array(
    'items' => array( cpfo_item( 1, 1, 10.00 ) ),
    'meta'  => array( '_breeze_payment_page_ids' => array( 'pp_first' ) ),
) );
$gw->create_payment_for_order( $order );
$ids = $order->get_meta( '_breeze_payment_page_ids' );
check_eq( array( 'pp_first', 'pp_second' ), $ids, 'Retry appends new ID to existing list' );

// Same page ID returned again (e.g. same API response) → no duplication.
reset_http( happy_path_responses( 'pp_dupe', 'https://pay.breeze.cash/pp_dupe' ) );
$order = new WC_CPFO_Order( 51, array(
    'items' => array( cpfo_item( 1, 1, 10.00 ) ),
    'meta'  => array( '_breeze_payment_page_ids' => array( 'pp_dupe' ) ),
) );
$gw->create_payment_for_order( $order );
$ids = $order->get_meta( '_breeze_payment_page_ids' );
check_eq( array( 'pp_dupe' ), $ids, 'Duplicate page ID not added twice to the list' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 40 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 40 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
