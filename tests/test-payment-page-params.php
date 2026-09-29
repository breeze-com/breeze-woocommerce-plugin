<?php
/**
 * Tests for optional POST-body and URL parameters in create_breeze_payment_page().
 *
 * Covers the untested branches of the private create_breeze_payment_page() via
 * the public create_payment_for_order() entry point:
 *   - taxDetails: included (non-zero, zero-rated), excluded when disabled
 *   - flexibleAmount: percentage-only, fixed-only, both+max, absent
 *   - crypto_network / crypto_token: appended to URL when crypto in payment_methods
 *   - crypto params: absent when crypto not in payment_methods / methods unset
 *
 * Uses the C1 real-class pattern: ReflectionClass::newInstanceWithoutConstructor(),
 * property injection via reflection, queue-based HTTP stub, direct public method call.
 *
 * Run: php tests/test-payment-page-params.php
 */

// ─── Stubs / polyfills ─────────────────────────────────────────────────────────

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
if ( ! class_exists( 'WC_Log_Levels' ) ) {
    class WC_Log_Levels { const DEBUG = 'debug'; }
}
if ( ! class_exists( 'WC_Admin_Settings' ) ) {
    class WC_Admin_Settings { public static function add_error( $msg ) {} }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
        public $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code()    { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $v ) { return $v instanceof WP_Error; }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $s, $remove_breaks = false ) {
        $s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $s );
        $s = strip_tags( $s );
        if ( $remove_breaks ) {
            $s = preg_replace( '/[\r\n\t ]+/', ' ', $s );
        }
        return trim( $s );
    }
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( $id ) {
        return $id > 0 ? "https://img.example.test/{$id}.jpg" : false;
    }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $length = 12, $special = true, $extra = true ) {
        return substr( str_repeat( 'a', max( (int) $length, 1 ) ), 0, (int) $length );
    }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '/' ) { return 'https://example.test' . $path; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg() {
        $argv = func_get_args();
        if ( is_array( $argv[0] ) ) {
            list( $params, $url ) = $argv;
            $sep = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
            return rtrim( $url, '?' ) . $sep . http_build_query( $params );
        }
        list( $key, $value, $url ) = $argv;
        $sep = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
        return rtrim( $url, '?' ) . $sep . urlencode( $key ) . '=' . urlencode( $value );
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) { return strip_tags( trim( (string) $s ) ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'wc_get_logger' ) ) {
    function wc_get_logger() {
        return new class {
            public function error( $m, $c = array() ) {}
            public function debug( $m, $c = array() ) {}
            public function info( $m, $c = array() ) {}
        };
    }
}

// ─── Queue-based HTTP mock ─────────────────────────────────────────────────────
// Each success call to create_payment_for_order() consumes two queue entries:
// GET /customers (customer lookup) then POST /payment_pages.

$ppp_http_log   = array();
$ppp_http_queue = array();

if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = array() ) {
        global $ppp_http_log, $ppp_http_queue;
        $ppp_http_log[] = array( 'url' => $url, 'args' => $args );
        $resp = array_shift( $ppp_http_queue );
        if ( null === $resp || false === $resp ) {
            return new WP_Error( 'transport_error', 'mock queue exhausted' );
        }
        return array( '_code' => $resp['code'], '_body' => $resp['body'] );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $r ) {
        return isset( $r['_code'] ) ? $r['_code'] : 0;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $r ) {
        return isset( $r['_body'] ) ? $r['_body'] : '';
    }
}

// ─── Load real gateway class ───────────────────────────────────────────────────

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Order / item / product mocks ─────────────────────────────────────────────

class PPPProduct {
    private $id;
    public function __construct( $id ) { $this->id = $id; }
    public function get_id()                { return $this->id; }
    public function get_short_description() { return ''; }
    public function get_image_id()          { return 0; }
}

class PPPItem {
    private $product;
    private $quantity;
    private $total;
    private $name;
    public function __construct( $product, $quantity, $total, $name = 'Widget' ) {
        $this->product  = $product;
        $this->quantity = $quantity;
        $this->total    = $total;
        $this->name     = $name;
    }
    public function get_product()  { return $this->product; }
    public function get_quantity() { return $this->quantity; }
    public function get_total()    { return $this->total; }
    public function get_name()     { return $this->name; }
}

