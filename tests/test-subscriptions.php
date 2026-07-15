<?php
/**
 * Tests for Breeze Subscriptions support.
 *
 * Loads the REAL gateway classes and drives the subscription flow against a
 * capturing wp_remote_request() stub, so the outgoing /v1/customers and
 * /v1/subscriptions payloads are asserted directly (field names, customer id,
 * crypto exclusion, return URLs) rather than against local literals.
 *
 * Covers:
 *  - Subscription detection (requires BOTH _breeze_price_id and _breeze_product_id)
 *  - Unsupported carts (mixed, multiple sub products, quantity > 1, misconfigured)
 *  - Customer provisioning (upsert POST /v1/customers) + correct subscription payload
 *  - Response uses `url` (not checkoutUrl)
 *  - SUBSCRIPTION_STATUS_UPDATED handling + ownership cross-check
 *  - INVOICE_STATUS_UPDATED lifecycle: first invoice completes the ORIGINAL order,
 *    subsequent invoices create renewals, priced from the invoice `amount`
 *  - Idempotency: a redelivered PAID never creates a duplicate order
 *  - Credential re-derivation (load_runtime_settings)
 *
 * Run: php tests/test-subscriptions.php
 */

// ─── Stubs / polyfills needed to load the real gateway classes ───────────────

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) {
		return $url . '?' . http_build_query( $args );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) { return 'https://example.com' . $path; }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = true ) {
		return substr( str_repeat( 'abcdefghij', (int) ceil( $length / 10 ) ), 0, $length );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		global $_test_post_meta;
		$val = isset( $_test_post_meta[ $post_id ][ $key ] ) ? $_test_post_meta[ $post_id ][ $key ] : '';
		return $single ? $val : array( $val );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		global $_test_post_meta;
		$_test_post_meta[ $post_id ][ $key ] = $value;
	}
}
if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $type = 'success' ) {
		global $_test_notices;
		$_test_notices[] = array( 'message' => $message, 'type' => $type );
	}
}
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) {
		global $_test_orders;
		return isset( $_test_orders[ $id ] ) ? $_test_orders[ $id ] : false;
	}
}
if ( ! function_exists( 'wc_get_orders' ) ) {
	// Minimal meta_key/meta_value filter, preserving insertion order so
	// orderby=date ASC (oldest first) is honored by returning results[0].
	function wc_get_orders( $args ) {
		global $_test_orders;
		$meta_key   = isset( $args['meta_key'] ) ? $args['meta_key'] : '';
		$meta_value = isset( $args['meta_value'] ) ? $args['meta_value'] : '';
		$results    = array();
		foreach ( $_test_orders as $order ) {
			if ( $order->get_meta( $meta_key ) === $meta_value ) {
				$results[] = $order;
			}
		}
		if ( isset( $args['order'] ) && 'DESC' === $args['order'] ) {
			$results = array_reverse( $results );
		}
		return $results;
	}
}
if ( ! function_exists( 'wc_create_order' ) ) {
	function wc_create_order( $args = array() ) {
		global $_test_orders, $_test_created_orders;
		$order = new MockWCOrder( 9000 + count( $_test_created_orders ) );
		if ( isset( $args['customer_id'] ) )          { $order->set_customer_id( $args['customer_id'] ); }
		if ( isset( $args['payment_method'] ) )       { $order->set_payment_method( $args['payment_method'] ); }
		if ( isset( $args['payment_method_title'] ) ) { $order->set_payment_method_title( $args['payment_method_title'] ); }
		$_test_created_orders[] = $order;
		$_test_orders[ $order->get_id() ] = $order;
		return $order;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

// Capturing HTTP stub. Records every outgoing request into $_test_http_log and
// returns a response matched by URL fragment from $_test_responses.
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args ) {
		global $_test_responses, $_test_default_response, $_test_http_log;
		$_test_http_log[] = array(
			'url'     => $url,
			'method'  => isset( $args['method'] ) ? $args['method'] : '',
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
			'body'    => isset( $args['body'] ) ? $args['body'] : '',
		);
		foreach ( $_test_responses as $fragment => $resp ) {
			if ( false !== strpos( $url, $fragment ) ) {
				return $resp;
			}
		}
		return $_test_default_response;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['code'] ) ? $response['code'] : 200;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '{}';
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() { return new class { public function debug() {} public function info() {} public function warning() {} public function error() {} }; }
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code, $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() { return $this->message; }
	}
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public $id          = '';
		public $title       = '';
		public $description = '';
		public $enabled     = 'yes';
		public $supports    = array();
		public $has_fields  = false;
		public $icon        = '';
		public $method_title       = '';
		public $method_description = '';
		protected $settings = array();
		public function get_option( $key, $default = '' ) {
			return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
		}
		public function init_settings() {}
		public function init_form_fields() {}
	}
}

