<?php
/**
 * Tests for breeze_api_request().
 *
 * Covers:
 *   - URL construction: api_base_url + endpoint
 *   - Authorization header: Basic base64(api_key + ':')
 *   - Content-Type header: application/json
 *   - HTTP method pass-through (GET, POST, PUT, PATCH, DELETE)
 *   - Request body: included only for POST/PUT/PATCH with non-empty data
 *   - 2xx responses (200, 201, 299): returns decoded JSON
 *   - Non-2xx responses (199, 400, 500): returns false
 *   - Transport WP_Error: returns false
 *   - Empty data on POST: no body set
 *   - Data on GET/DELETE: no body set
 *
 * Uses ReflectionMethod to invoke the private method on the real class.
 * Stubs wp_remote_request() to capture calls and control responses.
 *
 * Run: php tests/test-breeze-api-request.php
 */

// ─── Stubs/polyfills needed to load the real class standalone ────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        public $id = '';
    }
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
$http_response = array( 'code' => 200, 'body' => '{"ok":true}' );

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

function make_api_gateway( $api_base_url = 'https://api.breeze.cash', $api_key = 'test_key_123' ) {
    $ref = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gw  = $ref->newInstanceWithoutConstructor();

    $p = $ref->getProperty( 'api_base_url' );
    $p->setAccessible( true );
    $p->setValue( $gw, $api_base_url );

    $p = $ref->getProperty( 'api_key' );
    $p->setAccessible( true );
    $p->setValue( $gw, $api_key );

    // debug=false (default) — log branches stay silent.
    return $gw;
}

function invoke_api_request( $gw, $method, $endpoint, $data = array() ) {
    $m = new ReflectionMethod( 'WC_Breeze_Payment_Gateway', 'breeze_api_request' );
    $m->setAccessible( true );
    return $m->invoke( $gw, $method, $endpoint, $data );
}

function reset_http( $code = 200, $body = '{"ok":true}' ) {
    global $http_log, $http_response;
    $http_log      = array();
    $http_response = array( 'code' => $code, 'body' => $body );
}

$gw = make_api_gateway();

// ─── Group 1: Request construction ───────────────────────────────────────────

echo "\n🧪 breeze_api_request() — request construction\n";

// URL = api_base_url + endpoint.
reset_http();
invoke_api_request( $gw, 'GET', '/v1/customers' );
check_eq( 1, count( $http_log ), 'Exactly one HTTP call made' );
check_eq(
    'https://api.breeze.cash/v1/customers',
    $http_log[0]['url'],
    'URL = api_base_url + endpoint'
);

// URL uses the configured api_base_url (different base).
reset_http();
$gw2 = make_api_gateway( 'https://sandbox.breeze.cash' );
invoke_api_request( $gw2, 'GET', '/v1/test' );
check_eq(
    'https://sandbox.breeze.cash/v1/test',
    $http_log[0]['url'],
    'URL uses configured api_base_url'
);

// Authorization header: Basic base64(api_key + ':').
reset_http();
invoke_api_request( $gw, 'GET', '/v1/ping' );
$expected_auth = 'Basic ' . base64_encode( 'test_key_123:' );
check_eq(
    $expected_auth,
    $http_log[0]['args']['headers']['Authorization'],
    'Authorization header is Basic base64(api_key + ":")'
);

// Content-Type header.
reset_http();
invoke_api_request( $gw, 'GET', '/v1/ping' );
check_eq(
    'application/json',
    $http_log[0]['args']['headers']['Content-Type'],
    'Content-Type header is application/json'
);

// HTTP method passed through as-is (GET).
reset_http();
invoke_api_request( $gw, 'GET', '/v1/ping' );
check_eq( 'GET', $http_log[0]['args']['method'], 'GET method passed through' );

// HTTP method passed through as-is (POST).
reset_http();
invoke_api_request( $gw, 'POST', '/v1/payments', array( 'amount' => 100 ) );
check_eq( 'POST', $http_log[0]['args']['method'], 'POST method passed through' );

