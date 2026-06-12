<?php
/**
 * Tests for build_line_items() — the payload of products/shipping sent to Breeze.
 *
 * Like test-crypto-tax.php and unlike the mirror-style helpers in
 * test-gateway.php, these tests load the REAL WC_Breeze_Payment_Gateway class
 * and invoke the actual private method via reflection, so the assertions track
 * production behavior rather than a copy of it. This is the money path: a
 * regression in the per-unit split or rounding here changes what customers
 * are charged.
 *
 * Run: php tests/test-line-items.php
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
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string, $remove_breaks = false ) {
        $string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
        $string = strip_tags( $string );
        if ( $remove_breaks ) {
            $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
        }
        return trim( $string );
    }
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    // Mirrors WP: returns a URL for a real attachment ID, false otherwise.
    function wp_get_attachment_url( $attachment_id ) {
        return $attachment_id > 0 ? "https://img.example.test/{$attachment_id}.jpg" : false;
    }
}

require_once __DIR__ . '/../includes/class-wc-breeze-payment-gateway.php';

// ─── Minimal order/item/product stubs ────────────────────────────────────────

class Breeze_Test_Product {
    private $id;
    private $short_description;
    private $image_id;
    public function __construct( $id, $short_description = '', $image_id = 0 ) {
        $this->id                = $id;
        $this->short_description = $short_description;
        $this->image_id          = $image_id;
    }
    public function get_id() { return $this->id; }
    public function get_short_description() { return $this->short_description; }
    public function get_image_id() { return $this->image_id; }
}

class Breeze_Test_Item {
    private $product;
    private $quantity;
    private $total;
    private $name;
    public function __construct( $product, $quantity, $total, $name = 'Test Product' ) {
        $this->product  = $product;
        $this->quantity = $quantity;
        $this->total    = $total;
        $this->name     = $name;
    }
    public function get_product() { return $this->product; }
    public function get_quantity() { return $this->quantity; }
    public function get_total() { return $this->total; }
    public function get_name() { return $this->name; }
}

class Breeze_Test_Order {
    private $items;
    private $currency;
    private $shipping_total;
    private $shipping_method;
    public function __construct( $items, $currency = 'USD', $shipping_total = 0, $shipping_method = '' ) {
        $this->items           = $items;
        $this->currency        = $currency;
        $this->shipping_total  = $shipping_total;
        $this->shipping_method = $shipping_method;
    }
    public function get_items() { return $this->items; }
    public function get_currency() { return $this->currency; }
    public function get_shipping_total() { return $this->shipping_total; }
    public function get_shipping_method() { return $this->shipping_method; }
}

/**
 * Invoke the real private build_line_items() on a constructor-less instance.
 */
function build_line_items_real( $order, $send_description = false ) {
    $ref     = new ReflectionClass( 'WC_Breeze_Payment_Gateway' );
    $gateway = $ref->newInstanceWithoutConstructor();
    $gateway->send_product_description = $send_description;
    $method = $ref->getMethod( 'build_line_items' );
    $method->setAccessible( true );
    return $method->invoke( $gateway, $order );
}

function item( $product_id, $qty, $total, $name = 'Test Product' ) {
    return new Breeze_Test_Item( new Breeze_Test_Product( $product_id ), $qty, $total, $name );
}

/** Sum of amount × quantity across entries — what the customer is charged. */
function charged_total( $line_items ) {
    $sum = 0;
    foreach ( $line_items as $entry ) {
        $sum += $entry['amount'] * $entry['quantity'];
    }
    return $sum;
}

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

// ─── Clean division: one entry per item ──────────────────────────────────────

echo "\n🧪 Line items: clean division\n";

$items = build_line_items_real( new Breeze_Test_Order( array( item( 42, 2, 20.00 ) ) ) );
check_eq( 1, count( $items ), 'Even split → single entry' );
check_eq( 1000, $items[0]['amount'], 'Per-unit amount in minor units (2 × $10.00)' );
check_eq( 2, $items[0]['quantity'], 'Quantity carried through' );
check_eq( '42', $items[0]['clientProductId'], 'clientProductId is the product ID as string' );
check_eq( 'USD', $items[0]['currency'], 'Currency taken from the order' );
check_eq( 'Test Product', $items[0]['displayName'], 'Display name taken from the item' );

$items = build_line_items_real( new Breeze_Test_Order( array( item( 7, 1, 9.99 ) ) ) );
check_eq( 999, $items[0]['amount'], 'Qty 1, $9.99 → 999 minor units' );