if ( ! class_exists( 'WC_Log_Levels' ) ) {
	class WC_Log_Levels { const DEBUG = 'debug'; }
}
if ( ! class_exists( 'WC_Admin_Settings' ) ) {
	class WC_Admin_Settings { public static function add_error( $msg ) {} }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return strip_tags( $s ); } }
if ( ! function_exists( 'esc_url' ) )   { function esc_url( $u ) { return htmlspecialchars( $u, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) )  { function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'wpautop' ) )   { function wpautop( $s ) { return $s; } }
if ( ! defined( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_URL' ) ) {
	define( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_URL', 'https://example.com/plugin/' );
}

// ─── Mock WooCommerce order / product ────────────────────────────────────────

class MockWCOrder {
	private $id;
	private $meta   = array();
	private $notes  = array();
	private $status = 'pending';
	private $items  = array();
	private $customer_id  = 0;
	private $payment_method       = '';
	private $payment_method_title = '';
	private $billing_email  = 'test@example.com';
	private $billing_first  = 'Jane';
	private $billing_last   = 'Doe';
	private $currency       = 'USD';
	private $billing_address = array();
	private $shipping_address = array();
	private $transaction_id = '';
	private $total          = 0.0;

	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_billing_email()      { return $this->billing_email; }
	public function set_billing_email( $v )  { $this->billing_email = $v; }
	public function get_billing_first_name() { return $this->billing_first; }
	public function get_billing_last_name()  { return $this->billing_last; }
	public function get_customer_id()        { return $this->customer_id; }
	public function get_payment_method()     { return $this->payment_method; }
	public function get_payment_method_title() { return $this->payment_method_title; }
	public function get_currency()           { return $this->currency; }
	public function get_transaction_id()     { return $this->transaction_id; }
	public function get_total()              { return $this->total; }
	public function get_address( $type = 'billing' ) {
		return 'billing' === $type ? $this->billing_address : $this->shipping_address;
	}
	public function set_customer_id( $v )        { $this->customer_id = $v; }
	public function set_payment_method( $v )     { $this->payment_method = $v; }
	public function set_payment_method_title( $v ) { $this->payment_method_title = $v; }
	public function set_currency( $v )           { $this->currency = $v; }
	public function set_total( $v )              { $this->total = $v; }
	public function set_address( $addr, $type )  {
		if ( 'billing' === $type ) { $this->billing_address = $addr; }
		else { $this->shipping_address = $addr; }
	}
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() {}
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function get_notes() { return $this->notes; }
	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		if ( $note ) { $this->notes[] = $note; }
	}
	public function get_status() { return $this->status; }
	public function is_paid() { return in_array( $this->status, array( 'processing', 'completed' ), true ); }
	public function add_item( $item ) { $this->items[] = $item; }
	public function get_items() { return $this->items; }
	public function add_product( $product, $qty = 1, $args = array() ) {
		$this->items[] = new MockWCOrderItem( $product ? $product->get_id() : 0, $qty, $product );
	}
	public function calculate_totals() {}
	public function payment_complete( $txn_id = '' ) {
		$this->transaction_id = $txn_id;
		$this->status = 'processing';
	}
}

class MockWCOrderItem {
	private $product_id;
	private $quantity;
	private $product;
	public function __construct( $product_id, $quantity = 1, $product = null ) {
		$this->product_id = $product_id;
		$this->quantity   = $quantity;
		$this->product    = $product;
	}
	public function get_product_id() { return $this->product_id; }
	public function get_quantity()   { return $this->quantity; }
	public function get_product()    { return $this->product; }
}

class MockWCProduct {
	private $id;
	public function __construct( $id = 101 ) { $this->id = $id; }
	public function get_id() { return $this->id; }
}

// ─── Load real gateway classes ───────────────────────────────────────────────

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';
require_once __DIR__ . '/../includes/class-wc-breeze-subscription-gateway.php';

// ─── Test harness ─────────────────────────────────────────────────────────────

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

// Globals used by mocks.
$_test_post_meta      = array();
$_test_notices        = array();
$_test_orders         = array();
$_test_created_orders = array();
$_test_responses      = array();
$_test_default_response = array( 'code' => 200, 'body' => '{}' );
$_test_http_log       = array();

function reset_test_state() {
	global $_test_post_meta, $_test_notices, $_test_orders, $_test_created_orders, $_test_responses, $_test_http_log;
	$_test_post_meta      = array();
	$_test_notices        = array();
	$_test_orders         = array();
	$_test_created_orders = array();
	$_test_responses      = array();
	$_test_http_log       = array();
}

// Return the decoded JSON body of the first captured request to a URL fragment.
function captured_body_for( $fragment ) {
	global $_test_http_log;
	foreach ( $_test_http_log as $entry ) {
		if ( false !== strpos( $entry['url'], $fragment ) ) {
			return json_decode( $entry['body'], true );
		}
	}
	return null;
}

function request_count_for( $fragment ) {
	global $_test_http_log;
	$n = 0;
	foreach ( $_test_http_log as $entry ) {
		if ( false !== strpos( $entry['url'], $fragment ) ) { $n++; }
	}
	return $n;
}

// Register a product as a subscription (both IDs) in the meta store.
function register_sub_product( $product_ref, $price_id = 'price_abc', $product_id = 'prod_abc' ) {
	global $_test_post_meta;
	$_test_post_meta[ $product_ref ]['_breeze_price_id']   = $price_id;
	$_test_post_meta[ $product_ref ]['_breeze_product_id'] = $product_id;
}

// Standard happy-path API responses for a checkout (customer upsert + sub create).
function set_checkout_responses( $customer_id = 'cus_TEST', $sub_id = 'sub_TEST', $url = 'https://pay.breeze.cash/i/inv_1' ) {
	global $_test_responses;
	$_test_responses['/v1/customers']     = array( 'code' => 200, 'body' => json_encode( array( 'id' => $customer_id ) ) );
	$_test_responses['/v1/subscriptions'] = array( 'code' => 200, 'body' => json_encode( array( 'id' => $sub_id, 'url' => $url ) ) );
}

function call_method( $instance, $method_name, array $args = array() ) {
	$ref = new ReflectionMethod( get_class( $instance ), $method_name );
	$ref->setAccessible( true );
	return $ref->invokeArgs( $instance, $args );
}

function set_props( $instance, array $overrides = array() ) {
	$defaults = array(
		'api_base_url'               => 'https://api.breeze.cash',
		'api_key'                    => 'sk_test_key',
		'webhook_secret'             => 'whook_test_secret',
		'debug'                      => false,
		'log'                        => null,
		'payment_methods'            => array(),
		'crypto_network'             => '',
		'crypto_token'               => '',
		'merchant_calculated_tax'    => false,
		'flexible_amount_max'        => '',
		'flexible_amount_percentage' => '',
		'flexible_amount_fixed'      => '',
		'id'                         => 'breeze_payment_gateway',
		'checkout_display'           => 'redirect',
	);
	$props = array_merge( $defaults, $overrides );
	$ref   = new ReflectionClass( $instance );
	foreach ( $props as $prop => $val ) {
		$cls = $ref;
		while ( $cls && ! $cls->hasProperty( $prop ) ) { $cls = $cls->getParentClass(); }
		if ( $cls ) {
			$p = $cls->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $instance, $val );
		}
	}
}

