<?php
/**
 * Tests for WC_Breeze_Modal_Checkout::ajax_cancel_payment()
 *
 * Loads the REAL WC_Breeze_Modal_Checkout class via reflection
 * (constructor skipped — it only registers WP hooks which are no-ops here).
 * wp_send_json_error/wp_send_json_success are stubbed to throw exceptions
 * rather than terminating, so assertions run after each branch.
 *
 * Covers:
 *  - Nonce check fails → json_error 403
 *  - Missing order_id in $_POST → json_error 400
 *  - order_id evaluates to 0 → json_error 400
 *  - Order not found (wc_get_order returns null) → json_error 404
 *  - Wrong payment method → json_error 404
 *  - Ownership denied (no session match, not logged in) → json_error 403
 *  - Ownership via session, pending status → order cancelled, session cleared, json_success
 *  - Ownership via logged-in user, non-cancellable status → update_status NOT called, json_success
 *  - No WC()->session → set() never invoked, json_success still returned
 *
 * Run: php tests/test-ajax-cancel-payment.php
 */

// ─── Stubs / polyfills ────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

// Thrown instead of terminating so tests can assert after each branch.
class BreezeJsonError extends RuntimeException {
    public $data;
    public $status;
    public function __construct( $data = null, $status = null ) {
        $this->data   = $data;
        $this->status = $status;
    }
}

class BreezeJsonSuccess extends RuntimeException {
    public $data;
    public function __construct( $data = null ) {
        $this->data = $data;
    }
}

// Globally-controllable nonce result.
$_mock_nonce_ok = true;

if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action, $query_arg = false, $die = true ) {
        global $_mock_nonce_ok;
        return $_mock_nonce_ok ? 1 : false;
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}

// Globally-controllable order mock.
$_mock_order = null;

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $order_id ) {
        global $_mock_order;
        return $_mock_order;
    }
}

// Globally-controllable WC() return value.
$_mock_wc = null;

if ( ! function_exists( 'WC' ) ) {
    function WC() {
        global $_mock_wc;
        return $_mock_wc;
    }
}

// Globally-controllable current-user id.
$_mock_current_user_id = 0;

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        global $_mock_current_user_id;
        return $_mock_current_user_id;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null, $status_code = null, $flags = 0 ) {
        throw new BreezeJsonError( $data, $status_code );
    }
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null, $status_code = null, $flags = 0 ) {
        throw new BreezeJsonSuccess( $data );
    }
}

// ─── Mock classes ─────────────────────────────────────────────────────────────

class Mock_Cancel_Order {
    public $payment_method = 'breeze_payment_gateway';
    public $user_id        = 0;
    public $status         = 'pending';
    public $update_log     = array();

    public function get_payment_method() { return $this->payment_method; }
    public function get_user_id()        { return $this->user_id; }

    public function has_status( $statuses ) {
        return in_array( $this->status, (array) $statuses, true );
    }

    public function update_status( $new_status, $note = '' ) {
        $this->update_log[] = array( 'status' => $new_status, 'note' => $note );
    }
}

class Mock_Cancel_Session {
    public $store   = array();
    public $set_log = array();

    public function get( $key ) {
        return isset( $this->store[ $key ] ) ? $this->store[ $key ] : null;
    }

    public function set( $key, $value ) {
        $this->store[ $key ] = $value;
        $this->set_log[]     = array( 'key' => $key, 'value' => $value );
    }
}

class Mock_Cancel_WC {
    public $session;
    public function __construct( $session = null ) { $this->session = $session; }
}

require_once __DIR__ . '/../includes/class-wc-breeze-modal-checkout.php';

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

// ─── Helper: build an instance without running the private constructor ─────────

function make_modal_cancel() {
    $ref = new ReflectionClass( 'WC_Breeze_Modal_Checkout' );
    return $ref->newInstanceWithoutConstructor();
}

// ─── 1. Nonce check fails → json_error 403 ───────────────────────────────────

echo "\n🧪 1. Nonce fails → json_error 403\n";

global $_mock_nonce_ok, $_mock_order, $_mock_wc, $_mock_current_user_id;
$_mock_nonce_ok        = false;
$_mock_order           = null;
$_mock_wc              = new Mock_Cancel_WC();
$_mock_current_user_id = 0;
$_POST                 = array( 'order_id' => '5' );

