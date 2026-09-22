<?php
/**
 * Tests for WC_Breeze_Blocks_Support
 *
 * Covers:
 *   - is_active():                         null gateway; unavailable; available
 *   - get_supported_features():            empty and non-empty gateway->supports
 *   - get_payment_method_data():           all five return keys (title, description,
 *                                          supports, testMode, icon)
 *   - get_payment_method_script_handles(): asset file exists → deps+version from file;
 *                                          returns ['wc-breeze-blocks']; registers script
 *   - add_payment_request_data():          matching and non-matching payment_method
 *
 * Loads the REAL WC_Breeze_Blocks_Support class via ReflectionClass without
 * invoking the WP-dependent constructor. AbstractPaymentMethodType is stubbed via
 * a namespace block; gateway dependencies injected through ReflectionProperty.
 *
 * Run: php tests/test-blocks-support.php
 */

// ─── Namespace stub — AbstractPaymentMethodType ───────────────────────────────
// The real class lives in Automattic\WooCommerce\Blocks\Payments\Integrations.
// We stub just what WC_Breeze_Blocks_Support calls (get_setting, $settings, $name).

namespace Automattic\WooCommerce\Blocks\Payments\Integrations {

    abstract class AbstractPaymentMethodType {
        protected $name;
        protected $settings = array();

        public function get_setting( $key, $default = '' ) {
            return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
        }
    }
}

// ─── Global namespace ─────────────────────────────────────────────────────────

