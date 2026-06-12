<?php
/**
 * Tests for filter_gateway_icon_html() — the 24×24 icon constraint filter.
 *
 * Loads the real WC_Breeze_Payment_Gateway class and calls the actual public
 * filter_gateway_icon_html() method, so assertions track production behaviour
 * rather than a copy of it.
 *
 * Run: php tests/test-filter-gateway-icon.php
 */

// ─── Stubs/polyfills needed to load the real class standalone ────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    // Minimal stub so the gateway class can be parsed without WooCommerce.
    // Declares the public properties filter_gateway_icon_html() reads.
    class WC_Payment_Gateway {
        public $id           = '';
        public $icon         = '';
        public $method_title = '';
    }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) {
        return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Tiny assert harness (matches the rest of the suite's style) ──────────────

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

// ─── Helper: build a gateway instance without running the constructor ─────────

function make_icon_gateway( $icon_url, $method_title, $gateway_id = 'breeze_payment_gateway' ) {
    $ref     = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gateway = $ref->newInstanceWithoutConstructor();

    // id, icon, method_title are public — assign directly.
    $gateway->id           = $gateway_id;
    $gateway->icon         = $icon_url;
    $gateway->method_title = $method_title;

    return $gateway;
}

$icon_url     = 'https://example.com/breeze-icon.png';
$method_title = 'Breeze';
$gateway      = make_icon_gateway( $icon_url, $method_title );

// ─── Passthrough: other gateways are returned unchanged ───────────────────────

echo "\n🧪 filter_gateway_icon_html(): passthrough for other gateways\n";

$other_html = '<img src="https://other.com/icon.png" />';

check_eq(
    $other_html,
    $gateway->filter_gateway_icon_html( $other_html, 'paypal' ),
    'Different gateway ID (paypal) → original HTML returned unchanged'
);
check_eq(
    $other_html,
    $gateway->filter_gateway_icon_html( $other_html, 'stripe' ),
    'Different gateway ID (stripe) → original HTML returned unchanged'
);
check_eq(
    '',
    $gateway->filter_gateway_icon_html( '', 'some_other_gateway' ),
    'Empty HTML for other gateway → empty string returned as-is'
);

// ─── Breeze gateway: constrained 24×24 img tag is emitted ─────────────────────

echo "\n🧪 filter_gateway_icon_html(): Breeze icon gets 24×24 constraint\n";

$result = $gateway->filter_gateway_icon_html( $other_html, 'breeze_payment_gateway' );

check(
    false !== strpos( $result, 'max-width:24px' ),
    'Output contains max-width:24px'
);
check(
    false !== strpos( $result, 'max-height:24px' ),
    'Output contains max-height:24px'
);
check(
    false !== strpos( $result, esc_url( $icon_url ) ),
    'Output contains escaped icon URL in src attribute'
);
check(
    false !== strpos( $result, esc_attr( $method_title ) ),
    'Output contains escaped method title in alt attribute'
);
check(
    0 === strpos( $result, '<img ' ),
    'Output is an <img> element (starts with <img>)'
);
check(
    false === strpos( $result, 'other.com' ),
    'Original icon HTML not present — fully replaced, not wrapped'
);

// ─── XSS: malicious icon URL and method_title values are escaped ──────────────

echo "\n🧪 filter_gateway_icon_html(): XSS inputs are escaped\n";

$xss_gateway = make_icon_gateway(
    'https://evil.com/" onerror="alert(1)',
    '<script>alert(1)</script>'
);
$xss_result = $xss_gateway->filter_gateway_icon_html( '', 'breeze_payment_gateway' );

// A raw '" onerror="' substring in the output would indicate a broken-out
// attribute injection. esc_url() must encode the double-quote so the pattern
// can never appear literally.
check(
    false === strpos( $xss_result, '" onerror="' ),
    'Malicious src: attribute-injection pattern not present (double-quotes escaped)'
);
check(
    false === strpos( $xss_result, '<script>' ),
    'Script tag in method_title escaped in alt attribute'
);

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