// Extends the stub WC_Order so instanceof checks in create_payment_for_order() pass.
class PPPOrder extends WC_Order {
    private $id;
    private $items;
    private $meta      = array();
    private $total_tax;
    public  $save_count  = 0;
    public  $status_log  = array();

    public function __construct( $id, $items = array(), $total_tax = '0' ) {
        $this->id        = $id;
        $this->items     = $items;
        $this->total_tax = $total_tax;
    }
    public function get_id()                  { return $this->id; }
    public function get_items()               { return $this->items; }
    public function get_user_id()             { return 0; }
    public function get_billing_email()       { return 'buyer@example.test'; }
    public function get_billing_first_name()  { return 'Jane'; }
    public function get_billing_last_name()   { return 'Doe'; }
    public function get_currency()            { return 'USD'; }
    public function get_shipping_total()      { return '0'; }
    public function get_shipping_method()     { return ''; }
    public function get_total_tax()           { return $this->total_tax; }
    public function get_meta( $key ) {
        return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
    }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function save()                    { $this->save_count++; }
    public function update_status( $status, $note = '' ) { $this->status_log[] = $status; }
}

// ─── Assert harness ────────────────────────────────────────────────────────────

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

// ─── Helpers ──────────────────────────────────────────────────────────────────

function ppp_make_gw( array $overrides = array() ) {
    $ref      = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gw       = $ref->newInstanceWithoutConstructor();
    $defaults = array(
        'api_base_url' => 'https://api.breeze.cash',
        'api_key'      => 'sk_test_KEY',
        'id'           => 'breeze_payment_gateway',
    );
    $props = array_merge( $defaults, $overrides );
    foreach ( $props as $name => $val ) {
        $cls = $ref;
        while ( $cls && ! $cls->hasProperty( $name ) ) { $cls = $cls->getParentClass(); }
        if ( $cls ) {
            $p = $cls->getProperty( $name );
            $p->setAccessible( true );
            $p->setValue( $gw, $val );
        }
    }
    return $gw;
}

function ppp_one_item() {
    return array( new PPPItem( new PPPProduct( 1 ), 1, '10.00', 'Widget' ) );
}

function ppp_reset_http() {
    global $ppp_http_log, $ppp_http_queue;
    $ppp_http_log   = array();
    $ppp_http_queue = array();
}

function ppp_push_http( $code, $body ) {
    global $ppp_http_queue;
    $ppp_http_queue[] = array( 'code' => $code, 'body' => $body );
}

// Queues a standard two-entry success cycle: GET /customers then POST /payment_pages.
function ppp_queue_success( $page_url = 'https://pay.breeze.cash/p/pageXXX', $page_id = 'pageXXX' ) {
    ppp_push_http( 200, '{"data":{"id":"cus_EXISTING"}}' );
    ppp_push_http( 200, json_encode( array( 'data' => array( 'url' => $page_url, 'id' => $page_id ) ) ) );
}

// Returns the JSON-decoded body of the last POST request captured by the mock.
function ppp_last_post_body() {
    global $ppp_http_log;
    foreach ( array_reverse( $ppp_http_log ) as $entry ) {
        $method = isset( $entry['args']['method'] ) ? strtoupper( $entry['args']['method'] ) : '';
        if ( 'POST' === $method ) {
            return json_decode( $entry['args']['body'], true );
        }
    }
    return null;
}

// ─── Group 1: taxDetails — enabled, non-zero tax ───────────────────────────────

echo "\n🧪 Group 1: taxDetails — merchant_calculated_tax enabled, non-zero tax\n";

ppp_reset_http();
ppp_queue_success();
$order1  = new PPPOrder( 1, ppp_one_item(), '1.50' );
$result1 = ppp_make_gw( array( 'merchant_calculated_tax' => true ) )->create_payment_for_order( $order1 );
$post1   = ppp_last_post_body();

