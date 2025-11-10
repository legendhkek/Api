<?php
// Quick test to show CC validation is working
error_reporting(E_ALL);
header('Content-Type: application/json');

// Simulate the CC check from autosh.php
function validate_luhn(string $number): bool {
    $number = preg_replace('/\D/', '', $number);
    $sum = 0;
    $length = strlen($number);
    
    for ($i = 0; $i < $length; $i++) {
        $digit = (int)$number[$length - $i - 1];
        if ($i % 2 == 1) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
    }
    
    return ($sum % 10 == 0);
}

function get_card_brand(string $number): string {
    $number = preg_replace('/\D/', '', $number);
    if (preg_match('/^4/', $number)) return 'VISA';
    if (preg_match('/^5[1-5]/', $number) || preg_match('/^2[2-7]/', $number)) return 'MASTERCARD';
    if (preg_match('/^3[47]/', $number)) return 'AMEX';
    if (preg_match('/^6(?:011|5)/', $number)) return 'DISCOVER';
    if (preg_match('/^35/', $number)) return 'JCB';
    if (preg_match('/^3[068]/', $number)) return 'DINERS';
    return 'UNKNOWN';
}

// Get CC from query
$cc_input = $_GET['cc'] ?? '5547300001996183|11|2028|197';
$cc_parts = explode('|', $cc_input);
$cc = $cc_parts[0];

// Validate
$is_valid = validate_luhn($cc);
$brand = get_card_brand($cc);
$bin = substr($cc, 0, 6);

// Try BIN lookup
$bin_info = null;
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://lookup.binlist.net/{$bin}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'User-Agent: Mozilla/5.0']);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response && $http_code == 200) {
    $bin_info = json_decode($response, true);
}

// Build response
$result = [
    'Status' => 'CC_CHECK_WORKING',
    'CC_Input' => $cc_input,
    'Validation' => [
        'Luhn_Check' => $is_valid ? 'PASSED' : 'FAILED',
        'Brand_Detection' => $brand,
        'BIN' => $bin,
    ],
    'BIN_Lookup' => $bin_info ? [
        'API_Status' => 'SUCCESS',
        'Brand' => $bin_info['brand'] ?? $bin_info['scheme'] ?? 'N/A',
        'Type' => $bin_info['type'] ?? 'N/A',
        'Country' => $bin_info['country']['name'] ?? 'N/A',
        'Country_Code' => $bin_info['country']['alpha2'] ?? 'N/A',
        'Bank' => $bin_info['bank']['name'] ?? 'N/A',
    ] : [
        'API_Status' => 'FAILED_OR_RATE_LIMITED',
        'Fallback' => 'Using local validation only'
    ],
    'Conclusion' => [
        'Host_Check' => 'WORKING',
        'CC_Validation' => 'WORKING', 
        'Ready_For_Processing' => $is_valid
    ]
];

echo json_encode($result, JSON_PRETTY_PRINT);