// ─── Group 2: Request body rules ─────────────────────────────────────────────

echo "\n🧪 breeze_api_request() — request body rules\n";

// POST with non-empty data → body set to JSON-encoded data.
reset_http();
$data = array( 'amount' => 100, 'currency' => 'USD' );
invoke_api_request( $gw, 'POST', '/v1/payments', $data );
check( isset( $http_log[0]['args']['body'] ), 'POST with data → body is set' );
check_eq(
    json_encode( $data ),
    $http_log[0]['args']['body'],
    'POST body is JSON-encoded data'
);

// PUT with non-empty data → body set.
reset_http();
invoke_api_request( $gw, 'PUT', '/v1/orders/123', array( 'status' => 'paid' ) );
check( isset( $http_log[0]['args']['body'] ), 'PUT with data → body is set' );

// PATCH with non-empty data → body set.
reset_http();
invoke_api_request( $gw, 'PATCH', '/v1/orders/123', array( 'note' => 'x' ) );
check( isset( $http_log[0]['args']['body'] ), 'PATCH with data → body is set' );

// GET with non-empty data → body NOT set.
reset_http();
invoke_api_request( $gw, 'GET', '/v1/orders', array( 'filter' => 'active' ) );
check( ! isset( $http_log[0]['args']['body'] ), 'GET with data → body NOT set' );

// DELETE with non-empty data → body NOT set.
reset_http();
invoke_api_request( $gw, 'DELETE', '/v1/orders/1', array( 'reason' => 'test' ) );
check( ! isset( $http_log[0]['args']['body'] ), 'DELETE with data → body NOT set' );

// POST with empty data → body NOT set.
reset_http();
invoke_api_request( $gw, 'POST', '/v1/payments/pp_abc/expire' );
check( ! isset( $http_log[0]['args']['body'] ), 'POST with empty data → body NOT set' );

// ─── Group 3: Response handling ───────────────────────────────────────────────

echo "\n🧪 breeze_api_request() — response handling\n";

// 200 → decoded JSON returned.
reset_http( 200, '{"id":"pp_abc","status":"active"}' );
$result = invoke_api_request( $gw, 'GET', '/v1/payments/pp_abc' );
check_eq( array( 'id' => 'pp_abc', 'status' => 'active' ), $result, '200 → decoded JSON array returned' );

// 201 → decoded JSON returned (created).
reset_http( 201, '{"id":"pp_new"}' );
$result = invoke_api_request( $gw, 'POST', '/v1/payments', array( 'amount' => 50 ) );
check_eq( array( 'id' => 'pp_new' ), $result, '201 → decoded JSON array returned' );

// 299 → decoded JSON returned (2xx boundary).
reset_http( 299, '{"id":"edge"}' );
$result = invoke_api_request( $gw, 'GET', '/v1/test' );
check_eq( array( 'id' => 'edge' ), $result, '299 → decoded JSON returned (upper 2xx boundary)' );

// 199 → returns false (just below 2xx).
reset_http( 199, '{}' );
$result = invoke_api_request( $gw, 'GET', '/v1/test' );
check_eq( false, $result, '199 → returns false (just below 2xx)' );

// 400 → returns false.
reset_http( 400, '{"error":"bad_request"}' );
$result = invoke_api_request( $gw, 'POST', '/v1/payments', array( 'amount' => -1 ) );
check_eq( false, $result, '400 → returns false' );

// 500 → returns false.
reset_http( 500, '{"error":"internal"}' );
$result = invoke_api_request( $gw, 'GET', '/v1/payments' );
check_eq( false, $result, '500 → returns false' );

// WP_Error → returns false.
global $http_log, $http_response;
$http_log      = array();
$http_response = false;
$result        = invoke_api_request( $gw, 'GET', '/v1/payments' );
check_eq( false, $result, 'WP_Error (transport failure) → returns false' );
check_eq( 1, count( $http_log ), 'Transport error: HTTP call still attempted' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
