<?php
/**
 * Tests for expire_sibling_payment_pages().
 *
 * Covers:
 *   - Early-return paths: no/empty page-ID meta; only ID equals keep_page_id; empty-string ID
 *   - Sibling expiry: correct POST URL with rawurlencode(); one vs. multiple siblings;
 *     keep_page_id is never expired
 *   - Best-effort behaviour: non-2xx response and transport WP_Error both swallowed silently
 *
 * Uses ReflectionMethod to invoke the private method on the real class.
 * Stubs wp_remote_request() to capture calls and control responses.
 *
 * Run: php tests/test-expire-siblings.php
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
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

// HTTP-layer stubs — reset before each group.
$http_log      = array();
$http_response = array( 'code' => 200, 'body' => '{}' );

if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = array() ) {
        global $http_log, $http_response;
        $http_log[] = array( 'url' => $url, 'args' => $args );
        if ( false === $http_response ) {
            return new WP_Error( 'transport_error', 'mock transport failure' );
        }
        return array( '_code' => $http_response['code'], '_body' => $http_response['body'] );
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

// ─── Minimal order stub ───────────────────────────────────────────────────────

class Breeze_Expire_Test_Order {
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

function make_expire_gateway() {
    $ref = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gw  = $ref->newInstanceWithoutConstructor();

    $p = $ref->getProperty( 'api_base_url' );
    $p->setAccessible( true );
    $p->setValue( $gw, 'https://api.breeze.cash' );

    $p = $ref->getProperty( 'api_key' );
    $p->setAccessible( true );
    $p->setValue( $gw, 'test_key' );

    // debug=false (default) — log branches in expire_sibling_payment_pages are skipped.
    return $gw;
}

function invoke_expire( $gw, $order, $keep_page_id ) {
    $m = new ReflectionMethod( 'WC_Breeze_Payment_Gateway', 'expire_sibling_payment_pages' );
    $m->setAccessible( true );
    $m->invoke( $gw, $order, $keep_page_id );
}

function reset_http() {
    global $http_log, $http_response;
    $http_log      = array();
    $http_response = array( 'code' => 200, 'body' => '{}' );
}

$gw = make_expire_gateway();

// ─── Group 1: early-return / no-op paths ─────────────────────────────────────

echo "\n🧪 expire_sibling_payment_pages() — early-return / no-op paths\n";

// No meta set — get_meta() returns '' (not an array) → early return.
reset_http();
$order = new Breeze_Expire_Test_Order( 1 );
invoke_expire( $gw, $order, 'pp_paid' );
check_eq( 0, count( $http_log ), 'No _breeze_payment_page_ids meta → no API calls' );

// Meta is an empty array → early return.
reset_http();
$order = new Breeze_Expire_Test_Order( 2, array( '_breeze_payment_page_ids' => array() ) );
invoke_expire( $gw, $order, 'pp_paid' );
check_eq( 0, count( $http_log ), 'Empty page-ID array → no API calls' );

// Only ID in the list equals keep_page_id → loop skips it, no call.
reset_http();
$order = new Breeze_Expire_Test_Order( 3, array( '_breeze_payment_page_ids' => array( 'pp_paid' ) ) );
invoke_expire( $gw, $order, 'pp_paid' );
check_eq( 0, count( $http_log ), 'Single ID equals keep_page_id → no API call' );

// Empty-string ID in list (falsy) and keep_page_id → both skipped.
reset_http();
$order = new Breeze_Expire_Test_Order( 4, array( '_breeze_payment_page_ids' => array( '', 'pp_paid' ) ) );
invoke_expire( $gw, $order, 'pp_paid' );
check_eq( 0, count( $http_log ), 'Empty-string ID and keep_page_id → no API calls' );

// ─── Group 2: sibling expiry API calls ───────────────────────────────────────

echo "\n🧪 expire_sibling_payment_pages() — sibling expiry calls\n";

// One sibling → one POST to the correct /expire URL.
reset_http();
$order = new Breeze_Expire_Test_Order( 5, array(
    '_breeze_payment_page_ids' => array( 'pp_old', 'pp_paid' ),
) );
invoke_expire( $gw, $order, 'pp_paid' );
check_eq( 1, count( $http_log ), 'One sibling → one API call' );
check_eq(
    'https://api.breeze.cash/v1/payment_pages/pp_old/expire',
    $http_log[0]['url'],
    'Correct expire URL for sibling'
);
check_eq( 'POST', $http_log[0]['args']['method'], 'HTTP method is POST' );

// Two siblings → two calls; keep_page_id NOT expired.
reset_http();
$order = new Breeze_Expire_Test_Order( 6, array(
    '_breeze_payment_page_ids' => array( 'pp_a', 'pp_b', 'pp_paid' ),
) );
invoke_expire( $gw, $order, 'pp_paid' );
check_eq( 2, count( $http_log ), 'Two siblings → two API calls' );
$urls = array_column( $http_log, 'url' );
check(
    in_array( 'https://api.breeze.cash/v1/payment_pages/pp_a/expire', $urls, true ),
    'pp_a expire URL called'
);
check(
    in_array( 'https://api.breeze.cash/v1/payment_pages/pp_b/expire', $urls, true ),
    'pp_b expire URL called'
);
check(
    ! in_array( 'https://api.breeze.cash/v1/payment_pages/pp_paid/expire', $urls, true ),
    'keep_page_id (pp_paid) NOT expired'
);

// Page ID with a space → rawurlencode() applied in the URL.
reset_http();
$order = new Breeze_Expire_Test_Order( 7, array(
    '_breeze_payment_page_ids' => array( 'pp has space', 'pp_paid' ),
) );
invoke_expire( $gw, $order, 'pp_paid' );
check_eq( 1, count( $http_log ), 'Special-char sibling → one API call' );
check_eq(
    'https://api.breeze.cash/v1/payment_pages/pp%20has%20space/expire',
    $http_log[0]['url'],
    'Page ID is rawurlencode()d in the expire URL'
);

// ─── Group 3: best-effort — API failure is swallowed ────────────────────────

echo "\n🧪 expire_sibling_payment_pages() — best-effort on API failure\n";

// Non-2xx response → breeze_api_request() returns false; method must not throw.
global $http_log, $http_response;
$http_log      = array();
$http_response = array( 'code' => 500, 'body' => '{}' );
$order         = new Breeze_Expire_Test_Order( 8, array(
    '_breeze_payment_page_ids' => array( 'pp_sibling', 'pp_paid' ),
) );
$threw = false;
try {
    invoke_expire( $gw, $order, 'pp_paid' );
} catch ( Throwable $e ) {
    $threw = true;
}
check( ! $threw, 'Non-2xx response → no exception thrown (best-effort)' );
check_eq( 1, count( $http_log ), 'API call still attempted on 500 response' );

// Transport-level WP_Error → breeze_api_request() returns false; no exception.
$http_log      = array();
$http_response = false;
$order         = new Breeze_Expire_Test_Order( 9, array(
    '_breeze_payment_page_ids' => array( 'pp_fail', 'pp_paid' ),
) );
$threw = false;
try {
    invoke_expire( $gw, $order, 'pp_paid' );
} catch ( Throwable $e ) {
    $threw = true;
}
check( ! $threw, 'WP_Error from transport → no exception thrown (best-effort)' );
check_eq( 1, count( $http_log ), 'API call attempted even on transport error' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
