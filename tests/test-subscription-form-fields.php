<?php
/**
 * Tests for WC_Breeze_Subscription_Gateway::init_form_fields().
 *
 * The subscription gateway extends the base gateway and strips seven
 * crypto/flexible-amount fields that are irrelevant to fiat-only subscriptions.
 * This test verifies the removal contract and confirms the shared settings
 * fields (API keys, webhook secret, tax, etc.) are still present.
 *
 * Loads the REAL classes via ReflectionClass::newInstanceWithoutConstructor()
 * so the WP/WC constructor stack is bypassed entirely — only init_form_fields()
 * is exercised.
 *
 * Run: php tests/test-subscription-form-fields.php
 */

// ─── Stubs/polyfills needed to load the real classes standalone ───────────────

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

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';
require_once __DIR__ . '/../includes/class-wc-breeze-subscription-gateway.php';

// ─── Assert harness ───────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function check( $cond, $label ) {
    global $passed, $failed;
    if ( $cond ) { echo "  ✅ {$label}\n"; $passed++; }
    else         { echo "  ❌ {$label}\n"; $failed++; }
}

// ─── Build an instance and call init_form_fields() ───────────────────────────

$gw = ( new ReflectionClass( 'WC_Breeze_Subscription_Gateway' ) )->newInstanceWithoutConstructor();
$gw->init_form_fields();
$fields = $gw->form_fields;

// ─── Keys that must be REMOVED (crypto + flexible-amount — fiat-only gateway) ─

echo "\n🧪 Removed fields: subscription gateway strips crypto and flexible-amount keys\n";

$removed = array(
    'payment_methods',
    'crypto_network',
    'crypto_token',
    'flexible_amount_section',
    'flexible_amount_max',
    'flexible_amount_percentage',
    'flexible_amount_fixed',
);
foreach ( $removed as $key ) {
    check( ! array_key_exists( $key, $fields ), "'{$key}' removed from subscription form fields" );
}

// ─── Keys that must be PRESENT (shared gateway settings) ─────────────────────

echo "\n🧪 Retained fields: shared gateway settings survive in the subscription gateway\n";

$retained = array(
    'enabled',
    'title',
    'description',
    'checkout_display',
    'testmode',
    'test_api_key',
    'live_api_key',
    'webhook_secret',
    'debug',
    'send_product_description',
    'tax_section',
    'merchant_calculated_tax',
);
foreach ( $retained as $key ) {
    check( array_key_exists( $key, $fields ), "'{$key}' retained in subscription form fields" );
}

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
