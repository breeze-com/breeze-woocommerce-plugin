<?php
/**
 * Tests for handle_return() — the WooCommerce return-URL handler after a
 * Breeze payment page.
 *
 * Covers: $_GET parameter validation, order lookup, timing-safe token
 * verification (cumulative list and legacy single-token fallback), token
 * consumption, success/fail status transitions, cart clearing, and redirect
 * targets.
 *
 * wp_safe_redirect() is stubbed to throw BreezeRedirectException so the
 * `exit` that follows it never executes; the test catches the exception to
 * assert the redirect URL without terminating the process.
 *
 * Loads the REAL WC_Breeze_Payment_Gateway class via ReflectionClass so the
 * assertions track production behaviour rather than a copy of it.
 *
 * Run: php tests/test-handle-return.php
 */

// ─── Stubs / polyfills needed to load the real class standalone ───────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        // Provides the return URL shown on the WooCommerce order-received page.
        public function get_return_url( $order ) {
            return 'https://example.com/order-received/' . $order->get_id() . '/';
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
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) { return $str; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) { return $value; }
}
if ( ! function_exists( 'wc_get_cart_url' ) ) {
    function wc_get_cart_url() { return 'https://example.com/cart'; }
}
if ( ! function_exists( 'wc_get_checkout_url' ) ) {
    function wc_get_checkout_url() { return 'https://example.com/checkout'; }
}

// wc_get_order() — tests set $wc_order_stub before each scenario.
$wc_order_stub = false;
if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $id ) {
        global $wc_order_stub;
        return $wc_order_stub;
    }
}

// wc_add_notice() — captured so tests can assert type and message.
$wc_notices = array();
if ( ! function_exists( 'wc_add_notice' ) ) {
    function wc_add_notice( $message, $notice_type = 'success' ) {
        global $wc_notices;
        $wc_notices[] = array( 'message' => $message, 'type' => $notice_type );
    }
}

// wp_safe_redirect() — throws BreezeRedirectException instead of issuing a
// Location header so the `exit` that immediately follows it never executes.
class BreezeRedirectException extends RuntimeException {
    private $redirect_url;
    public function __construct( $url ) {
        parent::__construct( 'redirect:' . $url );
        $this->redirect_url = $url;
    }
    public function getRedirectUrl() { return $this->redirect_url; }
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( $url, $status = 302, $x_redirect_by = 'WordPress' ) {
        throw new BreezeRedirectException( $url );
    }
}