function make_gateway( array $overrides = array() ) {
	$gw = ( new ReflectionClass( 'WC_Breeze_Payment_Gateway' ) )->newInstanceWithoutConstructor();
	set_props( $gw, $overrides );
	return $gw;
}

// A subscription order with one valid subscription line item.
function make_sub_order( $order_id, $product_ref = 101, $qty = 1 ) {
	global $_test_orders;
	register_sub_product( $product_ref );
	$order = new MockWCOrder( $order_id );
	$order->add_item( new MockWCOrderItem( $product_ref, $qty, new MockWCProduct( $product_ref ) ) );
	$_test_orders[ $order_id ] = $order;
	return $order;
}

// ─── Test 1: Detection requires BOTH price id and product id ─────────────────

echo "\n🧪 Test 1: Subscription detection requires both _breeze_price_id and _breeze_product_id\n";

reset_test_state();
register_sub_product( 101, 'price_1', 'prod_1' );        // full subscription
$_test_post_meta[102]['_breeze_price_id'] = '';           // regular product

$gw = make_gateway();

$full = new MockWCOrder( 1 );
$full->add_item( new MockWCOrderItem( 101, 1 ) );
$ctx_full = call_method( $gw, 'get_subscription_context', array( $full ) );
check( $ctx_full['is_subscription'], 'Product with price+product id → is_subscription' );
check_eq( '', $ctx_full['error'], 'Valid single subscription line → no error' );
check_eq( 'prod_1', $ctx_full['product_id'], 'product_id resolved' );
check_eq( 'price_1', $ctx_full['price_id'], 'price_id resolved' );

