<?php
/**
 * Tests for the pass-through fee and crypto-param logic.
 *
 * Unlike the mirror-style helpers elsewhere in the suite, these tests load the
 * REAL WC_Breeze_Payment_Gateway class and call its actual static methods, so
 * the assertions track production behavior rather than a copy of it.
 *
 * Run: php tests/test-passthrough-fee-crypto.php
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

// ─── compute_passthrough_fee_minor_units() — real method ─────────────────────

echo "\n🧪 Pass-through fee: compute_passthrough_fee_minor_units()\n";

$one_item   = array( array( 'amount' => 1000, 'quantity' => 1 ) );
$multi_qty  = array( array( 'amount' => 500, 'quantity' => 3 ) );
$with_ship  = array(
    array( 'amount' => 1000, 'quantity' => 1 ),
    array( 'amount' => 250, 'quantity' => 1 ), // e.g. shipping line
);

check_eq( 0,  WC_Breeze_Payment_Gateway::compute_passthrough_fee_minor_units( $one_item, '', '', '' ),            'Disabled type → 0' );
check_eq( 0,  WC_Breeze_Payment_Gateway::compute_passthrough_fee_minor_units( $one_item, 'fixed', '', '' ),       'Fixed type but blank amount → 0' );
check_eq( 0,  WC_Breeze_Payment_Gateway::compute_passthrough_fee_minor_units( $one_item, 'percentage', '', '' ),  'Percentage type but blank value → 0' );
check_eq( 49, WC_Breeze_Payment_Gateway::compute_passthrough_fee_minor_units( $one_item, 'fixed', '49', '' ),     'Fixed 49 → 49' );
check_eq( 49, WC_Breeze_Payment_Gateway::compute_passthrough_fee_minor_units( $one_item, 'percentage', '', '4.9' ), '4.9% of 1000 → 49' );
check_eq( 45, WC_Breeze_Payment_Gateway::compute_passthrough_fee_minor_units( $multi_qty, 'percentage', '', '3' ),  '3% of 3×500 → 45 (multi-qty summed)' );
check_eq( 50, WC_Breeze_Payment_Gateway::compute_passthrough_fee_minor_units( array( array( 'amount' => 999, 'quantity' => 1 ) ), 'percentage', '', '5' ), '5% of 999 rounds to 50' );
check_eq( 63, WC_Breeze_Payment_Gateway::compute_passthrough_fee_minor_units( $with_ship, 'percentage', '', '5' ),  '5% applies to full line-item total incl. shipping (1250 → 63)' );
// Fixed takes precedence; percentage ignored when type is 'fixed'.
check_eq( 49, WC_Breeze_Payment_Gateway::compute_passthrough_fee_minor_units( $one_item, 'fixed', '49', '99' ),    'Fixed type ignores percentage value' );

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