$modal = make_modal_cancel();
$err   = null;
try {
    $modal->ajax_cancel_payment();
} catch ( BreezeJsonError $e ) {
    $err = $e;
}

check( $err instanceof BreezeJsonError,                     'BreezeJsonError thrown on nonce failure' );
check_eq( 403, $err ? $err->status : null,                  'status is 403' );
check_eq( 'Security check failed.', $err ? $err->data['message'] : null, 'message is "Security check failed."' );

// ─── 2. Missing order_id key → json_error 400 ────────────────────────────────

echo "\n🧪 2. Missing order_id key → json_error 400\n";

$_mock_nonce_ok = true;
$_POST          = array(); // no order_id key

$modal = make_modal_cancel();
$err   = null;
try {
    $modal->ajax_cancel_payment();
} catch ( BreezeJsonError $e ) {
    $err = $e;
}

check( $err instanceof BreezeJsonError,                    'BreezeJsonError thrown when order_id absent' );
check_eq( 400, $err ? $err->status : null,                 'status is 400' );
check_eq( 'Missing order ID.', $err ? $err->data['message'] : null, 'message is "Missing order ID."' );

// ─── 3. order_id present but zero → json_error 400 ───────────────────────────

echo "\n🧪 3. order_id = 0 → json_error 400\n";

$_POST = array( 'order_id' => '0' );

$modal = make_modal_cancel();
$err   = null;
try {
    $modal->ajax_cancel_payment();
} catch ( BreezeJsonError $e ) {
    $err = $e;
}

check( $err instanceof BreezeJsonError,                    'BreezeJsonError thrown when order_id is zero' );
check_eq( 400, $err ? $err->status : null,                 'status is 400' );
check_eq( 'Missing order ID.', $err ? $err->data['message'] : null, 'message is "Missing order ID."' );

// ─── 4. Order not found → json_error 404 ─────────────────────────────────────

echo "\n🧪 4. Order not found → json_error 404\n";

$_POST       = array( 'order_id' => '42' );
$_mock_order = null; // wc_get_order returns null

$modal = make_modal_cancel();
$err   = null;
try {
    $modal->ajax_cancel_payment();
} catch ( BreezeJsonError $e ) {
    $err = $e;
}

check( $err instanceof BreezeJsonError,                    'BreezeJsonError thrown when order not found' );
check_eq( 404, $err ? $err->status : null,                 'status is 404' );
check_eq( 'Order not found.', $err ? $err->data['message'] : null, 'message is "Order not found."' );

// ─── 5. Wrong payment method → json_error 404 ────────────────────────────────

echo "\n🧪 5. Wrong payment method → json_error 404\n";

$order                 = new Mock_Cancel_Order();
$order->payment_method = 'paypal';
$_mock_order           = $order;
$_POST                 = array( 'order_id' => '42' );

$modal = make_modal_cancel();
$err   = null;
try {
    $modal->ajax_cancel_payment();
} catch ( BreezeJsonError $e ) {
    $err = $e;
}

check( $err instanceof BreezeJsonError,                    'BreezeJsonError thrown when payment method does not match' );
check_eq( 404, $err ? $err->status : null,                 'status is 404' );
check_eq( 'Order not found.', $err ? $err->data['message'] : null, 'message is "Order not found."' );

// ─── 6. Ownership denied (no session match, not logged in) → json_error 403 ──

echo "\n🧪 6. Ownership denied → json_error 403\n";

$order                 = new Mock_Cancel_Order();
$order->payment_method = 'breeze_payment_gateway';
$order->user_id        = 0;
$_mock_order           = $order;

$session           = new Mock_Cancel_Session();
$session->store['breeze_modal_pending_order'] = 99; // different from order_id 42
$_mock_wc              = new Mock_Cancel_WC( $session );
$_mock_current_user_id = 0; // not logged in
$_POST                 = array( 'order_id' => '42' );

$modal = make_modal_cancel();
$err   = null;
try {
    $modal->ajax_cancel_payment();
} catch ( BreezeJsonError $e ) {
    $err = $e;
}

