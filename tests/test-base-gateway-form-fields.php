<?php
/**
 * Tests for WC_Breeze_Payment_Gateway::init_form_fields().
 *
 * Verifies that the base gateway registers all 19 expected settings fields with
 * the correct keys, types, and default values, and that the select/multiselect
 * option arrays contain every documented choice.
 *
 * Loads the REAL class via ReflectionClass::newInstanceWithoutConstructor() so
 * the WP/WC constructor stack is bypassed entirely — only init_form_fields() is
 * exercised.
 *
 * Run: php tests/test-base-gateway-form-fields.php
 */

// ─── Stubs/polyfills needed to load the real class standalone ─────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {}
}
if ( ! class_exists( 'WC_Log_Levels' ) ) {
    class WC_Log_Levels {
        const DEBUG = 'debug';
    }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'sprintf' ) ) {
    // sprintf is a built-in; this guard is here for documentation only.
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

// ─── Build an instance and call init_form_fields() ───────────────────────────

$gw = ( new ReflectionClass( 'WC_Breeze_Payment_Gateway' ) )->newInstanceWithoutConstructor();
$gw->init_form_fields();
$fields = $gw->form_fields;

// ─── 1. All expected keys are present ────────────────────────────────────────

echo "\n🧪 All 19 field keys registered\n";

$expected_keys = array(
    'enabled',
    'title',
    'description',
    'checkout_display',
    'testmode',
    'test_api_key',
    'live_api_key',
    'webhook_secret',
    'debug',
    'payment_methods',
    'crypto_network',
    'crypto_token',
    'send_product_description',
    'tax_section',
    'merchant_calculated_tax',
    'flexible_amount_section',
    'flexible_amount_max',
    'flexible_amount_percentage',
    'flexible_amount_fixed',
);

foreach ( $expected_keys as $key ) {
    check( array_key_exists( $key, $fields ), "'{$key}' key present" );
}

// ─── 2. Field types ───────────────────────────────────────────────────────────

echo "\n🧪 Field types are correct\n";

$expected_types = array(
    'enabled'                  => 'checkbox',
    'title'                    => 'text',
    'description'              => 'textarea',
    'checkout_display'         => 'select',
    'testmode'                 => 'checkbox',
    'test_api_key'             => 'password',
    'live_api_key'             => 'password',
    'webhook_secret'           => 'password',
    'debug'                    => 'checkbox',
    'payment_methods'          => 'multiselect',
    'crypto_network'           => 'select',
    'crypto_token'             => 'select',
    'send_product_description' => 'checkbox',
    'tax_section'              => 'title',
    'merchant_calculated_tax'  => 'checkbox',
    'flexible_amount_section'  => 'title',
    'flexible_amount_max'      => 'number',
    'flexible_amount_percentage' => 'number',
    'flexible_amount_fixed'    => 'number',
);

foreach ( $expected_types as $key => $expected_type ) {
    check(
        isset( $fields[ $key ]['type'] ) && $fields[ $key ]['type'] === $expected_type,
        "'{$key}' has type '{$expected_type}'"
    );
}

// ─── 3. Default values ────────────────────────────────────────────────────────

echo "\n🧪 Default values are correct\n";

$expected_defaults = array(
    'enabled'                  => 'no',
    'testmode'                 => 'yes',
    'test_api_key'             => '',
    'live_api_key'             => '',
    'webhook_secret'           => '',
    'debug'                    => 'no',
    'checkout_display'         => 'redirect',
    'payment_methods'          => array(),
    'crypto_network'           => '',
    'crypto_token'             => '',
    'send_product_description' => 'no',
    'merchant_calculated_tax'  => 'no',
    'flexible_amount_max'      => '',
    'flexible_amount_percentage' => '',
    'flexible_amount_fixed'    => '',
);

foreach ( $expected_defaults as $key => $expected_default ) {
    check(
        isset( $fields[ $key ]['default'] ) && $fields[ $key ]['default'] === $expected_default,
        "'{$key}' default is " . ( is_array( $expected_default ) ? '[]' : "'{$expected_default}'" )
    );
}

// ─── 4. checkout_display options ─────────────────────────────────────────────

echo "\n🧪 checkout_display options: redirect and modal\n";

$cd_options = $fields['checkout_display']['options'] ?? array();
check( array_key_exists( 'redirect', $cd_options ), "checkout_display has 'redirect' option" );
check( array_key_exists( 'modal', $cd_options ),    "checkout_display has 'modal' option" );
check( count( $cd_options ) === 2,                  "checkout_display has exactly 2 options" );

// ─── 5. payment_methods options ──────────────────────────────────────────────

echo "\n🧪 payment_methods options: all 5 documented methods present\n";

$pm_options = $fields['payment_methods']['options'] ?? array();
foreach ( array( 'apple_pay', 'google_pay', 'card', 'crypto_wallet', 'crypto_deposit' ) as $pm ) {
    check( array_key_exists( $pm, $pm_options ), "payment_methods has '{$pm}' option" );
}
check( count( $pm_options ) === 5, "payment_methods has exactly 5 options" );

// ─── 6. crypto_network options ───────────────────────────────────────────────

echo "\n🧪 crypto_network options: blank + 4 networks\n";

$cn_options = $fields['crypto_network']['options'] ?? array();
foreach ( array( '', 'BINANCE', 'ETHEREUM', 'SOLANA', 'POLYGON' ) as $net ) {
    check( array_key_exists( $net, $cn_options ), "crypto_network has '" . ( '' === $net ? '(none)' : $net ) . "' option" );
}
check( count( $cn_options ) === 5, "crypto_network has exactly 5 options" );

// ─── 7. crypto_token options ─────────────────────────────────────────────────

echo "\n🧪 crypto_token options: blank + 5 tokens\n";

$ct_options = $fields['crypto_token']['options'] ?? array();
foreach ( array( '', 'USDT', 'USDC', 'BTC', 'ETH', 'SOL' ) as $tok ) {
    check( array_key_exists( $tok, $ct_options ), "crypto_token has '" . ( '' === $tok ? '(none)' : $tok ) . "' option" );
}
check( count( $ct_options ) === 6, "crypto_token has exactly 6 options" );

// ─── 8. numeric fields have custom_attributes ─────────────────────────────────

echo "\n🧪 Number fields declare custom_attributes (min/step constraints)\n";

check( isset( $fields['flexible_amount_max']['custom_attributes']['min'] ),        "flexible_amount_max has min attribute" );
check( isset( $fields['flexible_amount_max']['custom_attributes']['step'] ),       "flexible_amount_max has step attribute" );
check( isset( $fields['flexible_amount_percentage']['custom_attributes']['min'] ), "flexible_amount_percentage has min attribute" );
check( isset( $fields['flexible_amount_percentage']['custom_attributes']['max'] ), "flexible_amount_percentage has max attribute" );
check( isset( $fields['flexible_amount_fixed']['custom_attributes']['min'] ),      "flexible_amount_fixed has min attribute" );
check( isset( $fields['flexible_amount_fixed']['custom_attributes']['step'] ),     "flexible_amount_fixed has step attribute" );

// ─── 9. payment_methods multiselect class ────────────────────────────────────

echo "\n🧪 payment_methods has wc-enhanced-select class\n";

check(
    isset( $fields['payment_methods']['class'] ) && $fields['payment_methods']['class'] === 'wc-enhanced-select',
    "payment_methods class is 'wc-enhanced-select'"
);

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
