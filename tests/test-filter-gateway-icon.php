<?php
/**
 * Tests for WC_Breeze_Payment_Gateway::filter_gateway_icon_html()
 *
 * Loads the real production class via reflection (no constructor) and
 * exercises the public filter callback that constrains the Breeze checkout
 * icon to 24x24px. No WP/WC runtime is required.
 *
 * Run: php tests/test-filter-gateway-icon.php
 */

// ─── Stubs/polyfills needed to load the real class standalone ────────────────

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
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
		// Strip disallowed protocols as real WP does.
		$url = preg_replace( '/^[\s]*(?:javascript|vbscript|data):/i', '', $url );
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Assert harness ──────────────────────────────────────────────────────────

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

// ─── Helper: build a gateway instance without running the WP constructor ─────

function make_icon_gateway( $icon_url, $method_title ) {
	$ref     = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
	$gateway = $ref->newInstanceWithoutConstructor();

	// id, icon, method_title are public properties inherited from WC_Payment_Gateway.
	$gateway->id           = 'breeze';
	$gateway->icon         = $icon_url;
	$gateway->method_title = $method_title;

	return $gateway;
}

$icon_url     = 'https://example.com/breeze-icon.png';
$method_title = 'Breeze';
$gateway      = make_icon_gateway( $icon_url, $method_title );

// ─── Passthrough: other gateway IDs must not be touched ──────────────────────

echo "\n🧪 filter_gateway_icon_html: passthrough for other gateways\n";

$original = '<img src="https://stripe.com/icon.png" />';

check_eq( $original, $gateway->filter_gateway_icon_html( $original, 'stripe' ),     'stripe gateway → original HTML returned' );
check_eq( $original, $gateway->filter_gateway_icon_html( $original, 'paypal' ),     'paypal gateway → original HTML returned' );
check_eq( $original, $gateway->filter_gateway_icon_html( $original, 'breezeXXX' ),  'similar-but-wrong id → original HTML returned' );

// ─── Breeze gateway: icon must be replaced with a 24x24-constrained <img> ────

echo "\n🧪 filter_gateway_icon_html: Breeze gateway emits constrained <img>\n";

$result = $gateway->filter_gateway_icon_html( $original, 'breeze' );

check( strpos( $result, 'max-width:24px' )  !== false, 'result contains max-width:24px' );
check( strpos( $result, 'max-height:24px' ) !== false, 'result contains max-height:24px' );
check( strpos( $result, esc_url( $icon_url ) ) !== false, 'result contains escaped icon URL' );
check( strpos( $result, esc_attr( $method_title ) ) !== false, 'result contains escaped method title as alt text' );
check( strpos( $result, 'stripe.com' ) === false, 'original HTML is NOT in the output' );

// ─── XSS: malicious icon URL and method_title must be escaped ────────────────

echo "\n🧪 filter_gateway_icon_html: XSS inputs are escaped\n";

$evil_url   = 'javascript:alert(1)';
$evil_title = '" onmouseover="alert(1)';
$evil_gw    = make_icon_gateway( $evil_url, $evil_title );
$xss_result = $evil_gw->filter_gateway_icon_html( '', 'breeze' );

check( strpos( $xss_result, 'javascript:' ) === false, 'malicious URL is escaped — javascript: scheme stripped' );
check( strpos( $xss_result, '&quot;' )     !== false, 'malicious alt text is escaped — double-quotes become &quot;' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n";
echo "  {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
