<?php
/**
 * Tests for WC_Breeze_Payment_Gateway::webhook_handler()
 *
 * Loads the REAL gateway class.  WH_TestGateway extends it and overrides the
 * new protected get_webhook_payload() hook so the test supplies the raw body
 * without a live HTTP request.  The constructor is skipped via
 * ReflectionClass::newInstanceWithoutConstructor() and all WP/WC functions are
 * stubbed to stop at the JSON-response boundary.
 *
 * Covers (15 asserts):
 *  - Empty/invalid JSON body         → json_error 400 "Invalid webhook structure"
 *  - Valid JSON missing 'signature'  → json_error 400 "Invalid webhook structure"
 *  - Valid JSON missing 'data'       → json_error 400 "Invalid webhook structure"
 *  - Valid structure, wrong sig      → json_error 400 "Invalid webhook signature"
 *  - Valid structure, correct sig, unknown event type → json_success 200
 *
 * Run: php tests/test-webhook-handler.php
 */

// ─── Stubs / polyfills ────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

// Thrown instead of exiting so assertions can run after each branch.
class BreezeWHJsonError extends RuntimeException {
    public $payload;
    public $status;
    public function __construct( $payload = null, $status = null ) {
        $this->payload = $payload;
        $this->status  = $status;
    }
}
class BreezeWHJsonSuccess extends RuntimeException {
    public $payload;
    public $status;
    public function __construct( $payload = null, $status = null ) {
        $this->payload = $payload;
        $this->status  = $status;
    }
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null, $status_code = null, $flags = 0 ) {
        throw new BreezeWHJsonError( $data, $status_code );
    }
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null, $status_code = null, $flags = 0 ) {
        throw new BreezeWHJsonSuccess( $data, $status_code );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {}
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Test subclass ────────────────────────────────────────────────────────────

class WH_TestGateway extends WC_Breeze_Payment_Gateway {
    /** Raw body returned by get_webhook_payload(). */
    public $test_input = '';

    protected function get_webhook_payload() {
        return $this->test_input;
    }
}

// ─── Assert harness ───────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function wh_check( $cond, $label ) {
    global $passed, $failed;
    if ( $cond ) {
        echo "  ✅ {$label}\n";
        $passed++;
    } else {
        echo "  ❌ {$label}\n";
        $failed++;
    }
}