check( is_array( $result1 ) && isset( $result1['url'] ), 'merchant_calculated_tax enabled → success' );
check( isset( $post1['taxDetails'] ), 'POST body contains taxDetails key' );
check_eq( 150, isset( $post1['taxDetails']['amount'] ) ? $post1['taxDetails']['amount'] : null, 'taxDetails.amount = 150 (1.50 major → 150 minor units)' );
check_eq( 'merchant_handled', isset( $post1['taxDetails']['mode'] ) ? $post1['taxDetails']['mode'] : null, 'taxDetails.mode = merchant_handled' );

// ─── Group 2: taxDetails — disabled ───────────────────────────────────────────

echo "\n🧪 Group 2: taxDetails — merchant_calculated_tax disabled\n";

ppp_reset_http();
ppp_queue_success();
$order2  = new PPPOrder( 2, ppp_one_item(), '1.50' );
$result2 = ppp_make_gw( array( 'merchant_calculated_tax' => false ) )->create_payment_for_order( $order2 );
$post2   = ppp_last_post_body();

check( ! isset( $post2['taxDetails'] ), 'no taxDetails in POST body when merchant_calculated_tax disabled' );

// ─── Group 3: taxDetails — zero-rated (still sent when enabled) ───────────────

echo "\n🧪 Group 3: taxDetails — zero-rated, still included\n";

ppp_reset_http();
ppp_queue_success();
$order3  = new PPPOrder( 3, ppp_one_item(), '0' );
$result3 = ppp_make_gw( array( 'merchant_calculated_tax' => true ) )->create_payment_for_order( $order3 );
$post3   = ppp_last_post_body();

check( isset( $post3['taxDetails'] ), 'taxDetails present for zero-rated order (sent so merchant value is authoritative)' );
check_eq( 0, isset( $post3['taxDetails']['amount'] ) ? $post3['taxDetails']['amount'] : null, 'taxDetails.amount = 0 for zero tax' );

// ─── Group 4: flexibleAmount — percentage only ────────────────────────────────

echo "\n🧪 Group 4: flexibleAmount — percentage only\n";

ppp_reset_http();
ppp_queue_success();
$order4  = new PPPOrder( 4, ppp_one_item() );
$result4 = ppp_make_gw( array(
    'flexible_amount_percentage' => '5.5',
    'flexible_amount_fixed'      => '',
    'flexible_amount_max'        => '',
) )->create_payment_for_order( $order4 );
$post4   = ppp_last_post_body();
$flex4   = isset( $post4['settings']['flexibleAmount'] ) ? $post4['settings']['flexibleAmount'] : null;

check( isset( $post4['settings']['flexibleAmount'] ), 'settings.flexibleAmount present when percentage set' );
check_eq( 5.5, isset( $flex4['percentage'] ) ? $flex4['percentage'] : null, 'flexibleAmount.percentage = 5.5' );
check( ! isset( $flex4['fixedAmount'] ), 'no fixedAmount when fixed not configured' );
check( ! isset( $flex4['maxAmount'] ), 'no maxAmount when max not configured' );

// ─── Group 5: flexibleAmount — fixed only ─────────────────────────────────────

echo "\n🧪 Group 5: flexibleAmount — fixed only\n";

ppp_reset_http();
ppp_queue_success();
$order5  = new PPPOrder( 5, ppp_one_item() );
$result5 = ppp_make_gw( array(
    'flexible_amount_percentage' => '',
    'flexible_amount_fixed'      => '200',
    'flexible_amount_max'        => '',
) )->create_payment_for_order( $order5 );
$post5   = ppp_last_post_body();
$flex5   = isset( $post5['settings']['flexibleAmount'] ) ? $post5['settings']['flexibleAmount'] : null;

check( isset( $post5['settings']['flexibleAmount'] ), 'settings.flexibleAmount present when fixed set' );
check_eq( 200, isset( $flex5['fixedAmount'] ) ? $flex5['fixedAmount'] : null, 'flexibleAmount.fixedAmount = 200' );
check( ! isset( $flex5['percentage'] ), 'no percentage when percentage not configured' );

// ─── Group 6: flexibleAmount — percentage + fixed + max ───────────────────────

echo "\n🧪 Group 6: flexibleAmount — percentage + fixed + max all set\n";