$plain = new MockWCOrder( 2 );
$plain->add_item( new MockWCOrderItem( 102, 1 ) );
$ctx_plain = call_method( $gw, 'get_subscription_context', array( $plain ) );
check( ! $ctx_plain['is_subscription'], 'Product without price id → not a subscription' );

// ─── Test 2: Mixed cart → failure ────────────────────────────────────────────

echo "\n🧪 Test 2: Mixed cart (subscription + regular) → failure + error notice\n";

reset_test_state();
register_sub_product( 101 );
$_test_post_meta[102]['_breeze_price_id'] = '';

$mixed = new MockWCOrder( 42 );
$mixed->add_item( new MockWCOrderItem( 101, 1, new MockWCProduct( 101 ) ) );
$mixed->add_item( new MockWCOrderItem( 102, 1, new MockWCProduct( 102 ) ) );
$_test_orders[42] = $mixed;

$result = call_method( make_gateway(), 'process_payment', array( 42 ) );
check_eq( 'failure', $result['result'], 'process_payment returns failure for mixed cart' );
check( ! empty( $_test_notices ), 'A notice was added for mixed cart' );
check( false !== strpos( $_test_notices[0]['message'], 'purchased separately' ), 'Correct mixed-cart message' );
check_eq( 'error', $_test_notices[0]['type'], 'Notice type is error' );

// ─── Test 3: Unsupported carts — qty > 1 and multiple sub products ───────────

echo "\n🧪 Test 3: Quantity > 1 and multiple subscription products are blocked\n";

reset_test_state();
$qty_order = make_sub_order( 43, 101, 2 ); // quantity 2
$ctx_qty   = call_method( make_gateway(), 'get_subscription_context', array( $qty_order ) );
check( ! empty( $ctx_qty['error'] ), 'qty > 1 → error' );
check( false !== strpos( $ctx_qty['error'], 'one subscription item' ), 'qty > 1 → correct message' );

reset_test_state();
register_sub_product( 101, 'price_1', 'prod_1' );
register_sub_product( 103, 'price_3', 'prod_3' );
$multi = new MockWCOrder( 44 );
$multi->add_item( new MockWCOrderItem( 101, 1, new MockWCProduct( 101 ) ) );
$multi->add_item( new MockWCOrderItem( 103, 1, new MockWCProduct( 103 ) ) );
$ctx_multi = call_method( make_gateway(), 'get_subscription_context', array( $multi ) );
check( ! empty( $ctx_multi['error'] ), 'two distinct subscription products → error' );

// ─── Test 4: Misconfigured product (price id but no product id) ──────────────

echo "\n🧪 Test 4: Subscription product missing Breeze Product ID → blocked\n";

reset_test_state();
$_test_post_meta[101]['_breeze_price_id']   = 'price_1';
$_test_post_meta[101]['_breeze_product_id'] = ''; // missing
$bad = new MockWCOrder( 45 );
$bad->add_item( new MockWCOrderItem( 101, 1, new MockWCProduct( 101 ) ) );
$ctx_bad = call_method( make_gateway(), 'get_subscription_context', array( $bad ) );
check( $ctx_bad['is_subscription'], 'still detected as a subscription attempt' );
check( false !== strpos( $ctx_bad['error'], 'misconfigured' ), 'missing product id → misconfigured error' );

// ─── Test 5: Subscription checkout payload (customer + subscription) ─────────

echo "\n🧪 Test 5: Checkout provisions a customer and sends the correct subscription payload\n";

reset_test_state();
$order5 = make_sub_order( 55, 101 );
set_checkout_responses( 'cus_5', 'sub_5', 'https://pay.breeze.cash/i/inv_5' );

$result5 = call_method( make_gateway(), 'create_subscription_checkout', array( $order5 ) );

check_eq( 'success', $result5['result'], 'checkout returns success' );
check_eq( 'https://pay.breeze.cash/i/inv_5', $result5['redirect'], 'redirect uses the API `url` field (not checkoutUrl)' );
check_eq( 'sub_5', $order5->get_meta( '_breeze_subscription_id' ), '_breeze_subscription_id stored on order' );
check_eq( 'yes', $order5->get_meta( '_breeze_is_subscription_order' ), 'original order flagged _breeze_is_subscription_order' );

