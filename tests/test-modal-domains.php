<?php
/**
 * Tests for WC_Breeze_Modal_Checkout::get_payment_page_domains()
 *
 * Loads the REAL WC_Breeze_Modal_Checkout class and calls the public static
 * get_payment_page_domains() method directly (no constructor invoked, no HTTP
 * calls). The breeze_payment_page_domains filter is exercised through a
 * controllable global stub.
 *
 * Covers:
 *  - Default list (breeze.cash, breeze.com) when no filter is registered
 *  - Filter extending the list
 *  - Non-array filter return coerced to single-element array
 *  - Wildcard prefix (*.) stripped
 *  - Full URL input → host extracted via wp_parse_url
 *  - Leading dot(s) stripped with ltrim
 *  - Empty strings and non-string values filtered out
 *  - Uppercase normalization to lowercase
 *  - Leading/trailing whitespace trimmed
 *  - Deduplication (including wildcard-normalized duplicates)
 *
 * Run: php tests/test-modal-domains.php
 */

// ─── Stubs / polyfills ────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

// Controllable filter stub: set $_filter_override['tag'] = $value to override;
// unset the key to let apply_filters return the default unchanged.
$_filter_override = array();

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        global $_filter_override;
        return array_key_exists( $tag, $_filter_override ) ? $_filter_override[ $tag ] : $value;
    }
}
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
    }
}

require_once __DIR__ . '/../includes/class-wc-breeze-modal-checkout.php';

// ─── Test harness ─────────────────────────────────────────────────────────────

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

function set_domains_filter( $value ) {
    global $_filter_override;
    $_filter_override['breeze_payment_page_domains'] = $value;
}

function clear_domains_filter() {
    global $_filter_override;
    unset( $_filter_override['breeze_payment_page_domains'] );
}

// ─── Test 1: Default list (no filter override) ────────────────────────────────

echo "\n🧪 Test 1: Default list when no filter is set\n";
clear_domains_filter();
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check( is_array( $domains ),                          'Returns an array' );
check_eq( 2, count( $domains ),                       'Default list has exactly 2 entries' );
check( in_array( 'breeze.cash', $domains, true ),     'Default list includes breeze.cash' );
check( in_array( 'breeze.com',  $domains, true ),     'Default list includes breeze.com' );

// ─── Test 2: Filter extends the list ─────────────────────────────────────────

echo "\n🧪 Test 2: Filter can extend the domain list\n";
set_domains_filter( array( 'breeze.cash', 'breeze.com', 'pay.example.com' ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check_eq( 3, count( $domains ),                           'Extended list has 3 entries' );
check( in_array( 'pay.example.com', $domains, true ),     'Custom domain present after filter extends list' );
check( in_array( 'breeze.cash',     $domains, true ),     'breeze.cash still present after extension' );

// ─── Test 3: Non-array filter return coerced to single-element array ──────────

echo "\n🧪 Test 3: Non-array string filter return is coerced to a single-element array\n";
set_domains_filter( 'mygateway.com' );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check_eq( 1, count( $domains ),                       'Non-array string coerced to one entry' );
check( in_array( 'mygateway.com', $domains, true ),   'The string value becomes the sole domain' );

// ─── Test 4: Wildcard prefix stripped ────────────────────────────────────────

echo "\n🧪 Test 4: Wildcard prefix (*.) is stripped\n";
set_domains_filter( array( '*.breeze.cash' ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check_eq( 1, count( $domains ),                       'Wildcard entry normalized to one domain' );
check( in_array( 'breeze.cash', $domains, true ),     '*.breeze.cash → breeze.cash' );

// ─── Test 5: Full URL input → host extracted ─────────────────────────────────

echo "\n🧪 Test 5: Full URL input has its host extracted\n";
set_domains_filter( array( 'https://checkout.example.com/some/path?q=1' ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check_eq( 1, count( $domains ),                               'URL entry yields one domain' );
check( in_array( 'checkout.example.com', $domains, true ),    'https://checkout.example.com/path → checkout.example.com' );

// URL with path/query only (host is extracted correctly regardless of extra parts).
set_domains_filter( array( 'https://api.example.com/v1/path?token=abc#frag' ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check_eq( 1, count( $domains ),                           'URL with path, query, and fragment yields one domain' );
check( in_array( 'api.example.com', $domains, true ),     'Only the host component is kept' );

// ─── Test 6: Leading dot(s) stripped ─────────────────────────────────────────

echo "\n🧪 Test 6: Leading dots are stripped\n";
set_domains_filter( array( '.breeze.cash' ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check( in_array( 'breeze.cash', $domains, true ),     '.breeze.cash → breeze.cash (single dot stripped)' );

set_domains_filter( array( '..double.com' ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check( in_array( 'double.com', $domains, true ),      '..double.com → double.com (multiple dots stripped)' );

// ─── Test 7: Empty strings skipped ───────────────────────────────────────────

echo "\n🧪 Test 7: Empty strings in the list are skipped\n";
set_domains_filter( array( '', 'breeze.cash', '' ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check_eq( 1, count( $domains ),                       'Empty strings filtered out; 1 entry remains' );
check( in_array( 'breeze.cash', $domains, true ),     'Non-empty entry survives' );

// ─── Test 8: Non-string values skipped ───────────────────────────────────────

echo "\n🧪 Test 8: Non-string array elements are skipped\n";
set_domains_filter( array( 42, 'valid.com', null, true ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check_eq( 1, count( $domains ),                       'Int, null, bool filtered; 1 string domain survives' );
check( in_array( 'valid.com', $domains, true ),       'Only the string domain is present' );

// ─── Test 9: Uppercase normalized to lowercase ───────────────────────────────

echo "\n🧪 Test 9: Domain names are normalized to lowercase\n";
set_domains_filter( array( 'BREEZE.CASH', 'Payments.Example.COM' ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check( in_array( 'breeze.cash',          $domains, true ),    'BREEZE.CASH → breeze.cash' );
check( in_array( 'payments.example.com', $domains, true ),    'Payments.Example.COM → payments.example.com' );

// ─── Test 10: Whitespace trimmed ─────────────────────────────────────────────

echo "\n🧪 Test 10: Leading/trailing whitespace is trimmed\n";
set_domains_filter( array( '  breeze.cash  ', "\tbreeze.com\t" ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check( in_array( 'breeze.cash', $domains, true ),     'Space-padded entry trimmed to breeze.cash' );
check( in_array( 'breeze.com',  $domains, true ),     'Tab-padded entry trimmed to breeze.com' );

// ─── Test 11: Deduplication ───────────────────────────────────────────────────

echo "\n🧪 Test 11: Duplicate entries are removed\n";
set_domains_filter( array( 'breeze.cash', 'breeze.cash', 'breeze.com' ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check_eq( 2, count( $domains ),                       'Literal duplicate removed; 2 unique domains remain' );

// Wildcard that normalizes to a domain already in the list → still de-duped.
set_domains_filter( array( 'breeze.cash', '*.breeze.cash' ) );
$domains = WC_Breeze_Modal_Checkout::get_payment_page_domains();
check_eq( 1, count( $domains ),                       'Wildcard normalizing to existing domain is deduped' );
check( in_array( 'breeze.cash', $domains, true ),     'Deduped value is breeze.cash' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 48 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 48 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
