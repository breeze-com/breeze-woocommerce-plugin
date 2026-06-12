<?php
/**
 * Tests for the crypto-param and merchant-calculated-tax logic.
 *
 * Unlike the mirror-style helpers elsewhere in the suite, these tests load the
 * REAL WC_Breeze_Payment_Gateway class and call its actual static methods, so
 * the assertions track production behavior rather than a copy of it.
 *
 * Run: php tests/test-crypto-tax.php
 */

// ─── Stubs/polyfills needed to load the real class standalone ────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    // Minimal base so the gateway class can be parsed/loaded without WooCommerce.
    class WC_Payment_Gateway {}
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Tiny assert harness (matches the rest of the suite's output style) ──────

$passed = 0;
$failed = 0;

function check( $cond, $label ) {
    global $passed, $failed;
    if ( $cond ) { echo "  ✅ {$label}\n"; $passed++; }
    else         { echo "  ❌ {$label}\n"; $failed++; }
}

function check_eq( $expected, $actual, $label ) {
    check( $expected === $actual, sprintf( '%s (expected %s, got %s)', $label, var_export( $expected, true ), var_export( $actual, true ) ) );
}

// ─── should_append_crypto_params() — real method (the issue #1 gating fix) ───

echo "\n🧪 Crypto gating: should_append_crypto_params()\n";

check_eq( true,  WC_Breeze_Payment_Gateway::should_append_crypto_params( array( 'crypto_deposit' ) ),              'crypto_deposit selected → append' );
check_eq( true,  WC_Breeze_Payment_Gateway::should_append_crypto_params( array( 'apple_pay', 'crypto_deposit' ) ), 'crypto_deposit among others → append' );
check_eq( true,  WC_Breeze_Payment_Gateway::should_append_crypto_params( array( 'crypto' ) ),                      'legacy crypto value → append (back-compat)' );
check_eq( false, WC_Breeze_Payment_Gateway::should_append_crypto_params( array( 'crypto_wallet' ) ),              'crypto_wallet only → do NOT append (deposit-specific)' );
check_eq( false, WC_Breeze_Payment_Gateway::should_append_crypto_params( array( 'apple_pay' ) ),                  'only apple_pay → do NOT append' );
check_eq( false, WC_Breeze_Payment_Gateway::should_append_crypto_params( array() ),                              'none selected → do NOT append' );
check_eq( false, WC_Breeze_Payment_Gateway::should_append_crypto_params( '' ),                                   'non-array → do NOT append' );

// ─── build_tax_details() — real method (merchant-calculated tax) ─────────────

echo "\n🧪 Merchant-calculated tax: build_tax_details()\n";

check_eq( null, WC_Breeze_Payment_Gateway::build_tax_details( false, 1.80 ), 'Disabled → null (no taxDetails sent)' );
check_eq( null, WC_Breeze_Payment_Gateway::build_tax_details( false, 0 ),    'Disabled with zero tax → still null' );

check_eq(
    array( 'amount' => 180, 'mode' => 'merchant_handled' ),
    WC_Breeze_Payment_Gateway::build_tax_details( true, 1.80 ),
    'Enabled, $1.80 tax → 180 minor units, merchant_handled'
);
check_eq(
    array( 'amount' => 0, 'mode' => 'merchant_handled' ),
    WC_Breeze_Payment_Gateway::build_tax_details( true, 0 ),
    'Enabled, $0 tax → 0 sent (zero-rated is valid)'
);
check_eq(
    array( 'amount' => 1600, 'mode' => 'merchant_handled' ),
    WC_Breeze_Payment_Gateway::build_tax_details( true, 16.00 ),
    'Enabled, $16.00 tax → 1600 minor units'
);
// Float-to-cents rounding parity with the rest of the plugin (round half away from zero).
check_eq(
    array( 'amount' => 181, 'mode' => 'merchant_handled' ),
    WC_Breeze_Payment_Gateway::build_tax_details( true, 1.805 ),
    '$1.805 rounds to 181 minor units'
);

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