// Customer upsert payload.
$cust = captured_body_for( '/v1/customers' );
check( is_array( $cust ), 'a POST /v1/customers request was made' );
check( ! empty( $cust['referenceId'] ), 'customer payload has referenceId' );
check( isset( $cust['signupAt'] ) && is_int( $cust['signupAt'] ) && $cust['signupAt'] > 0, 'customer payload has integer signupAt' );
check_eq( 'test@example.com', isset( $cust['email'] ) ? $cust['email'] : null, 'customer payload passes billing email' );

// Subscription payload.
$sub = captured_body_for( '/v1/subscriptions' );
check( is_array( $sub ), 'a POST /v1/subscriptions request was made' );
check_eq( 'order-55', isset( $sub['clientReferenceId'] ) ? $sub['clientReferenceId'] : null, 'clientReferenceId is order-{id}' );
check_eq( 'prod_abc', isset( $sub['productId'] ) ? $sub['productId'] : null, 'productId sent (API-required)' );
check_eq( 'price_abc', isset( $sub['priceId'] ) ? $sub['priceId'] : null, 'priceId sent' );
check_eq( 'cus_5', isset( $sub['customer']['id'] ) ? $sub['customer']['id'] : null, 'customer.id is the provisioned customer' );
check( ! isset( $sub['billingEmail'] ), 'no unsupported billingEmail field sent' );

// ─── Test 6: preferredPaymentMethods are fiat-only (no crypto) ───────────────

echo "\n🧪 Test 6: preferredPaymentMethods in the real payload are fiat-only\n";

$methods = isset( $sub['preferredPaymentMethods'] ) ? $sub['preferredPaymentMethods'] : array();
check( in_array( 'card', $methods, true ),       'card present' );
check( in_array( 'apple_pay', $methods, true ),  'apple_pay present' );
check( in_array( 'google_pay', $methods, true ), 'google_pay present' );
check( ! in_array( 'crypto', $methods, true ),         'crypto absent' );
check( ! in_array( 'crypto_wallet', $methods, true ),  'crypto_wallet absent' );
check( ! in_array( 'crypto_deposit', $methods, true ), 'crypto_deposit absent' );

// ─── Test 7: guest vs logged-in referenceId ─────────────────────────────────

echo "\n🧪 Test 7: customer referenceId is stable (guest = email hash, user = wc-user-{id})\n";

reset_test_state();
$guest = make_sub_order( 56, 101 );
set_checkout_responses( 'cus_g', 'sub_g' );
call_method( make_gateway(), 'create_subscription_checkout', array( $guest ) );
$guest_ref = captured_body_for( '/v1/customers' )['referenceId'];
check_eq( 'wc-guest-' . md5( 'test@example.com' ), $guest_ref, 'guest referenceId is wc-guest-{md5(email)}' );

reset_test_state();
$logged = make_sub_order( 57, 101 );
$logged->set_customer_id( 777 );
set_checkout_responses( 'cus_u', 'sub_u' );
call_method( make_gateway(), 'create_subscription_checkout', array( $logged ) );
check_eq( 'wc-user-777', captured_body_for( '/v1/customers' )['referenceId'], 'logged-in referenceId is wc-user-{id}' );

// ─── Test 8: customer provisioning failure aborts checkout ───────────────────

echo "\n🧪 Test 8: a failed customer upsert aborts checkout (no subscription call)\n";

reset_test_state();
$order8 = make_sub_order( 58, 101 );
global $_test_responses;
$_test_responses['/v1/customers']     = array( 'code' => 500, 'body' => '{}' ); // upsert fails
$_test_responses['/v1/subscriptions'] = array( 'code' => 200, 'body' => json_encode( array( 'id' => 'sub_x', 'url' => 'x' ) ) );

$result8 = call_method( make_gateway(), 'create_subscription_checkout', array( $order8 ) );
check_eq( 'failure', $result8['result'], 'checkout fails when customer cannot be provisioned' );
check_eq( 0, request_count_for( '/v1/subscriptions' ), 'no subscription request made after customer failure' );

// ─── Test 9: SUBSCRIPTION_STATUS_UPDATED status mapping ──────────────────────

echo "\n🧪 Test 9: SUBSCRIPTION_STATUS_UPDATED maps statuses to order state\n";

