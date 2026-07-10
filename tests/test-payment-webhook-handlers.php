<?php
/**
 * Tests for payment webhook status handlers and gateway availability.
 *
 * Covers:
 *   - is_available():                    currency gate + parent gate + filter extension
 *   - handle_payment_failed_webhook():   skip if no order; skip if already paid; update status otherwise
 *   - handle_payment_success_webhook():  skip if no order; payment_complete + note on first pay;
 *                                        duplicate-payment detection (idempotent on same pageId;
 *                                        note+meta on new pageId; dedup on repeated duplicate webhook)
 *
 * Loads the REAL WC_Breeze_Payment_Gateway class and exercises private methods
 * via ReflectionMethod, stubbing wc_get_order() and a minimal order object so
 * no WordPress environment is needed.
 *
 * Run: php tests/test-payment-webhook-handlers.php
 */

// ─── Stubs/polyfills needed to load the real class standalone ────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        public function is_available() {
            global $parent_gateway_available;
            return $parent_gateway_available;
        }
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

// Controllable stubs.
$wc_order_stub            = false;
$wc_currency              = 'USD';
$parent_gateway_available = true;
$apply_filters_overrides  = array();

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $id ) {
        global $wc_order_stub;
        return $wc_order_stub;
    }
}
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
    function get_woocommerce_currency() {
        global $wc_currency;
        return $wc_currency;
    }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) {
        global $apply_filters_overrides;
        return isset( $apply_filters_overrides[ $hook ] ) ? $apply_filters_overrides[ $hook ] : $value;
    }
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Minimal order stub ───────────────────────────────────────────────────────

class Breeze_Handler_Test_Order {
    private $id;
    private $meta;
    private $is_paid;
    private $transaction_id;
    public $notes          = array();
    public $status_updates = array();

    public function __construct( $id, $is_paid = false, $transaction_id = '', $meta = array() ) {
        $this->id             = $id;
        $this->is_paid        = $is_paid;
        $this->transaction_id = $transaction_id;
        $this->meta           = $meta;
    }

    public function get_id()             { return $this->id; }
    public function get_transaction_id() { return $this->transaction_id; }
    public function is_paid()            { return $this->is_paid; }

    public function get_meta( $key ) {
        return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
    }

    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function save() {}

    public function payment_complete( $txn_id ) {
        $this->transaction_id = $txn_id;
        $this->is_paid        = true;
    }

    public function add_order_note( $note ) { $this->notes[] = $note; }

