<?php
/**
 * Tests for WC_Breeze_Payment_Gateway::payment_fields()
 *
 * Verifies that payment_fields() renders the description (via wp_kses_post +
 * wpautop) when $this->description is truthy, renders a test-mode notice when
 * $this->testmode is true, and produces no output when neither is set.
 * Output is captured with output buffering.
 *
 * Run: php tests/test-payment-fields.php
 */

// ─── Stubs/polyfills ──────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        public $description = '';
    }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

// Track calls so tests can assert the pipeline: wp_kses_post → wpautop → echo.
$kses_calls   = array();
$autop_calls  = array();

if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $html ) {
        global $kses_calls;
        $kses_calls[] = $html;
        return $html; // passthrough
    }
}
if ( ! function_exists( 'wpautop' ) ) {
    function wpautop( $text ) {
        global $autop_calls;
        $autop_calls[] = $text;
        return '<p>' . $text . "</p>\n"; // minimal wrapping for assertion
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

// ─── Helper: build a gateway instance without running the WP constructor ──────

function make_gateway() {
    $ref = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gw  = $ref->newInstanceWithoutConstructor();
    // Ensure clean defaults matching property declarations.
    $gw->description = '';
    $gw->testmode    = false;
    return $gw;
}

function capture( $gateway ) {
    ob_start();
    $gateway->payment_fields();
    return ob_get_clean();
}

// ─── 1. No description, no testmode → no output ───────────────────────────────

echo "\n🧪 No description, no testmode\n";

$gw     = make_gateway();
$output = capture( $gw );

check_eq( '', $output, 'output is empty when description and testmode are both unset' );

// ─── 2. Description set, testmode off ─────────────────────────────────────────

echo "\n🧪 Description set, testmode off\n";

$kses_calls  = array();
$autop_calls = array();

$gw              = make_gateway();
$gw->description = 'Pay securely via Breeze.';
$output          = capture( $gw );

check( strlen( $output ) > 0,                             'output is non-empty when description is set' );
check( strpos( $output, 'Pay securely via Breeze.' ) !== false, 'output contains the description text' );
check( strpos( $output, '<p>' ) !== false,                'wpautop wraps description in <p> tag' );
check( strpos( $output, 'TEST MODE' ) === false,          'no test-mode notice when testmode is false' );

// Verify the pipeline: wp_kses_post received the raw description.
check( count( $kses_calls ) === 1,                        'wp_kses_post called once' );
check_eq( 'Pay securely via Breeze.', $kses_calls[0],     'wp_kses_post received the raw description' );

// wpautop received the wp_kses_post output.
check( count( $autop_calls ) === 1,                       'wpautop called once' );
check_eq( 'Pay securely via Breeze.', $autop_calls[0],    'wpautop received the kses-filtered description' );

// ─── 3. Description with HTML passes through the pipeline ─────────────────────

echo "\n🧪 Description with HTML markup\n";

$kses_calls  = array();
$autop_calls = array();

$gw              = make_gateway();
$gw->description = 'Pay with <strong>Breeze</strong>.';
$output          = capture( $gw );

check( strpos( $output, 'Pay with' ) !== false,           'description text is present in output' );
check( strpos( $output, '<strong>' ) !== false,           'HTML passes through wp_kses_post polyfill' );

// ─── 4. Falsy description values produce no output ────────────────────────────

echo "\n🧪 Falsy description values → no output\n";

$gw              = make_gateway();
$gw->description = '';
check_eq( '', capture( $gw ), "empty string description → no output" );

$gw              = make_gateway();
$gw->description = '0';
check_eq( '', capture( $gw ), "'0' description (PHP falsy) → no output" );

// ─── 5. testmode true, no description ────────────────────────────────────────

echo "\n🧪 testmode true, no description\n";

$gw           = make_gateway();
$gw->testmode = true;
$output       = capture( $gw );

check( strpos( $output, 'TEST MODE ENABLED' ) !== false,  'test-mode notice is present' );
check( strpos( $output, '<p>' ) !== false,                'test-mode notice is wrapped in <p>' );
check( strpos( $output, '</p>' ) !== false,               'test-mode notice closing </p> is present' );
check( strpos( $output, 'Pay securely' ) === false,       'description text absent when description not set' );

// ─── 6. testmode false → no notice ────────────────────────────────────────────

echo "\n🧪 testmode false → no notice\n";

$gw           = make_gateway();
$gw->testmode = false;
$output       = capture( $gw );

check( strpos( $output, 'TEST MODE' ) === false,          'no test-mode notice when testmode is false' );

// ─── 7. Description + testmode → both present, description first ──────────────

echo "\n🧪 Description + testmode → both rendered in order\n";

$gw              = make_gateway();
$gw->description = 'Pay securely via Breeze.';
$gw->testmode    = true;
$output          = capture( $gw );

check( strpos( $output, 'Pay securely via Breeze.' ) !== false, 'description is present' );
check( strpos( $output, 'TEST MODE ENABLED' ) !== false,        'test-mode notice is present' );

$desc_pos  = strpos( $output, 'Pay securely via Breeze.' );
$tmode_pos = strpos( $output, 'TEST MODE ENABLED' );
check( $desc_pos < $tmode_pos, 'description appears before test-mode notice in output' );

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
