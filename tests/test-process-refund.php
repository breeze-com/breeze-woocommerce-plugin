<?php
/**
 * Tests for process_refund() — Breeze refund flow.
 *
 * Exercises: invalid-order guard, missing-page-id guard (both transaction_id
 * and _breeze_payment_page_id absent), amount guards (zero / negative / null),
 * the documented transaction-ID-over-meta preference, API failure path,
 * both refund-ID response shapes (data.id vs top-level id), the no-id
 * fallback, amount-to-minor-units conversion, and reason field inclusion.
 *
 * Loads the REAL WC_Breeze_Payment_Gateway class; stubs wc_get_order() and
 * wp_remote_request() so no WordPress environment is needed.
 *
 * Run: php tests/test-process-refund.php
 */

// ─── Stubs/polyfills needed to load the real class standalone ────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        // Declare $id so $this->id is defined even when the constructor is skipped.
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
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) { return trim( strip_tags( $str ) ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
        private $message;
        public function __construct( $code, $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

// Null logger — wc_get_logger() is called on non-2xx responses when $this->log is null.
class BreezeNullLogger {
    public function error( $msg, $ctx = array() ) {}
    public function warning( $msg, $ctx = array() ) {}
    public function info( $msg, $ctx = array() ) {}
    public function debug( $msg, $ctx = array() ) {}
}
function wc_get_logger() { return new BreezeNullLogger(); }

// wc_get_order() stub — set $wc_order_stub before each assertion group.
$wc_order_stub = false;
function wc_get_order( $id ) {
    global $wc_order_stub;
    return $wc_order_stub;
}

// HTTP stubs — capture url, body, and full args; return configured response.
$http_response_stub = null;
$last_request_url   = '';
$last_request_body  = '';
$last_request_args  = array();
function wp_remote_request( $url, $args ) {
    global $http_response_stub, $last_request_url, $last_request_body, $last_request_args;
    $last_request_url  = $url;
    $last_request_body = isset( $args['body'] ) ? $args['body'] : '';
    $last_request_args = $args;
    return $http_response_stub;
}
function wp_remote_retrieve_response_code( $response ) {
    return isset( $response['code'] ) ? $response['code'] : 200;
}
function wp_remote_retrieve_body( $response ) {
    return isset( $response['body'] ) ? $response['body'] : '{}';
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Minimal order stub ───────────────────────────────────────────────────────

class Breeze_Refund_Order_Stub {
    private $id;
    private $transaction_id;
    private $meta;
    private $currency;
    public  $notes = array();

    public function __construct( $id, $transaction_id = '', $meta = array(), $currency = 'USD' ) {
        $this->id             = $id;
        $this->transaction_id = $transaction_id;
        $this->meta           = $meta;
        $this->currency       = $currency;
    }

    public function get_id()             { return $this->id; }
    public function get_transaction_id() { return $this->transaction_id; }
    public function get_currency()       { return $this->currency; }

    public function get_meta( $key ) {
        return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
    }

    public function add_order_note( $note ) {
        $this->notes[] = $note;
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

// ─── Gateway factory ─────────────────────────────────────────────────────────

function make_refund_gateway() {
    $ref = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gw  = $ref->newInstanceWithoutConstructor();

    foreach ( array(
        'api_key'      => 'test_key',
        'api_base_url' => 'https://api.breeze.cash',
        'debug'        => false,
        'log'          => null,
    ) as $prop => $value ) {
        $p = $ref->getProperty( $prop );
        $p->setAccessible( true );
        $p->setValue( $gw, $value );
    }

    return $gw;
}

// Reusable 200 OK response with a data.id refund ID.
$ok_response = array( 'code' => 200, 'body' => json_encode( array( 'data' => array( 'id' => 'ref_ok' ) ) ) );

// ─── Group 1: invalid order ───────────────────────────────────────────────────

echo "\n🧪 Guard: wc_get_order() returns false\n";

$wc_order_stub = false;
$r = make_refund_gateway()->process_refund( 999 );
check( $r instanceof WP_Error,       'wc_get_order() false → WP_Error' );
check_eq( 'invalid_order', $r->code, 'error code is invalid_order' );

// ─── Group 2: no page ID ──────────────────────────────────────────────────────

echo "\n🧪 Guard: no transaction_id and no _breeze_payment_page_id meta\n";

$wc_order_stub = new Breeze_Refund_Order_Stub( 10, '', array() );
$r = make_refund_gateway()->process_refund( 10, 5.00 );
check( $r instanceof WP_Error,         'both page-id sources empty → WP_Error' );
check_eq( 'missing_page_id', $r->code, 'error code is missing_page_id' );

// ─── Group 3: invalid amount ──────────────────────────────────────────────────

echo "\n🧪 Guard: amount ≤ 0 or null\n";

foreach ( array( 0, -1, null ) as $bad_amount ) {
    $wc_order_stub = new Breeze_Refund_Order_Stub( 20, 'pp_any', array() );
    $r = make_refund_gateway()->process_refund( 20, $bad_amount );
    check( $r instanceof WP_Error,        'amount=' . var_export( $bad_amount, true ) . ' → WP_Error' );
    check_eq( 'invalid_amount', $r->code, 'error code is invalid_amount' );
}

// ─── Group 4: page-ID preference (transaction_id beats meta) ─────────────────

echo "\n🧪 Page-ID preference: transaction_id over _breeze_payment_page_id meta\n";

// Both sources present — transaction_id must win (per code comment: "that's the page
// the customer actually paid on").
$wc_order_stub = new Breeze_Refund_Order_Stub( 30, 'pp_txn_winner', array(
    '_breeze_payment_page_id' => 'pp_meta_loser',
) );
$http_response_stub = $ok_response;
$last_request_url   = '';
$last_request_args  = array();
$r = make_refund_gateway()->process_refund( 30, 5.00 );
check_eq( true, $r,                                            'transaction_id + meta both present → succeeds' );
check( strpos( $last_request_url, 'pp_txn_winner'  ) !== false, 'request URL uses transaction_id' );
check( strpos( $last_request_url, 'pp_meta_loser' ) === false,  'request URL does not use meta page_id' );
check_eq( 'POST', $last_request_args['method'],                'refund request uses POST' );
check( isset( $last_request_args['headers']['Authorization'] )
    && strpos( $last_request_args['headers']['Authorization'], 'Basic ' ) === 0,
    'refund request sends Basic auth header' );

// transaction_id absent — falls back to meta.
$wc_order_stub = new Breeze_Refund_Order_Stub( 31, '', array(
    '_breeze_payment_page_id' => 'pp_from_meta',
) );
$http_response_stub = $ok_response;
$last_request_url   = '';
$r = make_refund_gateway()->process_refund( 31, 5.00 );
check_eq( true, $r,                                            'no transaction_id, meta present → succeeds' );
check( strpos( $last_request_url, 'pp_from_meta' ) !== false,  'request URL uses meta page_id' );

// ─── Group 5: API failure ─────────────────────────────────────────────────────

echo "\n🧪 API failure → WP_Error\n";

$wc_order_stub      = new Breeze_Refund_Order_Stub( 40, 'pp_fail', array() );
$http_response_stub = array( 'code' => 422, 'body' => json_encode( array( 'error' => 'Cannot refund crypto payment' ) ) );
$r = make_refund_gateway()->process_refund( 40, 10.00 );
check( $r instanceof WP_Error,       'API 422 → WP_Error' );
check_eq( 'refund_failed', $r->code, 'error code is refund_failed' );

// ─── Group 5b: transport failure (network/timeout) ────────────────────────────

echo "\n🧪 Transport failure (network error) → WP_Error\n";

// breeze_api_request() returns false when is_wp_error($response); a regression
// returning the WP_Error object directly would make !$result false and fake success.
$wc_order_stub      = new Breeze_Refund_Order_Stub( 41, 'pp_timeout', array() );
$http_response_stub = new WP_Error( 'http_request_failed', 'Connection timed out' );
$r = make_refund_gateway()->process_refund( 41, 5.00, '' );
check( $r instanceof WP_Error,          'transport failure → WP_Error (not a faked success)' );
check( empty( $wc_order_stub->notes ), 'transport failure → no success note added' );

// ─── Group 6: refund ID extraction ───────────────────────────────────────────

echo "\n🧪 Refund ID extraction: data.id shape\n";

$wc_order_stub      = new Breeze_Refund_Order_Stub( 50, 'pp_r50', array() );
$http_response_stub = array( 'code' => 200, 'body' => json_encode( array( 'data' => array( 'id' => 'ref_nested' ) ) ) );
$r = make_refund_gateway()->process_refund( 50, 7.99 );
check_eq( true, $r,                                                  'data.id response shape → true' );
check( strpos( $wc_order_stub->notes[0], 'ref_nested' ) !== false,  'order note contains nested refund ID' );

echo "\n🧪 Refund ID extraction: top-level id shape\n";

$wc_order_stub      = new Breeze_Refund_Order_Stub( 51, 'pp_r51', array() );
$http_response_stub = array( 'code' => 200, 'body' => json_encode( array( 'id' => 'ref_toplevel' ) ) );
$r = make_refund_gateway()->process_refund( 51, 3.00 );
check_eq( true, $r,                                                     'top-level id response shape → true' );
check( strpos( $wc_order_stub->notes[0], 'ref_toplevel' ) !== false,   'order note contains top-level refund ID' );

echo "\n🧪 Refund ID extraction: no id in response → N/A in note\n";

// A truthy response with no id fields (e.g. {"status":"ok"}) — an empty
// object decodes to array() which is falsy and would trip the failure gate.
$wc_order_stub      = new Breeze_Refund_Order_Stub( 52, 'pp_r52', array() );
$http_response_stub = array( 'code' => 200, 'body' => '{"status":"ok"}' );
$r = make_refund_gateway()->process_refund( 52, 1.00 );
check_eq( true, $r,                                                                     'no id in response → still true' );
check( strpos( $wc_order_stub->notes[0], 'Refund ID: N/A' ) !== false, 'no refund ID in response → note says "Refund ID: N/A"' );
check( strpos( $wc_order_stub->notes[0], 'ref_' ) === false,           'no stray refund ID leaked into the note' );

// ─── Group 7: amount converted to minor units ─────────────────────────────────

echo "\n🧪 Amount converted to minor units (dollars → cents)\n";

$wc_order_stub      = new Breeze_Refund_Order_Stub( 60, 'pp_cents', array() );
$http_response_stub = array( 'code' => 200, 'body' => '{"status":"ok"}' );
$last_request_body  = '';
make_refund_gateway()->process_refund( 60, 1.80 );
$body = json_decode( $last_request_body, true );
check_eq( 180, $body['amount'], '$1.80 → 180 minor units in POST body' );

$wc_order_stub      = new Breeze_Refund_Order_Stub( 61, 'pp_cents2', array() );
$http_response_stub = array( 'code' => 200, 'body' => '{"status":"ok"}' );
$last_request_body  = '';
make_refund_gateway()->process_refund( 61, 9.99 );
$body = json_decode( $last_request_body, true );
check_eq( 999, $body['amount'], '$9.99 → 999 minor units in POST body' );

// ─── Group 8: reason field ────────────────────────────────────────────────────

echo "\n🧪 Reason field: included when non-empty, absent when empty\n";

$wc_order_stub      = new Breeze_Refund_Order_Stub( 70, 'pp_rsn', array() );
$http_response_stub = array( 'code' => 200, 'body' => '{"status":"ok"}' );
$last_request_body  = '';
make_refund_gateway()->process_refund( 70, 5.00, 'Defective item' );
$body = json_decode( $last_request_body, true );
check( isset( $body['reason'] ),                 'reason key present when non-empty' );
check_eq( 'Defective item', $body['reason'],     'reason value passed through sanitize_text_field' );

$wc_order_stub      = new Breeze_Refund_Order_Stub( 71, 'pp_norsn', array() );
$http_response_stub = array( 'code' => 200, 'body' => '{"status":"ok"}' );
$last_request_body  = '';
make_refund_gateway()->process_refund( 71, 5.00, '' );
$body = json_decode( $last_request_body, true );
check( ! isset( $body['reason'] ),               'reason key absent when empty string' );

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
