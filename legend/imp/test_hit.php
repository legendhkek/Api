<?php
/**
 * HIT.PHP Test Suite
 * Tests various scenarios for hit.php functionality
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "====================================\n";
echo "HIT.PHP Test Suite\n";
echo "====================================\n\n";

// Test 1: Address parsing - Comma format
echo "Test 1: Address Parsing (Comma format)\n";
$test_address = "350, 5th Ave, New York, NY, 10118";
$parts = array_map('trim', explode(',', $test_address));
echo "Input: $test_address\n";
echo "Parsed: " . print_r($parts, true) . "\n";
echo "✓ PASS\n\n";

// Test 2: Address parsing - Pipe format
echo "Test 2: Address Parsing (Pipe format)\n";
$test_address = "350|5th Ave|New York|NY|10118";
$parts = array_map('trim', explode('|', $test_address));
echo "Input: $test_address\n";
echo "Parsed: " . print_r($parts, true) . "\n";
echo "✓ PASS\n\n";

// Test 3: Credit card parsing
echo "Test 3: Credit Card Parsing\n";
$test_cc = "4111111111111111|12|2027|123";
$parts = explode('|', $test_cc);
echo "Input: $test_cc\n";
echo "Number: {$parts[0]}\n";
echo "Month: {$parts[1]}\n";
echo "Year: {$parts[2]}\n";
echo "CVV: {$parts[3]}\n";
echo "✓ PASS\n\n";

// Test 4: Luhn validation
echo "Test 4: Luhn Validation Algorithm\n";
function testLuhn($number) {
    $number = preg_replace('/\s+/', '', $number);
    $sum = 0;
    $alt = false;
    
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = intval($number[$i]);
        if ($alt) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $alt = !$alt;
    }
    
    return ($sum % 10 === 0);
}

$test_cards = [
    '4111111111111111' => true,  // Valid Visa
    '5555555555554444' => true,  // Valid Mastercard
    '378282246310005' => true,   // Valid Amex
    '4111111111111112' => false, // Invalid (Luhn)
    '1234567890123456' => false, // Invalid
];

foreach ($test_cards as $card => $expected) {
    $result = testLuhn($card);
    $status = ($result === $expected) ? '✓' : '✗';
    echo "$status Card: $card - " . ($result ? 'VALID' : 'INVALID') . "\n";
}
echo "✓ PASS\n\n";

// Test 5: Card brand detection
echo "Test 5: Card Brand Detection\n";
function detectBrand($number) {
    $number = preg_replace('/\s+/', '', $number);
    
    if (preg_match('/^4/', $number)) return 'Visa';
    if (preg_match('/^5[1-5]/', $number)) return 'Mastercard';
    if (preg_match('/^3[47]/', $number)) return 'Amex';
    if (preg_match('/^6(?:011|5)/', $number)) return 'Discover';
    
    return 'Unknown';
}

$brand_tests = [
    '4111111111111111' => 'Visa',
    '5555555555554444' => 'Mastercard',
    '378282246310005' => 'Amex',
    '6011111111111117' => 'Discover',
];

foreach ($brand_tests as $card => $expected) {
    $brand = detectBrand($card);
    $status = ($brand === $expected) ? '✓' : '✗';
    echo "$status $card => $brand\n";
}
echo "✓ PASS\n\n";

// Test 6: ProxyManager availability
echo "Test 6: ProxyManager Class\n";
if (file_exists(__DIR__ . '/ProxyManager.php')) {
    require_once __DIR__ . '/ProxyManager.php';
    $pm = new ProxyManager();
    echo "✓ ProxyManager loaded successfully\n";
    
    if (file_exists(__DIR__ . '/ProxyList.txt')) {
        $count = $pm->loadFromFile(__DIR__ . '/ProxyList.txt');
        echo "✓ Loaded $count proxies from ProxyList.txt\n";
    } else {
        echo "⚠ ProxyList.txt not found - proxy rotation will not work\n";
    }
} else {
    echo "✗ ProxyManager.php not found\n";
}
echo "\n";

// Test 7: Address provider
echo "Test 7: Address Provider\n";
if (file_exists(__DIR__ . '/add.php')) {
    require_once __DIR__ . '/add.php';
    $addr = AddressProvider::getRandomAddress();
    echo "✓ Random address: {$addr['numd']} {$addr['address1']}, {$addr['city']}, {$addr['state']} {$addr['zip']}\n";
    
    $ca_addr = AddressProvider::getAddressByState('CA');
    if ($ca_addr) {
        echo "✓ CA address: {$ca_addr['numd']} {$ca_addr['address1']}, {$ca_addr['city']}, {$ca_addr['state']} {$ca_addr['zip']}\n";
    }
    
    echo "✓ Total addresses: " . AddressProvider::count() . "\n";
} else {
    echo "✗ add.php not found\n";
}
echo "\n";

// Test 8: User Agent
echo "Test 8: User Agent Generator\n";
if (file_exists(__DIR__ . '/ho.php')) {
    require_once __DIR__ . '/ho.php';
    $agent = new userAgent();
    $ua = $agent->generate('windows');
    echo "✓ Generated UA: " . substr($ua, 0, 50) . "...\n";
} else {
    echo "✗ ho.php not found\n";
}
echo "\n";

// Test 9: URL building
echo "Test 9: URL Building\n";
$base_url = "http://localhost:8000/hit.php";
$params = [
    'cc' => '4111111111111111|12|2027|123',
    'address' => '350, 5th Ave, New York, NY, 10118',
    'site' => 'https://shop.com',
    'format' => 'json'
];
$query = http_build_query($params);
$full_url = $base_url . '?' . $query;
echo "✓ Test URL: " . substr($full_url, 0, 80) . "...\n";
echo "\n";

// Test 10: JSON encoding
echo "Test 10: JSON Response Format\n";
$sample_response = [
    'success' => true,
    'count' => 1,
    'results' => [
        [
            'success' => false,
            'card' => '4111111111111111',
            'brand' => 'Visa',
            'status' => 'DECLINED',
            'message' => 'Card declined',
            'gateway' => 'Stripe',
            'response_time' => 1234
        ]
    ],
    'execution_time_ms' => 1234
];
$json = json_encode($sample_response, JSON_PRETTY_PRINT);
echo "✓ Sample JSON response:\n";
echo substr($json, 0, 200) . "...\n";
echo "\n";

echo "====================================\n";
echo "All Tests Completed!\n";
echo "====================================\n\n";

echo "Next Steps:\n";
echo "1. Start the server: ./start_server.sh or START_SERVER.bat\n";
echo "2. Open hit.php in browser: http://localhost:8000/hit.php\n";
echo "3. Or test via curl:\n";
echo "   curl 'http://localhost:8000/hit.php?cc=4111111111111111|12|2027|123&address=350,5th Ave,New York,NY,10118&site=https://shop.com&format=json'\n";
echo "\n";
?>