check( $err instanceof BreezeJsonError,                    'BreezeJsonError thrown when ownership denied' );
check_eq( 403, $err ? $err->status : null,                 'status is 403' );
check_eq( 'Not authorised.', $err ? $err->data['message'] : null, 'message is "Not authorised."' );

// ─── 7. Session ownership, pending status → cancelled, session cleared ─────────

echo "\n🧪 7. Session ownership, pending order → cancelled + all session keys cleared\n";

$order                 = new Mock_Cancel_Order();
$order->payment_method = 'breeze_payment_gateway';
$order->user_id        = 0;
$order->status         = 'pending';
$_mock_order           = $order;

$session           = new Mock_Cancel_Session();
$session->store['breeze_modal_pending_order'] = 42; // matches order_id
$_mock_wc              = new Mock_Cancel_WC( $session );
$_mock_current_user_id = 0;
$_POST                 = array( 'order_id' => '42' );

$modal   = make_modal_cancel();
$success = null;
try {
    $modal->ajax_cancel_payment();
} catch ( BreezeJsonSuccess $e ) {
    $success = $e;
}

check( $success instanceof BreezeJsonSuccess,                    'BreezeJsonSuccess thrown on happy path' );
check_eq( true, $success ? $success->data['cancelled'] : null,   'response cancelled = true' );
check_eq( 1, count( $order->update_log ),                        'update_status called once' );
check_eq( 'cancelled', $order->update_log[0]['status'],          'order status set to cancelled' );

$set_keys = array_column( $session->set_log, 'key' );
check( in_array( 'breeze_modal_pending_order', $set_keys, true ), 'session: breeze_modal_pending_order cleared' );
check( in_array( 'store_api_draft_order',      $set_keys, true ), 'session: store_api_draft_order cleared' );
check( in_array( 'order_awaiting_payment',     $set_keys, true ), 'session: order_awaiting_payment cleared' );
check( in_array( 'chosen_payment_method',      $set_keys, true ), 'session: chosen_payment_method cleared' );
check_eq( 4, count( $session->set_log ),                         'exactly 4 session keys cleared' );

// ─── 8. User ownership, non-cancellable status → update_status NOT called ──────

echo "\n🧪 8. User ownership, processing status → update_status NOT called\n";

$order                 = new Mock_Cancel_Order();
$order->payment_method = 'breeze_payment_gateway';
$order->user_id        = 7;
$order->status         = 'processing'; // not in the cancellable list
$_mock_order           = $order;

$session           = new Mock_Cancel_Session();
$session->store['breeze_modal_pending_order'] = 0; // no session match
$_mock_wc              = new Mock_Cancel_WC( $session );
$_mock_current_user_id = 7; // logged-in user matches order->user_id
$_POST                 = array( 'order_id' => '42' );

$modal   = make_modal_cancel();
$success = null;
try {
    $modal->ajax_cancel_payment();
} catch ( BreezeJsonSuccess $e ) {
    $success = $e;
}

check( $success instanceof BreezeJsonSuccess,                    'BreezeJsonSuccess thrown for user-owned order' );
check_eq( 0, count( $order->update_log ),                        'update_status NOT called for processing status' );

// ─── 9. No WC()->session → set() never invoked, json_success still returned ───

echo "\n🧪 9. WC()->session is null → session->set not called, json_success returned\n";

$order                 = new Mock_Cancel_Order();
$order->payment_method = 'breeze_payment_gateway';
$order->user_id        = 5;
$order->status         = 'on-hold'; // cancellable
$_mock_order           = $order;

$_mock_wc              = new Mock_Cancel_WC( null ); // session = null
$_mock_current_user_id = 5; // owns via user
$_POST                 = array( 'order_id' => '42' );

$modal   = make_modal_cancel();
$success = null;
try {
    $modal->ajax_cancel_payment();
} catch ( BreezeJsonSuccess $e ) {
    $success = $e;
}

check( $success instanceof BreezeJsonSuccess,  'BreezeJsonSuccess returned even with null session' );
check_eq( 1, count( $order->update_log ),      'on-hold order still cancelled when session is null' );
check_eq( 'cancelled', $order->update_log[0]['status'], 'order status set to cancelled' );

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
