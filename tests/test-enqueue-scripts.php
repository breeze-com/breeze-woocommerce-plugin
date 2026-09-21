<?php
/**
 * Tests for WC_Breeze_Modal_Checkout::enqueue_scripts()
 *
 * Loads the REAL WC_Breeze_Modal_Checkout class via ReflectionClass
 * (constructor skipped — it only registers WP hooks which are no-ops here).
 * Exercises every branch of enqueue_scripts() and the private
 * is_blocks_checkout() helper it delegates to.
 *
 * Covers:
 *  - not on checkout page (is_checkout false) → no enqueue calls
 *  - WC payment-gateways manager null → get_gateway() null → no enqueue calls
 *  - gateway checkout_display != 'modal' → no enqueue calls
 *  - gateway is_available() false → no enqueue calls
 *  - legacy (non-blocks) checkout → 'breeze-modal-legacy' script + jquery/wc-checkout deps
 *  - blocks checkout → 'breeze-modal-blocks' script + WC-blocks deps, no jquery
 *  - wp_localize_script breezeModalData payload (nonce, storeName, currency, siteDomain, gatewayData)
 *  - is_blocks_checkout(): page_id 0 → false; page_id -1 → false; valid page_id + has_block → value
 *
 * Run: php tests/test-enqueue-scripts.php
 */

// ─── Constants ────────────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../' );
}

define( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_DIR', __DIR__ . '/../' );
define( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_URL', 'https://example.com/wp-content/plugins/breeze/' );
define( 'BREEZE_PAYMENT_GATEWAY_VERSION', '2.2.0-test' );

// ─── Stubs / polyfills ────────────────────────────────────────────────────────

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { return $value; }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
    }
}

// Controllable: is_checkout()
$_mock_is_checkout = false;

if ( ! function_exists( 'is_checkout' ) ) {
    function is_checkout() {
        global $_mock_is_checkout;
        return $_mock_is_checkout;
    }
}

// Controllable: WC()
$_mock_wc = null;

if ( ! function_exists( 'WC' ) ) {
    function WC() {
        global $_mock_wc;
        return $_mock_wc;
    }
}

// Controllable: wc_get_page_id / has_block (used by is_blocks_checkout())
$_mock_blocks_page_id   = 0;
$_mock_has_block_result = false;

if ( ! function_exists( 'wc_get_page_id' ) ) {
    function wc_get_page_id( $page ) {
        global $_mock_blocks_page_id;
        return $_mock_blocks_page_id;
    }
}

if ( ! function_exists( 'has_block' ) ) {
    function has_block( $block, $post_id = null ) {
        global $_mock_has_block_result;
        return $_mock_has_block_result;
    }
}

// wp_enqueue_style / wp_enqueue_script / wp_localize_script call logs
$_enqueue_style_log  = array();
$_enqueue_script_log = array();
$_localize_log       = array();

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
        global $_enqueue_style_log;
        $_enqueue_style_log[] = compact( 'handle', 'src', 'deps', 'ver' );
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
        global $_enqueue_script_log;
        $_enqueue_script_log[] = compact( 'handle', 'src', 'deps', 'ver', 'in_footer' );
    }
}

if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( $handle, $object_name, $l10n ) {
        global $_localize_log;
        $_localize_log[] = compact( 'handle', 'object_name', 'l10n' );
    }
}

if ( ! function_exists( 'get_site_url' ) ) {
    function get_site_url() { return 'https://store.example.com'; }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'https://store.example.com/wp-admin/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ) { return 'test-nonce-abc'; }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $show = '' ) { return 'My Test Store'; }
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
    function get_woocommerce_currency() { return 'USD'; }
}

if ( ! function_exists( 'wc_get_checkout_url' ) ) {
    function wc_get_checkout_url() { return 'https://store.example.com/checkout/'; }
}

// Minimal WC_Breeze_Payment_Gateway stub with all properties/methods used in enqueue_scripts().
if ( ! class_exists( 'WC_Breeze_Payment_Gateway' ) ) {
    class WC_Breeze_Payment_Gateway {
        public $checkout_display = 'modal';
        public $enabled          = 'yes';
        public $available        = true;
        public function is_available()   { return $this->available; }
        public function get_title()      { return 'Breeze'; }
        public function get_description() { return 'Pay with Breeze.'; }
    }
}

// WC payment-gateways manager stub.
class Mock_WC_Payment_Gateways {
    private $gateways;
    public function __construct( array $gateways = array() ) {
        $this->gateways = $gateways;
    }
    public function payment_gateways() { return $this->gateways; }
}

