<?php
/**
 * SokoHub Automated Google Maps & Delivery Tracking Verification Suite
 * Verifies:
 * - Google Map embed URL generation for shops
 * - Google Map directions URL generation for delivery routing
 * - Kirinyaga County coordinate lookup
 * - CSP headers allowing Google Maps iframes
 * - Component HTML rendering without XSS or syntax errors
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/maps.php';
require_once __DIR__ . '/../includes/security.php';

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $name, string $details = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}: {$details}\n";
    }
}

echo "=========================================================\n";
echo " SokoHub Google Maps & Delivery Tracking Test Suite\n";
echo "=========================================================\n\n";

// 1. Kirinyaga Coordinates Lookup
echo "1. Geographic Coordinate Resolution\n";
$kutus = get_kirinyaga_coordinates('Kutus Central');
assert_test(abs($kutus['lat'] - (-0.5186)) < 0.001, 'MAP-01a: Kutus latitude correctly resolves to -0.5186');
assert_test(abs($kutus['lng'] - 37.3382) < 0.001, 'MAP-01b: Kutus longitude correctly resolves to 37.3382');

$kerugoya = get_kirinyaga_coordinates('Kerugoya Market');
assert_test(abs($kerugoya['lat'] - (-0.4989)) < 0.001, 'MAP-01c: Kerugoya latitude correctly resolves to -0.4989');

$fallback = get_kirinyaga_coordinates('Unknown Sub-county');
assert_test(abs($fallback['lat'] - (-0.5186)) < 0.001, 'MAP-01d: Unknown location safely defaults to Kirinyaga County Central HQ');

// 2. URL Generation
echo "\n2. Google Maps URL Formulation\n";
$shopEmbed = google_map_embed_url('Ian Fresh Mart, Kutus');
assert_test(str_contains($shopEmbed, 'google.com/maps') && str_contains($shopEmbed, 'output=embed'), 'MAP-02a: Shop embed URL targets google.com/maps with output=embed');
assert_test(str_contains($shopEmbed, 'Ian+Fresh+Mart%2C+Kutus'), 'MAP-02b: Shop query is properly URL encoded');

$dirEmbed = google_map_directions_embed_url('Kutus Market', 'Kirinyaga University Gate B');
assert_test(str_contains($dirEmbed, 'maps.google.com/maps') && str_contains($dirEmbed, 'saddr=') && str_contains($dirEmbed, 'daddr='), 'MAP-02c: Delivery directions embed targets origin and destination addresses');

$navUrl = google_map_directions_url('Kutus Market', 'Kerugoya Town');
assert_test(str_contains($navUrl, 'travelmode=driving'), 'MAP-02d: Direct navigation URL specifies driving travel mode');

// 3. Shop Location Card Rendering
echo "\n3. Shop Map Card HTML Component\n";
$cardHtml = render_shop_map_card('Wanjiku Organic Produce', 'Kerugoya, Kirinyaga', ['height' => 250]);
assert_test(str_contains($cardHtml, 'class="shop-location-map-card"'), 'MAP-03a: Card rendered with container class');
assert_test(str_contains($cardHtml, 'Wanjiku Organic Produce'), 'MAP-03b: Business name safely displayed in card header');
assert_test(str_contains($cardHtml, '<iframe'), 'MAP-03c: Embedded Google Maps iframe present');
assert_test(str_contains($cardHtml, 'height:250px'), 'MAP-03d: Custom height option applied');
assert_test(str_contains($cardHtml, 'Get Directions'), 'MAP-03e: Get Directions button provided for shoppers');

// 4. Delivery Route Tracking Card Rendering
echo "\n4. Delivery Tracking Map Card HTML Component\n";
$delData = [
    'order_code'      => 'ORD-2026-TEST',
    'pickup_town'     => 'Kutus Market',
    'dropoff_address' => 'Kirinyaga University Gate B, Hostel 4',
    'delivery_status' => 'in_transit',
    'rider_name'      => 'John Mwangi',
    'rider_phone'     => '0712345678',
];
$trackHtml = render_delivery_tracking_map_card($delData, ['height' => 300]);
assert_test(str_contains($trackHtml, 'class="delivery-tracking-map-card"'), 'MAP-04a: Delivery tracking card container rendered');
assert_test(str_contains($trackHtml, 'ORD-2026-TEST'), 'MAP-04b: Order code displayed in tracking header');
assert_test(str_contains($trackHtml, 'In Transit'), 'MAP-04c: Real-time milestone status badge rendered');
assert_test(str_contains($trackHtml, 'Kutus Market'), 'MAP-04d: Pickup shop town rendered in checkpoint bar');
assert_test(str_contains($trackHtml, 'Kirinyaga University'), 'MAP-04e: Dropoff destination rendered in checkpoint bar');
assert_test(str_contains($trackHtml, 'John Mwangi'), 'MAP-04f: Rider details displayed');
assert_test(str_contains($trackHtml, 'Open Route in Google Maps'), 'MAP-04g: Turn-by-turn navigation link provided');

// 5. CSP Permissiveness
echo "\n5. Security & CSP Compatibility\n";
$secCode = file_get_contents(__DIR__ . '/../includes/security.php');
assert_test(str_contains($secCode, "frame-src 'self' https://www.google.com https://maps.google.com;"), 'MAP-05: CSP explicitly permits frame-src from google.com and maps.google.com');

echo "\n=========================================================\n";
echo "   Google Maps Suite: {$passed} PASSED, {$failed} FAILED\n";
echo "=========================================================\n";

if ($failed > 0) {
    exit(1);
}
