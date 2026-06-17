<?php
/**
 * Tests for the flexible-amount and percentage settings validators.
 *
 * Exercises:
 *   - validate_flexible_amount_max_field()
 *   - validate_flexible_amount_fixed_field()
 *   - validate_flexible_amount_percentage_field()
 *   - validate_positive_integer_minor_units() (private, called by the two above)
 *
 * Loads the REAL WC_Breeze_Payment_Gateway class via ReflectionClass without
 * invoking the WP-dependent constructor, then drives public methods directly
 * and the private helper through setAccessible().
 *
 * Run: php tests/test-settings-validators.php
 */

// ─── Stubs needed to load the real class standalone ──────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {}
}
if ( ! class_exists( 'WC_Admin_Settings' ) ) {
    class WC_Admin_Settings {
        public static $errors = array();
        public static function add_error( $message ) {
            self::$errors[] = $message;
        }
        public static function reset() {
            self::$errors = array();
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

// ─── Shared helpers ───────────────────────────────────────────────────────────

function make_gateway() {
    $ref = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    return $ref->newInstanceWithoutConstructor();
}

/**
 * Call the private validate_positive_integer_minor_units() via reflection.
 */
function validate_minor_units( $gateway, $value, $label = 'Amount' ) {
    $method = new ReflectionMethod( 'WC_Breeze_Payment_Gateway', 'validate_positive_integer_minor_units' );
    $method->setAccessible( true );
    return $method->invoke( $gateway, 'field_key', $value, $label );
}

$gw = make_gateway();

// ─── validate_positive_integer_minor_units ────────────────────────────────────

echo "\n🧪 validate_positive_integer_minor_units: blank and null are allowed (optional field)\n";

check_eq( '', validate_minor_units( $gw, '' ),   'blank string → empty (field left empty)' );
check_eq( '', validate_minor_units( $gw, null ),  'null → empty' );
check_eq( '', validate_minor_units( $gw, '  ' ), 'whitespace-only → empty after trim' );

echo "\n🧪 validate_positive_integer_minor_units: valid positive integers\n";

WC_Admin_Settings::reset();
check_eq( '1',   validate_minor_units( $gw, '1' ),   "'1' → '1'" );
check_eq( '42',  validate_minor_units( $gw, '42' ),  "'42' → '42'" );
check_eq( '42',  validate_minor_units( $gw, ' 42 '), "'42' with surrounding whitespace → '42' (trimmed)" );
check_eq( '999', validate_minor_units( $gw, '999' ), "'999' → '999'" );
check( empty( WC_Admin_Settings::$errors ), 'no errors recorded for valid inputs' );

echo "\n🧪 validate_positive_integer_minor_units: non-positive integers are rejected\n";

WC_Admin_Settings::reset();
check_eq( '', validate_minor_units( $gw, '0' ),  "'0' → empty (must be > 0)" );
check( ! empty( WC_Admin_Settings::$errors ), "'0' records an error" );

WC_Admin_Settings::reset();
check_eq( '', validate_minor_units( $gw, '-5' ), "'-5' → empty (negative)" );
check( ! empty( WC_Admin_Settings::$errors ), "'-5' records an error" );

echo "\n🧪 validate_positive_integer_minor_units: non-integer values are rejected\n";

WC_Admin_Settings::reset();
check_eq( '', validate_minor_units( $gw, '3.14' ),  "'3.14' → empty (float)" );
check_eq( '', validate_minor_units( $gw, '1e2' ),   "'1e2' → empty (scientific notation)" );
check_eq( '', validate_minor_units( $gw, 'abc' ),   "'abc' → empty (non-numeric)" );
check_eq( '', validate_minor_units( $gw, '10 px' ), "'10 px' → empty (trailing text)" );
check( count( WC_Admin_Settings::$errors ) === 4, '4 errors recorded for 4 invalid inputs' );

// ─── validate_flexible_amount_max_field / validate_flexible_amount_fixed_field ─

echo "\n🧪 validate_flexible_amount_max_field / _fixed_field: delegate to minor-units validator\n";

WC_Admin_Settings::reset();
check_eq( '200', $gw->validate_flexible_amount_max_field( 'flexible_amount_max', '200' ),
    'max_field: valid integer passes through' );
check_eq( '',    $gw->validate_flexible_amount_max_field( 'flexible_amount_max', '0' ),
    'max_field: zero → empty' );
check_eq( '150', $gw->validate_flexible_amount_fixed_field( 'flexible_amount_fixed', '150' ),
    'fixed_field: valid integer passes through' );
check_eq( '',    $gw->validate_flexible_amount_fixed_field( 'flexible_amount_fixed', 'nope' ),
    'fixed_field: non-numeric → empty' );

// ─── validate_flexible_amount_percentage_field ────────────────────────────────

echo "\n🧪 validate_flexible_amount_percentage_field: blank and null are allowed\n";

check_eq( '', $gw->validate_flexible_amount_percentage_field( 'pct', '' ),    'blank → empty' );
check_eq( '', $gw->validate_flexible_amount_percentage_field( 'pct', null ),  'null → empty' );
check_eq( '', $gw->validate_flexible_amount_percentage_field( 'pct', '  ' ), 'whitespace → empty' );

echo "\n🧪 validate_flexible_amount_percentage_field: valid percentages\n";

WC_Admin_Settings::reset();
check_eq( '50',   $gw->validate_flexible_amount_percentage_field( 'pct', '50' ),   "'50' → '50'" );
check_eq( '25.5', $gw->validate_flexible_amount_percentage_field( 'pct', '25.5' ), "'25.5' → '25.5'" );
check_eq( '0.01', $gw->validate_flexible_amount_percentage_field( 'pct', '0.01' ), "'0.01' → '0.01' (lower boundary)" );
check_eq( '100',  $gw->validate_flexible_amount_percentage_field( 'pct', '100' ),  "'100' → '100' (upper boundary)" );
check_eq( '50',   $gw->validate_flexible_amount_percentage_field( 'pct', ' 50 ' ), "' 50 ' → '50' (trimmed)" );
check( empty( WC_Admin_Settings::$errors ), 'no errors for valid percentage inputs' );

echo "\n🧪 validate_flexible_amount_percentage_field: out-of-range and non-numeric are rejected\n";

WC_Admin_Settings::reset();
check_eq( '', $gw->validate_flexible_amount_percentage_field( 'pct', '0' ),     "'0' → empty (must be > 0)" );
check_eq( '', $gw->validate_flexible_amount_percentage_field( 'pct', '-1' ),    "'-1' → empty (negative)" );
check_eq( '', $gw->validate_flexible_amount_percentage_field( 'pct', '100.1' ), "'100.1' → empty (exceeds 100)" );
check_eq( '', $gw->validate_flexible_amount_percentage_field( 'pct', '101' ),   "'101' → empty (exceeds 100)" );
check_eq( '', $gw->validate_flexible_amount_percentage_field( 'pct', 'abc' ),   "'abc' → empty (non-numeric)" );
check( count( WC_Admin_Settings::$errors ) === 5, '5 errors recorded for 5 invalid inputs' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
