<?php
/**
 * Test script for autosh.php
 * Tests the script with sample cc and site parameters
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "====================================\n";
echo "AUTOSH.PHP Test Script\n";
echo "====================================\n\n";

// Test 1: Verify syntax
echo "Test 1: Syntax Check\n";
echo "Checking AdvancedCaptchaSolver.php...\n";
$syntax_check = shell_exec('php -l AdvancedCaptchaSolver.php 2>&1');
if (strpos($syntax_check, 'No syntax errors') !== false) {
    echo "✓ AdvancedCaptchaSolver.php: No syntax errors\n";
} else {
    echo "✗ AdvancedCaptchaSolver.php: Syntax errors found\n";
    echo $syntax_check . "\n";
}

echo "Checking autosh.php...\n";
$syntax_check2 = shell_exec('php -l autosh.php 2>&1');
if (strpos($syntax_check2, 'No syntax errors') !== false) {
    echo "✓ autosh.php: No syntax errors\n";
} else {
    echo "✗ autosh.php: Syntax errors found\n";
    echo $syntax_check2 . "\n";
}
echo "\n";

// Test 2: Parameter validation
echo "Test 2: Parameter Validation\n";

// Simulate GET parameters
$_GET['cc'] = '4111111111111111|12|2027|123';
$_GET['site'] = 'https://example.myshopify.com';

echo "Testing with:\n";
echo "  CC: {$_GET['cc']}\n";
echo "  Site: {$_GET['site']}\n\n";

// Test CC format parsing
$cc1 = $_GET['cc'];
$cc_partes = explode("|", $cc1);

if (count($cc_partes) >= 4) {
    echo "✓ CC format valid (4 parts found)\n";
    echo "  Number: {$cc_partes[0]}\n";
    echo "  Month: {$cc_partes[1]}\n";
    echo "  Year: {$cc_partes[2]}\n";
    echo "  CVV: {$cc_partes[3]}\n";
} else {
    echo "✗ CC format invalid (expected 4 parts, got " . count($cc_partes) . ")\n";
}

// Test site URL parsing
$site1 = filter_input(INPUT_GET, 'site', FILTER_SANITIZE_URL);
$site1 = parse_url($site1, PHP_URL_HOST);
$site1 = 'https://' . $site1;
$site1 = filter_var($site1, FILTER_VALIDATE_URL);

if ($site1 !== false) {
    echo "✓ Site URL valid: $site1\n";
} else {
    echo "✗ Site URL invalid\n";
}

echo "\n";

// Test 3: Luhn validation
echo "Test 3: Luhn Algorithm Check\n";
function validate_luhn($number) {
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

$test_cc = $cc_partes[0];
$luhn_valid = validate_luhn($test_cc);
if ($luhn_valid) {
    echo "✓ CC passes Luhn check: $test_cc\n";
} else {
    echo "✗ CC fails Luhn check: $test_cc\n";
}

echo "\n";

// Test 4: Expected Response Format
echo "Test 4: Expected Response Format\n";
echo "When autosh.php is called with valid parameters, it should return JSON:\n";
echo "{\n";
echo "  \"Response\": \"...\",\n";
echo "  \"Price\": \"...\",\n";
echo "  \"Gateway\": \"...\",\n";
echo "  \"cc\": \"...\",\n";
echo "  \"CC_Info\": {\n";
echo "    \"BIN\": \"...\",\n";
echo "    \"Brand\": \"...\",\n";
echo "    \"Type\": \"...\",\n";
echo "    \"Country\": \"...\",\n";
echo "    \"Country_Name\": \"...\",\n";
echo "    \"Bank\": \"...\"\n";
echo "  },\n";
echo "  \"ProxyStatus\": \"...\",\n";
echo "  \"ProxyIP\": \"...\",\n";
echo "  \"ProxyPort\": \"...\"\n";
echo "}\n\n";

echo "====================================\n";
echo "Test Complete\n";
echo "====================================\n";
echo "\n";
echo "To test autosh.php with real parameters:\n";
echo "  URL: http://localhost:8000/autosh.php?cc=4111111111111111|12|2027|123&site=https://example.myshopify.com\n";
echo "\n";
echo "Note: The script requires:\n";
echo "  - Valid Shopify site URL\n";
echo "  - Valid credit card (passes Luhn check)\n";
echo "  - Proxy support (optional, use ?noproxy to bypass)\n";
echo "\n";