namespace {

    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ . '/' );
    }
    // Point PLUGIN_DIR at the real repo root so the asset.php file_exists check passes.
    if ( ! defined( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_DIR' ) ) {
        define( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
    }
    if ( ! defined( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_URL' ) ) {
        define( 'BREEZE_PAYMENT_GATEWAY_PLUGIN_URL', 'https://example.test/wp-content/plugins/breeze/' );
    }
    if ( ! defined( 'BREEZE_PAYMENT_GATEWAY_VERSION' ) ) {
        define( 'BREEZE_PAYMENT_GATEWAY_VERSION', '2.2.0' );
    }

    // ─── Minimal WP/WC polyfills ──────────────────────────────────────────────

    if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
    if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
    if ( ! function_exists( 'apply_filters' ) ) {
        function apply_filters( $tag, $value ) { return $value; }
    }
    if ( ! function_exists( 'is_checkout' ) )      { function is_checkout() { return false; } }
    if ( ! function_exists( 'is_wc_endpoint_url' ) ) { function is_wc_endpoint_url() { return false; } }
    if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style() {} }

    // Capture wp_register_script calls for inspection.
    $register_script_log = array();

    if ( ! function_exists( 'wp_register_script' ) ) {
        function wp_register_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
            global $register_script_log;
            $register_script_log[] = compact( 'handle', 'src', 'deps', 'ver', 'in_footer' );
        }
    }

    require_once __DIR__ . '/../includes/class-wc-breeze-blocks-support.php';

    // ─── Mock gateway ─────────────────────────────────────────────────────────

    class Mock_Blocks_Gateway {
        public $testmode  = 'yes';
        public $icon      = 'https://example.test/icon.png';
        public $supports  = array( 'products', 'refunds' );
        public $available = true;

        public function is_available() {
            return $this->available;
        }
    }

    // ─── Assertion helpers ────────────────────────────────────────────────────

    $pass = 0;
    $fail = 0;

    function assert_true( $val, $label ) {
        global $pass, $fail;
        if ( $val ) {
            echo "  PASS: $label\n";
            $pass++;
        } else {
            echo "  FAIL: $label\n";
            $fail++;
        }
    }

    function assert_equal( $expected, $actual, $label ) {
        global $pass, $fail;
        if ( $expected === $actual ) {
            echo "  PASS: $label\n";
            $pass++;
        } else {
            echo "  FAIL: $label — expected " . var_export( $expected, true )
                . ', got ' . var_export( $actual, true ) . "\n";
            $fail++;
        }
    }

    /**
     * Build a WC_Breeze_Blocks_Support instance without running the constructor.
     *
     * @param Mock_Blocks_Gateway|null $gateway  Injected gateway.
     * @param array                    $settings Injected settings (title, description, …).
     * @return WC_Breeze_Blocks_Support
     */
    function make_blocks_support( $gateway = null, $settings = array() ) {
        $ref = new ReflectionClass( 'WC_Breeze_Blocks_Support' );
        $obj = $ref->newInstanceWithoutConstructor();

        // gateway is private on WC_Breeze_Blocks_Support.
        $gw_prop = $ref->getProperty( 'gateway' );
        $gw_prop->setAccessible( true );
        $gw_prop->setValue( $obj, $gateway );

        // settings is protected on AbstractPaymentMethodType (the parent).
        $parent   = $ref->getParentClass();
        $set_prop = $parent->getProperty( 'settings' );
        $set_prop->setAccessible( true );
        $set_prop->setValue( $obj, $settings );

        return $obj;
    }

    // ─── is_active() ─────────────────────────────────────────────────────────

    echo "\n=== is_active() ===\n";

    $obj = make_blocks_support( null );
    assert_equal( false, $obj->is_active(), 'null gateway → false' );

    $gw            = new Mock_Blocks_Gateway();
    $gw->available = false;
    $obj           = make_blocks_support( $gw );
    assert_equal( false, $obj->is_active(), 'gateway not available → false' );

    $gw            = new Mock_Blocks_Gateway();
    $gw->available = true;
    $obj           = make_blocks_support( $gw );
    assert_equal( true, $obj->is_active(), 'gateway available → true' );

    // ─── get_supported_features() ─────────────────────────────────────────────

    echo "\n=== get_supported_features() ===\n";

    $gw           = new Mock_Blocks_Gateway();
    $gw->supports = array();
    $obj          = make_blocks_support( $gw );
    $features     = $obj->get_supported_features();
    assert_true( is_array( $features ), 'empty supports → returns array' );
    assert_equal( 0, count( $features ), 'empty supports → zero items' );

    $gw           = new Mock_Blocks_Gateway();
    $gw->supports = array( 'products', 'refunds' );
    $obj          = make_blocks_support( $gw );
    $features     = $obj->get_supported_features();
    assert_equal( array( 'products', 'refunds' ), $features, 'non-empty supports → same values returned' );
    assert_equal( 2, count( $features ), 'non-empty supports → correct count' );

    // ─── get_payment_method_data() ────────────────────────────────────────────

    echo "\n=== get_payment_method_data() ===\n";

    $gw           = new Mock_Blocks_Gateway();
    $gw->testmode = 'yes';
    $gw->icon     = 'https://example.test/icon.png';
    $gw->supports = array( 'products' );
    $obj          = make_blocks_support(
        $gw,
        array( 'title' => 'Pay with Breeze', 'description' => 'Fast checkout' )
    );
    $data = $obj->get_payment_method_data();

    assert_true( is_array( $data ), 'returns array' );
    assert_equal( 'Pay with Breeze', $data['title'], 'title sourced from settings' );
    assert_equal( 'Fast checkout', $data['description'], 'description sourced from settings' );
    assert_equal( 'yes', $data['testMode'], 'testMode sourced from gateway->testmode' );
    assert_equal( 'https://example.test/icon.png', $data['icon'], 'icon sourced from gateway->icon' );
    assert_true( array_key_exists( 'supports', $data ), 'supports key present' );
    assert_equal( array( 'products' ), $data['supports'], 'supports value matches gateway features' );

    // ─── get_payment_method_script_handles() ─────────────────────────────────

    echo "\n=== get_payment_method_script_handles() ===\n";

    global $register_script_log;
    $register_script_log = array();

    $obj     = make_blocks_support( new Mock_Blocks_Gateway() );
    $handles = $obj->get_payment_method_script_handles();

    assert_equal( array( 'wc-breeze-blocks' ), $handles, 'returns [wc-breeze-blocks]' );
    assert_equal( 1, count( $register_script_log ), 'wp_register_script called once' );

    $call = $register_script_log[0];
    assert_equal( 'wc-breeze-blocks', $call['handle'], 'registered handle is wc-breeze-blocks' );
    assert_true(
        false !== strpos( $call['src'], '/assets/js/blocks/breeze-blocks.js' ),
        'src contains the asset path'
    );
    assert_equal( true, $call['in_footer'], 'script registered for footer' );

    // Asset file exists at PLUGIN_DIR; version + deps come from the asset file.
    $asset = require BREEZE_PAYMENT_GATEWAY_PLUGIN_DIR . 'assets/js/blocks/breeze-blocks.asset.php';
    assert_equal( $asset['version'], $call['ver'], 'version from asset file' );
    assert_equal( $asset['dependencies'], $call['deps'], 'deps from asset file' );

    // ─── add_payment_request_data() ───────────────────────────────────────────

    echo "\n=== add_payment_request_data() ===\n";

    $obj    = make_blocks_support( new Mock_Blocks_Gateway() );
    $result = null;

    $ctx_match                 = new stdClass();
    $ctx_match->payment_method = 'breeze_payment_gateway';
    $obj->add_payment_request_data( $ctx_match, $result );
    assert_true( true, 'matching payment_method — no crash' );

    $ctx_other                 = new stdClass();
    $ctx_other->payment_method = 'stripe';
    $obj->add_payment_request_data( $ctx_other, $result );
    assert_true( true, 'non-matching payment_method — no crash' );

    // ─── Summary ──────────────────────────────────────────────────────────────

    echo "\n" . str_repeat( '-', 50 ) . "\n";
    echo "Results: $pass passed, $fail failed\n";

    if ( $fail > 0 ) {
        exit( 1 );
    }

} // end global namespace