$status_cases = array(
	array( 'ACTIVE',             'note', 'active' ),
	array( 'SUSPENDED',          'on-hold', '' ),
	array( 'CANCELED',           'cancelled', '' ),
	array( 'INCOMPLETE_EXPIRED', 'cancelled', '' ),
	array( 'GRACE_PERIOD',       'on-hold', '' ),
	array( 'TRIALING',           'note', 'trial' ),
);
$oid = 60;
foreach ( $status_cases as $case ) {
	list( $status, $expect, $needle ) = $case;
	reset_test_state();
	$o = new MockWCOrder( $oid );
	$o->update_meta_data( '_breeze_subscription_id', 'sub_' . $status );
	$_test_orders[ $oid ] = $o;
	call_method( make_gateway(), 'handle_subscription_status_updated_webhook', array( array(
		'id'                => 'sub_' . $status,
		'status'            => $status,
		'clientReferenceId' => 'order-' . $oid,
	) ) );
	if ( 'note' === $expect ) {
		$notes = $o->get_notes();
		check( ! empty( $notes ) && ( '' === $needle || false !== stripos( implode( ' ', $notes ), $needle ) ), "$status → informational note (no status change)" );
		check_eq( 'pending', $o->get_status(), "$status → order status unchanged" );
	} else {
		check_eq( $expect, $o->get_status(), "$status → order status is $expect" );
	}
	$oid++;
}

// ─── Test 10: subscription webhook ownership cross-check ─────────────────────

echo "\n🧪 Test 10: a clientReferenceId pointing at an order that does not own the sub is rejected\n";

reset_test_state();
$wrong = new MockWCOrder( 68 );
$wrong->update_meta_data( '_breeze_subscription_id', 'sub_OWNED_BY_68' ); // different sub
$_test_orders[68] = $wrong;

call_method( make_gateway(), 'handle_subscription_status_updated_webhook', array( array(
	'id'                => 'sub_DIFFERENT',       // does not match order 68's sub
	'status'            => 'CANCELED',
	'clientReferenceId' => 'order-68',
) ) );
check_eq( 'pending', $wrong->get_status(), 'unrelated order is NOT cancelled by a mismatched clientReferenceId' );
check( empty( $wrong->get_notes() ), 'no note added to the unrelated order' );

// ─── Test 11: first invoice PAID completes the ORIGINAL order (no renewal) ───

echo "\n🧪 Test 11: first INVOICE_STATUS_UPDATED:PAID completes the original order, no renewal\n";

reset_test_state();
$original = new MockWCOrder( 70 );
$original->update_meta_data( '_breeze_subscription_id', 'sub_LIFE' );
$original->update_status( 'on-hold' ); // as left by handle_return
$original->add_item( new MockWCOrderItem( 101, 1, new MockWCProduct( 101 ) ) );
$_test_orders[70] = $original;

call_method( make_gateway(), 'handle_invoice_status_updated_webhook', array( array(
	'id'             => 'inv_1',
	'status'         => 'PAID',
	'subscriptionId' => 'sub_LIFE',
	'amount'         => 1500,
	'currency'       => 'USD',
) ) );

check_eq( 'processing', $original->get_status(), 'first PAID → original order marked paid (processing)' );
check_eq( 'inv_1', $original->get_transaction_id(), 'first PAID → payment_complete uses the invoice id' );
check_eq( 'inv_1', $original->get_meta( '_breeze_invoice_id' ), 'first PAID → invoice id recorded on the original order' );
check_eq( 0, count( $GLOBALS['_test_created_orders'] ), 'first PAID → NO renewal order created' );

// ─── Test 12: subsequent invoice PAID creates a renewal priced from the invoice

echo "\n🧪 Test 12: a subsequent PAID creates a renewal order priced from the invoice amount\n";

reset_test_state();
$orig12 = new MockWCOrder( 71 );
$orig12->update_meta_data( '_breeze_subscription_id', 'sub_REN' );
$orig12->update_meta_data( '_breeze_invoice_id', 'inv_first' );
$orig12->update_status( 'processing' ); // already paid
$orig12->set_customer_id( 9 );
$orig12->set_payment_method( 'breeze_subscription_gateway' );
$orig12->add_item( new MockWCOrderItem( 101, 1, new MockWCProduct( 101 ) ) );
$_test_orders[71] = $orig12;

call_method( make_gateway(), 'handle_invoice_status_updated_webhook', array( array(
	'id'                => 'inv_second',
	'status'            => 'PAID',
	'subscriptionId'    => 'sub_REN',
	'previousInvoiceId' => 'inv_first', // marks this as a renewal, not the first invoice
	'amount'            => 999, // $9.99 charged
	'currency'          => 'USD',
) ) );