$items = build_line_items_real( new Breeze_Test_Order( array( item( 1, 1, 5.00 ), item( 2, 1, 3.00 ) ) ) );
check_eq( 2, count( $items ), 'Two items → two entries, in order' );
check_eq( '1', $items[0]['clientProductId'], 'First item first' );
check_eq( '2', $items[1]['clientProductId'], 'Second item second' );

// ─── Remainder split: exact line total preserved ─────────────────────────────

echo "\n🧪 Line items: remainder split preserves the exact charge\n";

// 3 × $10.00 line total: 1000¢ / 3 = 333¢ base + 1¢ remainder.
$items = build_line_items_real( new Breeze_Test_Order( array( item( 5, 3, 10.00 ) ) ) );
check_eq( 2, count( $items ), 'Uneven split → two entries (bulk + tail)' );
check_eq( 333, $items[0]['amount'], 'Bulk entry at floored per-unit price' );
check_eq( 2, $items[0]['quantity'], 'Bulk entry covers qty − 1 units' );
check_eq( 334, $items[1]['amount'], 'Tail entry absorbs the rounding remainder' );
check_eq( 1, $items[1]['quantity'], 'Tail entry is a single unit' );
check_eq( 1000, charged_total( $items ), 'Charged total exactly equals the line total' );
check_eq( $items[0]['clientProductId'], $items[1]['clientProductId'], 'Both split entries reference the same product' );

// 7 × $1.00 line total: 100¢ / 7 = 14¢ base + 2¢ remainder.
$items = build_line_items_real( new Breeze_Test_Order( array( item( 5, 7, 1.00 ) ) ) );
check_eq( 14, $items[0]['amount'], '7-way split: bulk at 14¢' );
check_eq( 6, $items[0]['quantity'], '7-way split: bulk qty 6' );
check_eq( 16, $items[1]['amount'], '7-way split: tail absorbs 2¢ remainder' );
check_eq( 100, charged_total( $items ), '7-way split: charged total preserved' );

// ─── Skip rules ───────────────────────────────────────────────────────────────

echo "\n🧪 Line items: invalid/free items are skipped\n";

$no_product = new Breeze_Test_Item( false, 1, 5.00 );
$orphan     = item( 0, 1, 5.00 );            // unattached variation: product ID 0
$zero_qty   = item( 9, 0, 5.00 );
$free       = item( 9, 1, 0.00 );            // 100%-discounted / free
$refunded   = item( 9, 1, -5.00 );           // negative line total
$valid      = item( 9, 1, 5.00 );

$items = build_line_items_real( new Breeze_Test_Order( array( $no_product, $orphan, $zero_qty, $free, $refunded, $valid ) ) );
check_eq( 1, count( $items ), 'Item without product, ID-0 product, zero qty, free, and negative-total items all skipped' );
check_eq( '9', $items[0]['clientProductId'], 'Valid item after skipped ones still emitted' );
check_eq( 500, $items[0]['amount'], 'Valid item amount unaffected by skipped siblings' );

// ─── Description, display name, image ────────────────────────────────────────

echo "\n🧪 Line items: description / name / image rules\n";

$described = new Breeze_Test_Item(
    new Breeze_Test_Product( 11, '<b>Soft</b> & <script>alert(1)</script>cozy' ),
    1, 5.00
);

$items = build_line_items_real( new Breeze_Test_Order( array( $described ) ), false );
check( ! isset( $items[0]['description'] ), 'Setting off → description omitted even when product has one' );

$items = build_line_items_real( new Breeze_Test_Order( array( $described ) ), true );
check_eq( 'Soft & cozy', $items[0]['description'], 'Setting on → description included with tags/scripts stripped' );

$long_desc = new Breeze_Test_Item( new Breeze_Test_Product( 11, str_repeat( 'a', 300 ) ), 1, 5.00 );
$items = build_line_items_real( new Breeze_Test_Order( array( $long_desc ) ), true );
check_eq( 280, mb_strlen( $items[0]['description'] ), 'Description truncated to 280 chars' );

$no_desc = new Breeze_Test_Item( new Breeze_Test_Product( 11, '' ), 1, 5.00 );
$items = build_line_items_real( new Breeze_Test_Order( array( $no_desc ) ), true );
check( ! isset( $items[0]['description'] ), 'Setting on but empty product description → key omitted' );

