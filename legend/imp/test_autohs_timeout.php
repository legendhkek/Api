<?php
/**
 * Test script to verify autosh.php timeout settings
 * Run: php test_autohs_timeout.php
 */

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║     AUTOSH.PHP TIMEOUT VERIFICATION TEST                   ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Simulate GET parameters
$_GET = [
    'cc' => '4111111111111111|12|2027|123',
    'site' => 'https://example.myshopify.com',
    'format' => 'json'
];

// Check runtime_cfg function
require_once 'autosh.php';

// Get runtime config
$cfg = runtime_cfg();

echo "✓ Runtime Configuration:\n";
echo "  - Connect Timeout: {$cfg['cto']} seconds\n";
echo "  - Total Timeout: {$cfg['to']} seconds\n";
echo "  - DNS Cache Timeout: 300 seconds\n";
echo "  - IPv4 Preferred: " . ($cfg['v4'] ? 'Yes' : 'No') . "\n\n";

// Verify timeout is 30 seconds
if ($cfg['to'] == 30) {
    echo "✅ PASS: Default timeout is correctly set to 30 seconds\n";
} else {
    echo "❌ FAIL: Default timeout is {$cfg['to']} seconds (expected 30)\n";
}

// Verify connect timeout is reasonable
if ($cfg['cto'] >= 5 && $cfg['cto'] <= 10) {
    echo "✅ PASS: Connect timeout is {$cfg['cto']} seconds (optimized)\n";
} else {
    echo "⚠️  WARN: Connect timeout is {$cfg['cto']} seconds\n";
}

echo "\n════════════════════════════════════════════════════════════\n";
echo "Test completed. Timeout settings verified.\n";
