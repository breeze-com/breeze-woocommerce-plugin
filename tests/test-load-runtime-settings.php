<?php
/**
 * Tests for WC_Breeze_Payment_Gateway::load_runtime_settings()
 *
 * Verifies that load_runtime_settings() correctly maps stored option values to
 * runtime properties: API-key selection (testmode branch), boolean coercions
 * (testmode, debug, send_product_description, merchant_calculated_tax),
 * checkout_display normalisation (modal vs. redirect fallback), logger
 * assignment, and scalar/array pass-through.
 *
 * Run: php tests/test-load-runtime-settings.php
 */

// ─── Stubs ────────────────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        public $title       = '';
        public $description = '';
        public $enabled     = '';
        public $settings    = array();

        public function get_option( $key, $empty_value = null ) {
            return array_key_exists( $key, $this->settings )
                ? $this->settings[ $key ]
                : $empty_value;
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

$logger_stub = (object) array( 'name' => 'logger_stub' );

if ( ! function_exists( 'wc_get_logger' ) ) {
    function wc_get_logger() {
        global $logger_stub;
        return $logger_stub;
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

// ─── Helpers ─────────────────────────────────────────────────────────────────

function make_gateway() {
    $ref = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    return $ref->newInstanceWithoutConstructor();
}

function load_settings( $gateway, $settings ) {
    $gateway->settings = $settings;
    $method = new ReflectionMethod( 'WC_Breeze_Payment_Gateway', 'load_runtime_settings' );
    $method->setAccessible( true );
    $method->invoke( $gateway );
}

function get_prop( $gateway, $name ) {
    foreach ( array( 'WC_Breeze_Payment_Gateway', 'WC_Payment_Gateway' ) as $cls ) {
        try {
            $prop = new ReflectionProperty( $cls, $name );
            $prop->setAccessible( true );
            return $prop->getValue( $gateway );
        } catch ( ReflectionException $e ) {
            // try next class
        }
    }
    return null;
}

// ─── Default values (empty settings) ─────────────────────────────────────────

echo "\n🧪 Default values when settings are empty\n";

$gw = make_gateway();
load_settings( $gw, array() );

check_eq( false,   $gw->testmode,                          'testmode defaults to false' );
check_eq( false,   get_prop( $gw, 'debug' ),               'debug defaults to false' );
check_eq( 'redirect', $gw->checkout_display,               'checkout_display defaults to redirect' );
check_eq( false,   $gw->send_product_description,          'send_product_description defaults to false' );
check_eq( false,   $gw->merchant_calculated_tax,           'merchant_calculated_tax defaults to false' );
check_eq( array(), get_prop( $gw, 'payment_methods' ),     'payment_methods defaults to empty array' );
check_eq( '',      get_prop( $gw, 'crypto_network' ),      'crypto_network defaults to empty string' );
check_eq( '',      get_prop( $gw, 'crypto_token' ),        'crypto_token defaults to empty string' );
check_eq( '',      get_prop( $gw, 'webhook_secret' ),      'webhook_secret defaults to empty string' );
check( null === get_prop( $gw, 'log' ),                    'log is null when debug is off by default' );

// ─── Test mode: api_key selected from test_api_key ───────────────────────────

echo "\n🧪 Test mode: API key comes from test_api_key\n";

$gw = make_gateway();
load_settings( $gw, array(
    'testmode'     => 'yes',
    'test_api_key' => 'tk_test_TESTKEY',
    'live_api_key' => 'tk_live_LIVEKEY',
) );

check_eq( true,              $gw->testmode,                'testmode is true when option is yes' );
check_eq( 'tk_test_TESTKEY', get_prop( $gw, 'api_key' ),  'api_key uses test_api_key in test mode' );

// ─── Live mode: api_key selected from live_api_key ───────────────────────────

echo "\n🧪 Live mode: API key comes from live_api_key\n";

$gw = make_gateway();
load_settings( $gw, array(
    'testmode'     => 'no',
    'test_api_key' => 'tk_test_TESTKEY',
    'live_api_key' => 'tk_live_LIVEKEY',
) );

check_eq( false,             $gw->testmode,                'testmode is false when option is no' );
check_eq( 'tk_live_LIVEKEY', get_prop( $gw, 'api_key' ),  'api_key uses live_api_key in live mode' );

// ─── Debug on: logger is assigned ────────────────────────────────────────────

echo "\n🧪 Debug on: wc_get_logger() return value is stored in log\n";

$gw = make_gateway();
load_settings( $gw, array( 'debug' => 'yes' ) );

check_eq( true, get_prop( $gw, 'debug' ),   'debug is true when option is yes' );
check( null !== get_prop( $gw, 'log' ),      'log is non-null when debug is on' );

// ─── Debug off: logger remains null ──────────────────────────────────────────

echo "\n🧪 Debug off: log stays null\n";

$gw = make_gateway();
load_settings( $gw, array( 'debug' => 'no' ) );

check_eq( false, get_prop( $gw, 'debug' ),  'debug is false when option is no' );
check( null === get_prop( $gw, 'log' ),      'log is null when debug is off' );

// ─── checkout_display normalisation ──────────────────────────────────────────

echo "\n🧪 checkout_display normalisation\n";

$gw = make_gateway();
load_settings( $gw, array( 'checkout_display' => 'modal' ) );
check_eq( 'modal', $gw->checkout_display, "checkout_display 'modal' → 'modal'" );

$gw = make_gateway();
load_settings( $gw, array( 'checkout_display' => 'redirect' ) );
check_eq( 'redirect', $gw->checkout_display, "checkout_display 'redirect' → 'redirect'" );

$gw = make_gateway();
load_settings( $gw, array( 'checkout_display' => 'iframe' ) );
check_eq( 'redirect', $gw->checkout_display, "unrecognised value falls back to 'redirect'" );

// ─── Boolean coercions ────────────────────────────────────────────────────────

echo "\n🧪 Boolean coercions: send_product_description and merchant_calculated_tax\n";

$gw = make_gateway();
load_settings( $gw, array(
    'send_product_description' => 'yes',
    'merchant_calculated_tax'  => 'yes',
) );
check_eq( true, $gw->send_product_description, 'send_product_description yes → true' );
check_eq( true, $gw->merchant_calculated_tax,  'merchant_calculated_tax yes → true' );

$gw = make_gateway();
load_settings( $gw, array(
    'send_product_description' => 'no',
    'merchant_calculated_tax'  => 'no',
) );
check_eq( false, $gw->send_product_description, 'send_product_description no → false' );
check_eq( false, $gw->merchant_calculated_tax,  'merchant_calculated_tax no → false' );

// ─── Scalar pass-through ──────────────────────────────────────────────────────

echo "\n🧪 Scalar fields pass through from settings\n";

$gw = make_gateway();
load_settings( $gw, array(
    'title'                      => 'Pay with Breeze',
    'description'                => 'Fast and secure.',
    'enabled'                    => 'yes',
    'webhook_secret'             => 'whsec_abc123',
    'crypto_network'             => 'BINANCE',
    'crypto_token'               => 'USDT',
    'flexible_amount_max'        => '500',
    'flexible_amount_percentage' => '15',
    'flexible_amount_fixed'      => '100',
) );

check_eq( 'Pay with Breeze',  $gw->title,                              'title passes through' );
check_eq( 'Fast and secure.', $gw->description,                        'description passes through' );
check_eq( 'yes',              $gw->enabled,                            'enabled passes through raw string' );
check_eq( 'whsec_abc123',     get_prop( $gw, 'webhook_secret' ),       'webhook_secret passes through' );
check_eq( 'BINANCE',          get_prop( $gw, 'crypto_network' ),       'crypto_network passes through' );
check_eq( 'USDT',             get_prop( $gw, 'crypto_token' ),         'crypto_token passes through' );
check_eq( '500',              $gw->flexible_amount_max,                'flexible_amount_max passes through' );
check_eq( '15',               $gw->flexible_amount_percentage,         'flexible_amount_percentage passes through' );
check_eq( '100',              $gw->flexible_amount_fixed,              'flexible_amount_fixed passes through' );

// ─── payment_methods stored as-is ────────────────────────────────────────────

echo "\n🧪 payment_methods is stored as-is from settings\n";

$gw      = make_gateway();
$methods = array( 'card', 'apple_pay' );
load_settings( $gw, array( 'payment_methods' => $methods ) );
check_eq( $methods, get_prop( $gw, 'payment_methods' ), 'payment_methods array stored correctly' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