    public function update_status( $status, $note = '' ) {
        $this->status_updates[] = array( 'status' => $status, 'note' => $note );
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

function make_handler_gateway() {
    $ref = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    return $ref->newInstanceWithoutConstructor();
}

function invoke_failed_webhook( $gw, $data ) {
    $m = new ReflectionMethod( 'WC_Breeze_Payment_Gateway', 'handle_payment_failed_webhook' );
    $m->setAccessible( true );
    $m->invoke( $gw, $data );
}

function invoke_success_webhook( $gw, $data ) {
    $m = new ReflectionMethod( 'WC_Breeze_Payment_Gateway', 'handle_payment_success_webhook' );
    $m->setAccessible( true );
    $m->invoke( $gw, $data );
}

$gw = make_handler_gateway();

// ─── Group 1: is_available() ─────────────────────────────────────────────────

echo "\n🧪 is_available() — currency gate\n";

$parent_gateway_available = false;
$wc_currency              = 'USD';
check_eq( false, $gw->is_available(), 'parent::is_available() false → unavailable regardless of currency' );

$parent_gateway_available = true;
$wc_currency = 'AUD';
check_eq( false, $gw->is_available(), 'Unsupported currency (AUD) → unavailable' );

$wc_currency = 'USD';
check_eq( true, $gw->is_available(), 'USD in default list → available' );

$wc_currency = 'EUR';
check_eq( true, $gw->is_available(), 'EUR in default list → available' );

// breeze_supported_currencies filter can extend the list.
$apply_filters_overrides['breeze_supported_currencies'] = array( 'USD', 'EUR', 'SGD', 'CAD', 'GBP' );
$wc_currency = 'GBP';
check_eq( true, $gw->is_available(), 'GBP added via breeze_supported_currencies filter → available' );
$wc_currency = 'AUD';
check_eq( false, $gw->is_available(), 'AUD absent from filtered list → still unavailable' );
$apply_filters_overrides = array();

// ─── Group 2: handle_payment_failed_webhook() ────────────────────────────────

echo "\n🧪 handle_payment_failed_webhook() — failed webhook transitions\n";

// No order found — silent return, no crash.
$wc_order_stub = false;
invoke_failed_webhook( $gw, array( 'clientReferenceId' => 'order-50' ) );
check( true, 'No order resolved → returns silently' );

// Order already paid — must NOT downgrade to failed (delayed failure webhook race).
$wc_order_stub = new Breeze_Handler_Test_Order( 51, /* is_paid */ true );
invoke_failed_webhook( $gw, array( 'clientReferenceId' => 'order-51' ) );
check_eq( array(), $wc_order_stub->status_updates, 'Already-paid order → status NOT changed' );

// Normal unpaid order — update_status('failed') called once.
$wc_order_stub = new Breeze_Handler_Test_Order( 52, /* is_paid */ false );
invoke_failed_webhook( $gw, array( 'clientReferenceId' => 'order-52' ) );
check_eq( 1, count( $wc_order_stub->status_updates ), 'Unpaid order → update_status() called once' );
check_eq( 'failed', $wc_order_stub->status_updates[0]['status'], "Status set to 'failed'" );

// ─── Group 3: handle_payment_success_webhook() ───────────────────────────────

echo "\n🧪 handle_payment_success_webhook() — success webhook transitions\n";

// No order resolved — silent return, no crash.
$wc_order_stub = false;
invoke_success_webhook( $gw, array( 'clientReferenceId' => 'order-60' ) );
check( true, 'No order resolved → returns silently' );

// Order not yet paid, webhook has no pageId.
$wc_order_stub = new Breeze_Handler_Test_Order( 61 );
invoke_success_webhook( $gw, array( 'clientReferenceId' => 'order-61' ) );
check( $wc_order_stub->is_paid(), 'Unpaid order → marked paid via payment_complete()' );
check_eq( '', $wc_order_stub->get_transaction_id(), 'No pageId in webhook → empty transaction ID' );
check_eq( 1, count( $wc_order_stub->notes ), 'Confirmation note added' );
check( false !== strpos( $wc_order_stub->notes[0], 'N/A' ), 'Note mentions N/A when pageId absent' );

// Order not yet paid, webhook carries a pageId.
$wc_order_stub = new Breeze_Handler_Test_Order( 62 );
invoke_success_webhook( $gw, array( 'clientReferenceId' => 'order-62', 'pageId' => 'pp_abc' ) );
check_eq( 'pp_abc', $wc_order_stub->get_transaction_id(), 'pageId stored as transaction ID' );
check( false !== strpos( $wc_order_stub->notes[0], 'pp_abc' ), 'Confirmation note includes pageId' );

// Order already paid, no incoming pageId → idempotent, no note.
$wc_order_stub = new Breeze_Handler_Test_Order( 63, /* is_paid */ true, 'pp_orig' );
invoke_success_webhook( $gw, array( 'clientReferenceId' => 'order-63' ) );
check_eq( array(), $wc_order_stub->notes, 'Already paid, no incoming pageId → no note (idempotent)' );

// Order already paid, same pageId retried → idempotent, no note.
$wc_order_stub = new Breeze_Handler_Test_Order( 64, /* is_paid */ true, 'pp_same' );
invoke_success_webhook( $gw, array( 'clientReferenceId' => 'order-64', 'pageId' => 'pp_same' ) );
check_eq( array(), $wc_order_stub->notes, 'Already paid, same pageId retry → no note (idempotent)' );

// Order already paid, different pageId → duplicate-payment note + meta flagged.
$wc_order_stub = new Breeze_Handler_Test_Order( 65, /* is_paid */ true, 'pp_orig' );
invoke_success_webhook( $gw, array( 'clientReferenceId' => 'order-65', 'pageId' => 'pp_dup' ) );
check_eq( 1, count( $wc_order_stub->notes ), 'Duplicate pageId → order note added' );
check( false !== strpos( $wc_order_stub->notes[0], 'pp_dup' ), 'Duplicate note mentions incoming pageId' );
check_eq( array( 'pp_dup' ), $wc_order_stub->get_meta( '_breeze_duplicate_pages_noted' ), 'Duplicate pageId recorded in meta' );

// Repeat webhook for already-noted duplicate → dedup, no second note.
invoke_success_webhook( $gw, array( 'clientReferenceId' => 'order-65', 'pageId' => 'pp_dup' ) );
check_eq( 1, count( $wc_order_stub->notes ), 'Repeated duplicate webhook → note NOT added again (dedup)' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