ppp_reset_http();
ppp_queue_success();
$order6  = new PPPOrder( 6, ppp_one_item() );
$result6 = ppp_make_gw( array(
    'flexible_amount_percentage' => '2.5',
    'flexible_amount_fixed'      => '100',
    'flexible_amount_max'        => '500',
) )->create_payment_for_order( $order6 );
$post6   = ppp_last_post_body();
$flex6   = isset( $post6['settings']['flexibleAmount'] ) ? $post6['settings']['flexibleAmount'] : null;

check( isset( $post6['settings']['flexibleAmount'] ), 'settings.flexibleAmount present when all three fields set' );
check_eq( 2.5, isset( $flex6['percentage'] ) ? $flex6['percentage'] : null, 'flexibleAmount.percentage = 2.5' );
check_eq( 100, isset( $flex6['fixedAmount'] ) ? $flex6['fixedAmount'] : null, 'flexibleAmount.fixedAmount = 100' );
check_eq( 500, isset( $flex6['maxAmount'] ) ? $flex6['maxAmount'] : null, 'flexibleAmount.maxAmount = 500' );

// ─── Group 7: flexibleAmount — absent when both percentage and fixed are empty ─

echo "\n🧪 Group 7: flexibleAmount — absent when both unset\n";

ppp_reset_http();
ppp_queue_success();
$order7  = new PPPOrder( 7, ppp_one_item() );
$result7 = ppp_make_gw( array(
    'flexible_amount_percentage' => '',
    'flexible_amount_fixed'      => '',
    'flexible_amount_max'        => '',
) )->create_payment_for_order( $order7 );
$post7   = ppp_last_post_body();

check( ! isset( $post7['settings'] ), 'no settings key in POST body when both percentage and fixed are empty' );

// ─── Group 8: crypto_network + crypto_token appended to payment URL ───────────

echo "\n🧪 Group 8: crypto_network and crypto_token appended to URL\n";

ppp_reset_http();
ppp_queue_success( 'https://pay.breeze.cash/p/pageCRYPTO', 'pageCRYPTO' );
$order8  = new PPPOrder( 8, ppp_one_item() );
$result8 = ppp_make_gw( array(
    'payment_methods' => array( 'crypto' ),
    'crypto_network'  => 'ethereum',
    'crypto_token'    => 'usdt',
) )->create_payment_for_order( $order8 );

check( is_array( $result8 ) && isset( $result8['url'] ), 'crypto config → success' );
check( false !== strpos( $result8['url'], 'network=ETHEREUM' ), 'URL contains network=ETHEREUM (uppercased)' );
check( false !== strpos( $result8['url'], 'token=USDT' ), 'URL contains token=USDT (uppercased)' );

// ─── Group 9: crypto params absent when 'crypto' not in payment_methods ───────

echo "\n🧪 Group 9: crypto params absent when 'crypto' not in payment_methods\n";

ppp_reset_http();
ppp_queue_success( 'https://pay.breeze.cash/p/pageCARD', 'pageCARD' );
$order9  = new PPPOrder( 9, ppp_one_item() );
$result9 = ppp_make_gw( array(
    'payment_methods' => array( 'card' ),
    'crypto_network'  => 'ethereum',
    'crypto_token'    => 'usdt',
) )->create_payment_for_order( $order9 );

check( false === strpos( $result9['url'], 'network=' ), 'no network param when crypto not in payment_methods' );
check( false === strpos( $result9['url'], 'token=' ), 'no token param when crypto not in payment_methods' );

// ─── Group 10: crypto params absent when payment_methods not configured ────────

echo "\n🧪 Group 10: crypto params absent when payment_methods not configured\n";

ppp_reset_http();
ppp_queue_success( 'https://pay.breeze.cash/p/pageNOPM', 'pageNOPM' );
$order10  = new PPPOrder( 10, ppp_one_item() );
$result10 = ppp_make_gw( array(
    'crypto_network' => 'binance',
    'crypto_token'   => 'usdt',
) )->create_payment_for_order( $order10 );

check( false === strpos( $result10['url'], 'network=' ), 'no network param when payment_methods not configured' );

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