// WC() root mock — holds a payment-gateways manager (or null).
class Mock_WC {
    public $pg;
    public function __construct( $pg = null ) { $this->pg = $pg; }
    public function payment_gateways()        { return $this->pg; }
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

// ─── Helpers ──────────────────────────────────────────────────────────────────

function make_modal() {
    $ref = new ReflectionClass( 'WC_Breeze_Modal_Checkout' );
    return $ref->newInstanceWithoutConstructor();
}

function reset_logs() {
    global $_enqueue_style_log, $_enqueue_script_log, $_localize_log;
    $_enqueue_style_log  = array();
    $_enqueue_script_log = array();
    $_localize_log       = array();
}

function wire_gateway( $gateway ) {
    global $_mock_wc;
    $pg       = new Mock_WC_Payment_Gateways( array( 'breeze_payment_gateway' => $gateway ) );
    $_mock_wc = new Mock_WC( $pg );
}

// ─── 1. Not on checkout page → early return ───────────────────────────────────

echo "\n🧪 1. is_checkout() false → early return, nothing enqueued\n";

global $_mock_is_checkout, $_mock_wc;
$_mock_is_checkout = false;
$_mock_wc          = null;
reset_logs();

make_modal()->enqueue_scripts();

check( empty( $_enqueue_style_log ),  'wp_enqueue_style not called when not on checkout page' );
check( empty( $_enqueue_script_log ), 'wp_enqueue_script not called when not on checkout page' );
check( empty( $_localize_log ),       'wp_localize_script not called when not on checkout page' );

// ─── 2. WC payment-gateways manager null → get_gateway() null → early return ──

echo "\n🧪 2. WC()->payment_gateways() null → get_gateway() null → early return\n";

$_mock_is_checkout = true;
$_mock_wc          = new Mock_WC( null ); // payment_gateways() returns null
reset_logs();

make_modal()->enqueue_scripts();

check( empty( $_enqueue_style_log ),  'wp_enqueue_style not called when gateway is null' );
check( empty( $_enqueue_script_log ), 'wp_enqueue_script not called when gateway is null' );

// ─── 3. checkout_display != 'modal' → early return ───────────────────────────

echo "\n🧪 3. checkout_display is 'redirect' → early return\n";

$_mock_is_checkout    = true;
$gw                   = new WC_Breeze_Payment_Gateway();
$gw->checkout_display = 'redirect';
wire_gateway( $gw );
reset_logs();

make_modal()->enqueue_scripts();

check( empty( $_enqueue_style_log ),  'wp_enqueue_style not called when display is not modal' );
check( empty( $_enqueue_script_log ), 'wp_enqueue_script not called when display is not modal' );

// ─── 4. is_available() false → early return ───────────────────────────────────

echo "\n🧪 4. is_available() false → early return\n";

$_mock_is_checkout    = true;
$gw                   = new WC_Breeze_Payment_Gateway();
$gw->checkout_display = 'modal';
$gw->available        = false;
wire_gateway( $gw );
reset_logs();

make_modal()->enqueue_scripts();

check( empty( $_enqueue_style_log ),  'wp_enqueue_style not called when gateway unavailable' );
check( empty( $_enqueue_script_log ), 'wp_enqueue_script not called when gateway unavailable' );

// ─── 5. Legacy (non-blocks) checkout ─────────────────────────────────────────

echo "\n🧪 5. Legacy checkout — breeze-modal-legacy, jquery + wc-checkout deps, full localize payload\n";

global $_mock_blocks_page_id, $_mock_has_block_result;
$_mock_is_checkout      = true;
$_mock_blocks_page_id   = 0;     // wc_get_page_id returns 0 → is_blocks_checkout returns false
$_mock_has_block_result = false;

$gw                   = new WC_Breeze_Payment_Gateway();
$gw->checkout_display = 'modal';
$gw->available        = true;
wire_gateway( $gw );
reset_logs();

make_modal()->enqueue_scripts();

check( ! empty( $_enqueue_script_log ), 'wp_enqueue_script was called for legacy checkout' );
if ( ! empty( $_enqueue_script_log ) ) {
    $s = $_enqueue_script_log[0];
    check_eq( 'breeze-modal-legacy',                          $s['handle'],     'Script handle is breeze-modal-legacy' );
    check( strpos( $s['src'], 'breeze-modal-legacy.js' ) !== false,             'Src contains breeze-modal-legacy.js' );
    check( in_array( 'jquery',      $s['deps'], true ),                         'jquery in legacy deps' );
    check( in_array( 'wc-checkout', $s['deps'], true ),                         'wc-checkout in legacy deps' );
    check( ! in_array( 'wc-blocks-registry', $s['deps'], true ),                'wc-blocks-registry NOT in legacy deps' );
    check_eq( true,                                           $s['in_footer'],  'Script enqueued in footer' );
}

check( ! empty( $_enqueue_style_log ), 'wp_enqueue_style was called' );
if ( ! empty( $_enqueue_style_log ) ) {
    $st = $_enqueue_style_log[0];
    check_eq( 'breeze-modal',                                 $st['handle'],    'Style handle is breeze-modal' );
    check( strpos( $st['src'], 'breeze-modal.css' ) !== false,                  'Style src contains breeze-modal.css' );
}

check( ! empty( $_localize_log ), 'wp_localize_script was called' );
if ( ! empty( $_localize_log ) ) {
    $loc  = $_localize_log[0];
    $data = $loc['l10n'];
    check_eq( 'breeze-modal-legacy', $loc['handle'],                            'Localize handle matches script handle' );
    check_eq( 'breezeModalData',     $loc['object_name'],                       'Object name is breezeModalData' );
    check( isset( $data['ajaxUrl'] ),                                           'breezeModalData has ajaxUrl key' );
    check( strpos( $data['ajaxUrl'], 'admin-ajax.php' ) !== false,              'ajaxUrl contains admin-ajax.php' );
    check_eq( 'test-nonce-abc',     $data['nonce'],                             'nonce value from wp_create_nonce' );
    check_eq( 'My Test Store',      $data['storeName'],                         'storeName from get_bloginfo' );
    check_eq( 'USD',                $data['currency'],                          'currency from get_woocommerce_currency' );
    check_eq( 'store.example.com',  $data['siteDomain'],                        'siteDomain is host extracted from get_site_url' );
    check( is_array( $data['breezeDomains'] ),                                  'breezeDomains is array' );
    check( ! empty( $data['breezeDomains'] ),                                   'breezeDomains is non-empty' );
    $gd = $data['gatewayData'];
    check_eq( 'Breeze',             $gd['title'],                               'gatewayData.title from get_title()' );
    check_eq( 'Pay with Breeze.',   $gd['description'],                         'gatewayData.description from get_description()' );
    check_eq( true,                 $gd['enabled'],                             'gatewayData.enabled true when $enabled = yes' );
}

// ─── 6. Blocks checkout ───────────────────────────────────────────────────────

echo "\n🧪 6. Blocks checkout — breeze-modal-blocks, WC blocks deps, no jquery\n";

$_mock_is_checkout      = true;
$_mock_blocks_page_id   = 5;    // valid page ID → enters has_block check
$_mock_has_block_result = true; // has woocommerce/checkout block

$gw                   = new WC_Breeze_Payment_Gateway();
$gw->checkout_display = 'modal';
$gw->available        = true;
wire_gateway( $gw );
reset_logs();

make_modal()->enqueue_scripts();

check( ! empty( $_enqueue_script_log ), 'wp_enqueue_script called for blocks checkout' );
if ( ! empty( $_enqueue_script_log ) ) {
    $s = $_enqueue_script_log[0];
    check_eq( 'breeze-modal-blocks',                           $s['handle'],    'Script handle is breeze-modal-blocks' );
    check( strpos( $s['src'], 'breeze-modal-blocks.js' ) !== false,             'Src contains breeze-modal-blocks.js' );
    check( in_array( 'wc-blocks-registry', $s['deps'], true ),                  'wc-blocks-registry in blocks deps' );
    check( in_array( 'wp-element',         $s['deps'], true ),                  'wp-element in blocks deps' );
    check( ! in_array( 'jquery',           $s['deps'], true ),                  'jquery NOT in blocks deps' );
}

check( ! empty( $_localize_log ), 'wp_localize_script called for blocks checkout' );
if ( ! empty( $_localize_log ) ) {
    check_eq( 'breeze-modal-blocks', $_localize_log[0]['handle'],               'Localize handle matches blocks script' );
}

// ─── 7. is_blocks_checkout() branches via page ID values ─────────────────────

echo "\n🧪 7. is_blocks_checkout() page_id guards (exercised via enqueue_scripts)\n";

// page_id = 0 → falsy → is_blocks_checkout false → legacy path (covered in test 5)
// page_id = -1 → < 1 → is_blocks_checkout false → legacy path
$_mock_is_checkout      = true;
$_mock_blocks_page_id   = -1;
$_mock_has_block_result = true; // would return true if page_id were valid

$gw                   = new WC_Breeze_Payment_Gateway();
$gw->checkout_display = 'modal';
$gw->available        = true;
wire_gateway( $gw );
reset_logs();

make_modal()->enqueue_scripts();

check( ! empty( $_enqueue_script_log ), 'enqueue_scripts ran (page_id = -1)' );
if ( ! empty( $_enqueue_script_log ) ) {
    check_eq( 'breeze-modal-legacy', $_enqueue_script_log[0]['handle'],
        'page_id = -1: is_blocks_checkout false → legacy handle used' );
}

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