check_eq( 1, count( $GLOBALS['_test_created_orders'] ), 'subsequent PAID → exactly one renewal order created' );
$renewal = $GLOBALS['_test_created_orders'][0];
check_eq( 'processing', $renewal->get_status(), 'renewal is paid (processing)' );
check_eq( 'inv_second', $renewal->get_transaction_id(), 'renewal payment_complete uses invoice id' );
check_eq( 'sub_REN', $renewal->get_meta( '_breeze_subscription_id' ), 'renewal carries subscription id' );
check_eq( 'inv_second', $renewal->get_meta( '_breeze_invoice_id' ), 'renewal carries invoice id' );
check_eq( 'yes', $renewal->get_meta( '_breeze_is_renewal' ), 'renewal flagged _breeze_is_renewal' );
check_eq( 9.99, $renewal->get_total(), 'renewal total sourced from invoice amount (minor units → 9.99)' );

// ─── Test 13: idempotency — a redelivered PAID never duplicates ──────────────

echo "\n🧪 Test 13: redelivered PAID is idempotent (no duplicate orders)\n";

// First-invoice redelivery.
reset_test_state();
$o13 = new MockWCOrder( 72 );
$o13->update_meta_data( '_breeze_subscription_id', 'sub_IDEM' );
$o13->update_status( 'on-hold' );
$o13->add_item( new MockWCOrderItem( 101, 1, new MockWCProduct( 101 ) ) );
$_test_orders[72] = $o13;
$gw13 = make_gateway();
$paid13 = array( 'id' => 'inv_A', 'status' => 'PAID', 'subscriptionId' => 'sub_IDEM', 'amount' => 500 );
call_method( $gw13, 'handle_invoice_status_updated_webhook', array( $paid13 ) );
call_method( $gw13, 'handle_invoice_status_updated_webhook', array( $paid13 ) ); // redelivery
check_eq( 'processing', $o13->get_status(), 'first-invoice redelivery: original stays paid' );
check_eq( 0, count( $GLOBALS['_test_created_orders'] ), 'first-invoice redelivery: still no renewal created' );

// Renewal redelivery.
reset_test_state();
$o13b = new MockWCOrder( 73 );
$o13b->update_meta_data( '_breeze_subscription_id', 'sub_IDEM2' );
$o13b->update_meta_data( '_breeze_invoice_id', 'inv_first' );
$o13b->update_status( 'processing' );
$o13b->add_item( new MockWCOrderItem( 101, 1, new MockWCProduct( 101 ) ) );
$_test_orders[73] = $o13b;
$gw13b = make_gateway();
$paid13b = array( 'id' => 'inv_R', 'status' => 'PAID', 'subscriptionId' => 'sub_IDEM2', 'previousInvoiceId' => 'inv_first', 'amount' => 500 );
call_method( $gw13b, 'handle_invoice_status_updated_webhook', array( $paid13b ) );
call_method( $gw13b, 'handle_invoice_status_updated_webhook', array( $paid13b ) ); // redelivery
check_eq( 1, count( $GLOBALS['_test_created_orders'] ), 'renewal redelivery: exactly one renewal (not two)' );

// ─── Test 14: get_order_by_subscription_id returns the ORIGINAL (oldest) ─────

echo "\n🧪 Test 14: get_order_by_subscription_id returns the original, not a later renewal\n";

reset_test_state();
$orig14 = new MockWCOrder( 74 );
$orig14->update_meta_data( '_breeze_subscription_id', 'sub_ORD' );
$_test_orders[74] = $orig14; // inserted first (oldest)
$ren14 = new MockWCOrder( 9999 );
$ren14->update_meta_data( '_breeze_subscription_id', 'sub_ORD' );
$ren14->update_meta_data( '_breeze_is_renewal', 'yes' );
$_test_orders[9999] = $ren14; // inserted later
$found = call_method( make_gateway(), 'get_order_by_subscription_id', array( 'sub_ORD' ) );
check( $found instanceof MockWCOrder, 'order found' );
check_eq( 74, $found->get_id(), 'oldest (original) order returned even though a renewal also carries the sub id' );

// ─── Test 15: INVOICE EXPIRED adds a note ────────────────────────────────────

echo "\n🧪 Test 15: INVOICE_STATUS_UPDATED:EXPIRED adds an informational note\n";

reset_test_state();
$o15 = new MockWCOrder( 75 );
$o15->update_meta_data( '_breeze_subscription_id', 'sub_EXP' );
$_test_orders[75] = $o15;
call_method( make_gateway(), 'handle_invoice_status_updated_webhook', array( array(
	'id'             => 'inv_exp',
	'status'         => 'EXPIRED',
	'subscriptionId' => 'sub_EXP',
) ) );
$notes15 = $o15->get_notes();
check( ! empty( $notes15 ), 'EXPIRED: note added' );
check( false !== strpos( $notes15[0], 'EXPIRED' ), 'EXPIRED: note mentions the status' );