$items = build_line_items_real( new Breeze_Test_Order( array( item( 11, 1, 5.00, str_repeat( 'é', 150 ) ) ) ) );
check_eq( 100, mb_strlen( $items[0]['displayName'] ), 'Display name truncated to 100 chars (multibyte-safe)' );

$with_image = new Breeze_Test_Item( new Breeze_Test_Product( 11, '', 77 ), 1, 5.00 );
$items = build_line_items_real( new Breeze_Test_Order( array( $with_image ) ) );
check_eq( 'https://img.example.test/77.jpg', $items[0]['image'], 'Product image URL included when attachment exists' );

$items = build_line_items_real( new Breeze_Test_Order( array( item( 11, 1, 5.00 ) ) ) );
check( ! isset( $items[0]['image'] ), 'No image attachment → image key omitted' );

// ─── Shipping as a virtual line item ─────────────────────────────────────────

echo "\n🧪 Line items: shipping entry\n";

$order = new Breeze_Test_Order( array( item( 1, 1, 5.00 ) ), 'EUR', 5.50, 'Flat rate' );
$items = build_line_items_real( $order );
check_eq( 2, count( $items ), 'Shipping appended as its own entry' );
$shipping = $items[1];
check_eq( 'shipping', $shipping['clientProductId'], 'Shipping uses the reserved clientProductId' );
check_eq( 550, $shipping['amount'], '$5.50 shipping → 550 minor units' );
check_eq( 1, $shipping['quantity'], 'Shipping quantity is 1' );
check_eq( 'EUR', $shipping['currency'], 'Shipping uses the order currency' );
check_eq( 'Flat rate', $shipping['description'], 'Shipping method name becomes the description' );

$order = new Breeze_Test_Order( array( item( 1, 1, 5.00 ) ), 'USD', 4.00, '' );
$items = build_line_items_real( $order );
check( ! isset( $items[1]['description'] ), 'No shipping method name → description omitted' );

$order = new Breeze_Test_Order( array( item( 1, 1, 5.00 ) ), 'USD', 0 );
$items = build_line_items_real( $order );
check_eq( 1, count( $items ), 'Zero shipping → no shipping entry' );

// ─── 19-entry product cap (slot 20 reserved for shipping) ────────────────────

echo "\n🧪 Line items: entry cap\n";

function n_clean_items( $n ) {
    $items = array();
    for ( $i = 1; $i <= $n; $i++ ) {
        $items[] = item( $i, 1, 5.00 );
    }
    return $items;
}

$items = build_line_items_real( new Breeze_Test_Order( n_clean_items( 19 ) ) );
check_eq( 19, count( $items ), '19 clean items → allowed' );

$items = build_line_items_real( new Breeze_Test_Order( n_clean_items( 19 ), 'USD', 5.00, 'Flat rate' ) );
check_eq( 20, count( $items ), '19 products + shipping → 20 entries (shipping exempt from the cap)' );

$threw = false;
try {
    build_line_items_real( new Breeze_Test_Order( n_clean_items( 20 ) ) );
} catch ( Exception $e ) {
    $threw = strpos( $e->getMessage(), 'too many line items' ) !== false;
}
check( $threw, '20 clean items → exception (over the 19-product cap)' );

// 18 clean items + an uneven split (needs 2 entries) would make 20 → reject.
$over_via_split = n_clean_items( 18 );
$over_via_split[] = item( 99, 3, 10.00 );
$threw = false;
try {
    build_line_items_real( new Breeze_Test_Order( $over_via_split ) );
} catch ( Exception $e ) {
    $threw = strpos( $e->getMessage(), 'too many line items' ) !== false;
}
check( $threw, '18 items + 2-entry split → exception (split counts as 2 toward the cap)' );

// 17 clean items + a split fits exactly: 17 + 2 = 19.
$fits_via_split = n_clean_items( 17 );
$fits_via_split[] = item( 99, 3, 10.00 );
$items = build_line_items_real( new Breeze_Test_Order( $fits_via_split ) );
check_eq( 19, count( $items ), '17 items + 2-entry split → exactly 19, allowed' );

// Skipped items don't count toward the cap.
$with_free = n_clean_items( 19 );
$with_free[] = item( 99, 1, 0.00 );
$items = build_line_items_real( new Breeze_Test_Order( $with_free ) );
check_eq( 19, count( $items ), 'Skipped (free) item does not count toward the cap' );

// ─── Summary ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '━', 32 ) . "\n";
echo "  {$passed} passed, {$failed} failed\n";
echo str_repeat( '━', 32 ) . "\n";

exit( $failed > 0 ? 1 : 0 );