// WC() global — returns a singleton with a stubbed cart.
class Breeze_Test_Cart {
    public $empty_count = 0;
    public function empty_cart() { $this->empty_count++; }
}
class Breeze_Test_WC {
    public $cart;
    public function __construct() { $this->cart = new Breeze_Test_Cart(); }
}
$breeze_wc_instance = null;
if ( ! function_exists( 'WC' ) ) {
    function WC() {
        global $breeze_wc_instance;
        if ( null === $breeze_wc_instance ) {
            $breeze_wc_instance = new Breeze_Test_WC();
        }
        return $breeze_wc_instance;
    }
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Minimal order stub ───────────────────────────────────────────────────────

class Breeze_Return_Order_Stub {
    private $id;
    private $meta;
    private $paid;

    public $status_updates = array();
    public $deleted_meta   = array();
    public $save_count     = 0;

    public function __construct( $id, array $meta = array(), $paid = false ) {
        $this->id   = $id;
        $this->meta = $meta;
        $this->paid = $paid;
    }

    public function get_id()                         { return $this->id; }
    public function get_meta( $key )                 { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
    public function delete_meta_data( $key )         { $this->deleted_meta[] = $key; }
    public function save()                           { $this->save_count++; }
    public function is_paid()                        { return $this->paid; }
    public function update_status( $s, $note = '' )  { $this->status_updates[] = array( $s, $note ); }
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

function make_gateway() {
    $ref = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    return $ref->newInstanceWithoutConstructor();
    // debug defaults null (falsy) so log branches are never entered.
}

/**
 * Call handle_return() on $gw and return the URL redirected to, or null if
 * the method returned without redirecting (should not happen in practice).
 */
function call_handle_return( $gw ) {
    try {
        $gw->handle_return();
        return null;
    } catch ( BreezeRedirectException $e ) {
        return $e->getRedirectUrl();
    }
}

function reset_cart() {
    global $breeze_wc_instance;
    $breeze_wc_instance = new Breeze_Test_WC();
}
function reset_notices() {
    global $wc_notices;
    $wc_notices = array();
}

$gw = make_gateway();

// ─── Group 1: $_GET validation ────────────────────────────────────────────────

echo "\n🧪 order_id validation\n";

// Valid order present so a cart-redirect here proves the order_id guard
// specifically — not the order-not-found fallback.
$wc_order_stub = new Breeze_Return_Order_Stub( 1, array(
    '_breeze_return_tokens' => array( 'tok' ),
) );

$_GET = array();
check_eq( 'https://example.com/cart', call_handle_return( $gw ),
    'Missing order_id in $_GET → redirect to cart' );

$_GET = array( 'order_id' => '0' );
check_eq( 'https://example.com/cart', call_handle_return( $gw ),
    'order_id = 0 (absint of "0") → redirect to cart' );

// ─── Group 2: Order lookup ────────────────────────────────────────────────────

echo "\n🧪 Order lookup\n";

$wc_order_stub = false;
$_GET = array( 'order_id' => '99' );
check_eq( 'https://example.com/cart', call_handle_return( $gw ),
    'wc_get_order() returns false (order not found) → redirect to cart' );

// ─── Group 3: Token verification ─────────────────────────────────────────────

echo "\n🧪 Token verification\n";

// No tokens stored and no token supplied → token_matches stays false.
$order10 = new Breeze_Return_Order_Stub( 10 );
$wc_order_stub = $order10;
$_GET = array( 'order_id' => '10', 'status' => 'success' );
check_eq( 'https://example.com/cart', call_handle_return( $gw ),
    'No stored tokens, no token in GET → redirect to cart' );
check( empty( $order10->deleted_meta ),
    'No stored tokens → tokens NOT consumed' );
check_eq( 0, $order10->save_count,
    'No stored tokens → save() NOT called' );
check( empty( $order10->status_updates ),
    'No stored tokens → status NOT changed' );

// Token present in GET but does not match stored list.
$order11 = new Breeze_Return_Order_Stub( 11, array(
    '_breeze_return_tokens' => array( 'correct_token' ),
) );
$wc_order_stub = $order11;
$_GET = array( 'order_id' => '11', 'status' => 'success', 'token' => 'wrong_token' );
check_eq( 'https://example.com/cart', call_handle_return( $gw ),
    'Mismatched token → redirect to cart' );
// Security: a rejected token must leave the order untouched.
check( empty( $order11->deleted_meta ),
    'Mismatched token → tokens NOT consumed' );
check_eq( 0, $order11->save_count,
    'Mismatched token → save() NOT called' );
check( empty( $order11->status_updates ),
    'Mismatched token → status NOT changed' );

// Empty token in GET — !empty('') is false, so hash_equals loop is skipped.
$order12 = new Breeze_Return_Order_Stub( 12, array(
    '_breeze_return_tokens' => array( 'some_token' ),
) );
$wc_order_stub = $order12;
$_GET = array( 'order_id' => '12', 'status' => 'success', 'token' => '' );
check_eq( 'https://example.com/cart', call_handle_return( $gw ),
    'Empty token in GET → token_matches stays false → redirect to cart' );
check( empty( $order12->deleted_meta ),
    'Empty token → tokens NOT consumed' );
check_eq( 0, $order12->save_count,
    'Empty token → save() NOT called' );
check( empty( $order12->status_updates ),
    'Empty token → status NOT changed' );

// Token matches an entry in the cumulative list → proceeds to success path.
$wc_order_stub = new Breeze_Return_Order_Stub( 13, array(
    '_breeze_return_tokens' => array( 'token_a', 'token_b' ),
), false );
reset_cart();
$_GET = array( 'order_id' => '13', 'status' => 'success', 'token' => 'token_b' );
check_eq( 'https://example.com/order-received/13/', call_handle_return( $gw ),
    'Token matches cumulative list → success redirect to order-received URL' );

// Legacy single-token meta (no list) → array( $single_token ) built on the fly.
$wc_order_stub = new Breeze_Return_Order_Stub( 14, array(
    '_breeze_return_token' => 'legacy_tok',
), false );
reset_cart();
$_GET = array( 'order_id' => '14', 'status' => 'success', 'token' => 'legacy_tok' );
check_eq( 'https://example.com/order-received/14/', call_handle_return( $gw ),
    'Token matches legacy single-token meta → success redirect to order-received URL' );

// ─── Group 4: Token consumption ───────────────────────────────────────────────

echo "\n🧪 Token consumption after valid return\n";

$order15 = new Breeze_Return_Order_Stub( 15, array(
    '_breeze_return_tokens' => array( 'consume_me' ),
), false );
$wc_order_stub = $order15;
reset_cart();
$_GET = array( 'order_id' => '15', 'status' => 'success', 'token' => 'consume_me' );
call_handle_return( $gw );

check( in_array( '_breeze_return_tokens', $order15->deleted_meta, true ),
    '_breeze_return_tokens deleted from meta after redemption' );
check( in_array( '_breeze_return_token', $order15->deleted_meta, true ),
    '_breeze_return_token deleted from meta after redemption' );
check( $order15->save_count >= 1,
    'save() called to persist token deletion' );

// ─── Group 5: Success path — status transitions and cart clearing ─────────────

echo "\n🧪 Success path — status transitions\n";

// Unpaid order: status must be set to on-hold (webhook is the authoritative pay event).
$order20 = new Breeze_Return_Order_Stub( 20, array(
    '_breeze_return_tokens' => array( 'tok20' ),
), /* paid= */ false );
$wc_order_stub = $order20;
reset_cart();
$cart20 = WC()->cart;
$_GET = array( 'order_id' => '20', 'status' => 'success', 'token' => 'tok20' );
call_handle_return( $gw );

check( ! empty( $order20->status_updates ) && 'on-hold' === $order20->status_updates[0][0],
    'Unpaid order + success: status set to on-hold' );
check( $cart20->empty_count >= 1,
    'Unpaid order + success: cart emptied' );

// Already-paid order: update_status must NOT be called (idempotency guard).
$order21 = new Breeze_Return_Order_Stub( 21, array(
    '_breeze_return_tokens' => array( 'tok21' ),
), /* paid= */ true );
$wc_order_stub = $order21;
reset_cart();
$_GET = array( 'order_id' => '21', 'status' => 'success', 'token' => 'tok21' );
call_handle_return( $gw );

check( empty( $order21->status_updates ),
    'Already-paid order + success: update_status not called' );

// ─── Group 6: Fail path ───────────────────────────────────────────────────────

echo "\n🧪 Fail path\n";

$order30 = new Breeze_Return_Order_Stub( 30, array(
    '_breeze_return_tokens' => array( 'tok30' ),
), false );
$wc_order_stub = $order30;
reset_cart();
$cart30 = WC()->cart;
reset_notices();
$_GET = array( 'order_id' => '30', 'status' => 'failed', 'token' => 'tok30' );
$redirect30 = call_handle_return( $gw );

check_eq( 'https://example.com/checkout', $redirect30,
    'Fail path: redirect to checkout URL' );
check( ! empty( $order30->status_updates ) && 'failed' === $order30->status_updates[0][0],
    'Fail path: order status set to failed' );
check( $cart30->empty_count >= 1,
    'Fail path: cart emptied' );
check( ! empty( $wc_notices ) && 'error' === $wc_notices[0]['type'],
    'Fail path: error notice added' );
check( ! empty( $wc_notices ) && 'Payment was not completed.' === $wc_notices[0]['message'],
    'Fail path: notice message is "Payment was not completed."' );
check( in_array( '_breeze_return_tokens', $order30->deleted_meta, true ),
    'Fail path: _breeze_return_tokens deleted (token consumed even on failure)' );
check( $order30->save_count >= 1,
    'Fail path: save() called to persist token deletion' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