// ─── Test 16: subscription gateway identity + form fields ────────────────────

echo "\n🧪 Test 16: WC_Breeze_Subscription_Gateway identity and crypto-free form\n";

$sub_gw = new WC_Breeze_Subscription_Gateway();
$sub_gw->init_form_fields();
$ff = $sub_gw->form_fields;
check_eq( 'breeze_subscription_gateway', $sub_gw->id, 'gateway id is breeze_subscription_gateway' );
check_eq( 'Breeze (Subscriptions)', $sub_gw->method_title, 'method_title is Breeze (Subscriptions)' );
check( ! isset( $ff['payment_methods'] ), 'payment_methods field removed' );
check( ! isset( $ff['crypto_network'] ),  'crypto_network field removed' );
check( ! isset( $ff['crypto_token'] ),    'crypto_token field removed' );
check( isset( $ff['enabled'] ),           'enabled field present' );
check( isset( $ff['webhook_secret'] ),    'webhook_secret field present' );

// ─── Test 17: subscription gateway routes checkout through the subscription flow

echo "\n🧪 Test 17: WC_Breeze_Subscription_Gateway::process_payment uses the subscription flow\n";

reset_test_state();
$sub_gw2 = new WC_Breeze_Subscription_Gateway();
set_props( $sub_gw2, array( 'id' => 'breeze_subscription_gateway' ) );
$order17 = make_sub_order( 80, 101 );
set_checkout_responses( 'cus_17', 'sub_17', 'https://pay.breeze.cash/i/inv_17' );
$result17 = $sub_gw2->process_payment( 80 );
check_eq( 'success', $result17['result'], 'subscription gateway returns success' );
check_eq( 'https://pay.breeze.cash/i/inv_17', $result17['redirect'], 'subscription gateway redirects to the API url' );
check_eq( 'sub_17', $order17->get_meta( '_breeze_subscription_id' ), 'subscription id stored' );

// ─── Test 18: subscription gateway rejects a non-subscription cart ───────────

echo "\n🧪 Test 18: subscription gateway rejects an order with no subscription product\n";

reset_test_state();
$_test_post_meta[102]['_breeze_price_id'] = '';
$plain18 = new MockWCOrder( 81 );
$plain18->add_item( new MockWCOrderItem( 102, 1, new MockWCProduct( 102 ) ) );
$_test_orders[81] = $plain18;
$result18 = $sub_gw2->process_payment( 81 );
check_eq( 'failure', $result18['result'], 'non-subscription cart → failure on the subscription gateway' );

// ─── Test 19: load_runtime_settings re-derives credentials from settings ─────

echo "\n🧪 Test 19: load_runtime_settings re-derives credentials (subscription gateway uses its own key)\n";

$gw19 = make_gateway();
$ref19 = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
$settings_prop = $ref19->getParentClass()->getProperty( 'settings' );
$settings_prop->setAccessible( true );

// Live-mode settings.
$settings_prop->setValue( $gw19, array(
	'enabled'        => 'yes',
	'testmode'       => 'no',
	'live_api_key'   => 'sk_live_SUBSCRIPTION',
	'test_api_key'   => 'sk_test_XXX',
	'webhook_secret' => 'wh_sub_secret',
	'debug'          => 'no',
) );
call_method( $gw19, 'load_runtime_settings' );
$api_key_prop = $ref19->getProperty( 'api_key' );
$api_key_prop->setAccessible( true );
$secret_prop = $ref19->getProperty( 'webhook_secret' );
$secret_prop->setAccessible( true );
check_eq( 'sk_live_SUBSCRIPTION', $api_key_prop->getValue( $gw19 ), 'live api_key derived from settings' );
check_eq( 'wh_sub_secret', $secret_prop->getValue( $gw19 ), 'webhook_secret derived from settings' );

// Flip to test mode and re-derive — proves it reads current settings each call.
$settings_prop->setValue( $gw19, array(
	'enabled'      => 'yes',
	'testmode'     => 'yes',
	'live_api_key' => 'sk_live_XXX',
	'test_api_key' => 'sk_test_SUBSCRIPTION',
) );
call_method( $gw19, 'load_runtime_settings' );
check_eq( 'sk_test_SUBSCRIPTION', $api_key_prop->getValue( $gw19 ), 'test-mode api_key re-derived from current settings' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