function wh_check_eq( $expected, $actual, $label ) {
    wh_check(
        $expected === $actual,
        sprintf( '%s (expected %s, got %s)', $label, var_export( $expected, true ), var_export( $actual, true ) )
    );
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Build a WH_TestGateway instance via reflection (constructor skipped).
 * Optionally inject a webhook_secret.
 */
function make_wh_gateway( $secret = '' ) {
    $ref = new ReflectionClass( 'WH_TestGateway' );
    $gw  = $ref->newInstanceWithoutConstructor();

    if ( $secret !== '' ) {
        $prop = ( new ReflectionClass( 'WC_Breeze_Payment_Gateway' ) )->getProperty( 'webhook_secret' );
        $prop->setAccessible( true );
        $prop->setValue( $gw, $secret );
    }

    return $gw;
}

/**
 * Produce the HMAC-SHA256 base64 signature that Breeze sends with a webhook.
 * Matches the algorithm in verify_webhook_signature(): recursive ksort → compact
 * JSON with unescaped slashes/unicode → HMAC-SHA256 → base64.
 */
function wh_breeze_sign( $data, $secret ) {
    $data = wh_canonical_sort( $data );
    $json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    return base64_encode( hash_hmac( 'sha256', $json, $secret, true ) );
}

function wh_canonical_sort( $array ) {
    if ( ! is_array( $array ) ) {
        return $array;
    }
    ksort( $array );
    foreach ( $array as $key => $value ) {
        $array[ $key ] = wh_canonical_sort( $value );
    }
    return $array;
}

// ─── Test groups ──────────────────────────────────────────────────────────────

// Group 1: Empty / invalid JSON → structure validation failure

echo "\n🧪 webhook_handler: empty body → structure failure (400)\n";

$gw1 = make_wh_gateway();
$gw1->test_input = '';

$caught1 = null;
try {
    $gw1->webhook_handler();
} catch ( BreezeWHJsonError $e ) {
    $caught1 = $e;
}

wh_check( $caught1 instanceof BreezeWHJsonError, 'empty body → BreezeWHJsonError thrown' );
wh_check_eq( 400, $caught1 ? $caught1->status : null, 'empty body → status 400' );
wh_check_eq( 'Invalid webhook structure', $caught1 ? $caught1->payload['message'] : null, 'empty body → message' );

// Group 2: Valid JSON missing 'signature' key → structure failure

echo "\n🧪 webhook_handler: valid JSON missing signature → structure failure (400)\n";

$gw2 = make_wh_gateway();
$gw2->test_input = json_encode( array( 'data' => array( 'orderId' => '100' ) ) );

$caught2 = null;
try {
    $gw2->webhook_handler();
} catch ( BreezeWHJsonError $e ) {
    $caught2 = $e;
}

wh_check( $caught2 instanceof BreezeWHJsonError, 'missing signature → BreezeWHJsonError thrown' );
wh_check_eq( 400, $caught2 ? $caught2->status : null, 'missing signature → status 400' );
wh_check_eq( 'Invalid webhook structure', $caught2 ? $caught2->payload['message'] : null, 'missing signature → message' );

// Group 3: Valid JSON missing 'data' key → structure failure

echo "\n🧪 webhook_handler: valid JSON missing data → structure failure (400)\n";

$gw3 = make_wh_gateway();
$gw3->test_input = json_encode( array( 'signature' => 'whatever' ) );

$caught3 = null;
try {
    $gw3->webhook_handler();
} catch ( BreezeWHJsonError $e ) {
    $caught3 = $e;
}

wh_check( $caught3 instanceof BreezeWHJsonError, 'missing data → BreezeWHJsonError thrown' );
wh_check_eq( 400, $caught3 ? $caught3->status : null, 'missing data → status 400' );
wh_check_eq( 'Invalid webhook structure', $caught3 ? $caught3->payload['message'] : null, 'missing data → message' );

// Group 4: Valid structure, wrong signature → signature verification failure

echo "\n🧪 webhook_handler: bad signature → signature failure (400)\n";

$gw4 = make_wh_gateway( 'real-secret' );
$gw4->test_input = json_encode( array(
    'signature' => 'not-the-right-signature',
    'data'      => array( 'orderId' => '100' ),
) );

$caught4 = null;
try {
    $gw4->webhook_handler();
} catch ( BreezeWHJsonError $e ) {
    $caught4 = $e;
}

wh_check( $caught4 instanceof BreezeWHJsonError, 'bad signature → BreezeWHJsonError thrown' );
wh_check_eq( 400, $caught4 ? $caught4->status : null, 'bad signature → status 400' );
wh_check_eq( 'Invalid webhook signature', $caught4 ? $caught4->payload['message'] : null, 'bad signature → message' );

// Group 5: Valid structure, correct signature, unknown event type → success

echo "\n🧪 webhook_handler: valid signature + unknown event → json_success (200)\n";

$test_secret = 'whsec_test_abc123';
$test_data   = array( 'orderId' => '999' );
$test_sig    = wh_breeze_sign( $test_data, $test_secret );

$gw5 = make_wh_gateway( $test_secret );
$gw5->test_input = json_encode( array(
    'type'      => 'TOTALLY_UNKNOWN_EVENT',
    'signature' => $test_sig,
    'data'      => $test_data,
) );

$caught5 = null;
try {
    $gw5->webhook_handler();
} catch ( BreezeWHJsonSuccess $e ) {
    $caught5 = $e;
}

wh_check( $caught5 instanceof BreezeWHJsonSuccess, 'valid sig + unknown event → BreezeWHJsonSuccess thrown' );
wh_check_eq( 200, $caught5 ? $caught5->status : null, 'valid sig + unknown event → status 200' );
wh_check_eq( 'Webhook processed', $caught5 ? $caught5->payload['message'] : null, 'valid sig + unknown event → success message' );

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '-', 50 ) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) {
    exit( 1 );
}
