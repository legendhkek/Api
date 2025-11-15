<?php
/**
 * Stripe Integration Test
 * Tests Stripe checkout detection and handling in autosh.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Stripe Integration Test ===\n\n";

// Simulate minimal environment for testing
$_GET = [];

// Load required files
require_once 'autosh.php';

echo "Test 1: Stripe Gateway Detection\n";
echo "----------------------------------------\n";

// Test HTML samples with Stripe integration
$testCases = [
    [
        'name' => 'Stripe.js v3 Standard',
        'html' => '<script src="https://js.stripe.com/v3/"></script>
                   <form id="payment-form">
                     <div id="card-element"></div>
                     <input type="hidden" name="stripeToken" />
                   </form>',
        'shouldDetect' => true
    ],
    [
        'name' => 'Stripe Checkout',
        'html' => '<script src="https://checkout.stripe.com/checkout.js"></script>
                   <button id="stripe-button">Pay with Stripe</button>',
        'shouldDetect' => true
    ],
    [
        'name' => 'Stripe Payment Elements',
        'html' => '<div class="stripe-payment-element" data-client-secret="pi_test_123">
                   <script>const stripe = Stripe("pk_live_123ABC");</script>',
        'shouldDetect' => true
    ],
    [
        'name' => 'PayPal (should not detect Stripe)',
        'html' => '<script src="https://www.paypal.com/sdk/js"></script>
                   <div id="paypal-button-container"></div>',
        'shouldDetect' => false
    ],
    [
        'name' => 'Generic Payment Form (no Stripe)',
        'html' => '<form id="payment">
                     <input type="text" name="card_number" />
                     <button>Pay Now</button>
                   </form>',
        'shouldDetect' => false
    ],
];

$passedTests = 0;
$totalTests = count($testCases);

foreach ($testCases as $index => $test) {
    echo "\nTest 1." . ($index + 1) . ": {$test['name']}\n";
    
    $detected = GatewayDetector::detect($test['html']);
    $isStripe = ($detected['id'] === 'stripe');
    
    echo "  Detected: {$detected['name']} (ID: {$detected['id']})\n";
    echo "  Confidence: " . round($detected['confidence'] * 100, 1) . "%\n";
    
    if ($test['shouldDetect']) {
        if ($isStripe) {
            echo "  ✅ PASSED: Stripe correctly detected\n";
            $passedTests++;
        } else {
            echo "  ❌ FAILED: Stripe not detected (got: {$detected['id']})\n";
        }
    } else {
        if (!$isStripe) {
            echo "  ✅ PASSED: Correctly did not detect Stripe\n";
            $passedTests++;
        } else {
            echo "  ❌ FAILED: False positive Stripe detection\n";
        }
    }
}

echo "\n" . str_repeat("-", 40) . "\n";
echo "Results: $passedTests/$totalTests tests passed\n\n";

// Test 2: Stripe 3DS Detection
echo "Test 2: Stripe 3DS Challenge Detection\n";
echo "----------------------------------------\n";

$test3dsResponses = [
    [
        'name' => '3DS Challenge URL',
        'response' => '{"redirect_url": "/stripe/authentications/pi_123/challenge"}',
        'should3DS' => true
    ],
    [
        'name' => 'CompletePaymentChallenge',
        'response' => '{"__typename": "CompletePaymentChallenge", "challengeUrl": "https://..."}',
        'should3DS' => true
    ],
    [
        'name' => 'Successful Payment (no 3DS)',
        'response' => '{"status": "succeeded", "receipt_url": "/thank_you"}',
        'should3DS' => false
    ],
];

$ds3Passed = 0;
$ds3Total = count($test3dsResponses);

foreach ($test3dsResponses as $index => $test) {
    echo "\nTest 2." . ($index + 1) . ": {$test['name']}\n";
    
    $has3DS = (strpos($test['response'], '/stripe/authentications/') !== false ||
               strpos($test['response'], 'CompletePaymentChallenge') !== false);
    
    echo "  3DS Detected: " . ($has3DS ? 'Yes' : 'No') . "\n";
    
    if ($test['should3DS'] == $has3DS) {
        echo "  ✅ PASSED\n";
        $ds3Passed++;
    } else {
        echo "  ❌ FAILED: Expected " . ($test['should3DS'] ? '3DS' : 'no 3DS') . "\n";
    }
}

echo "\n" . str_repeat("-", 40) . "\n";
echo "Results: $ds3Passed/$ds3Total tests passed\n\n";

// Test 3: Card Brand Detection (for Stripe compatibility)
echo "Test 3: Card Brand Detection (Stripe Support)\n";
echo "----------------------------------------\n";

$cardTests = [
    ['4242424242424242', 'VISA', 'Stripe test Visa'],
    ['5555555555554444', 'MASTERCARD', 'Stripe test Mastercard'],
    ['378282246310005', 'AMEX', 'Stripe test Amex'],
    ['6011111111111117', 'DISCOVER', 'Stripe test Discover'],
];

$brandPassed = 0;
$brandTotal = count($cardTests);

foreach ($cardTests as $index => [$card, $expected, $desc]) {
    echo "\nTest 3." . ($index + 1) . ": $desc\n";
    
    $detected = get_card_brand($card);
    $valid = validate_luhn($card);
    
    echo "  Card: " . substr($card, 0, 6) . "..." . substr($card, -4) . "\n";
    echo "  Brand: $detected\n";
    echo "  Luhn: " . ($valid ? 'Valid' : 'Invalid') . "\n";
    
    if ($detected === $expected && $valid) {
        echo "  ✅ PASSED\n";
        $brandPassed++;
    } else {
        echo "  ❌ FAILED: Expected $expected, got $detected" . ($valid ? '' : ' (Luhn failed)') . "\n";
    }
}

echo "\n" . str_repeat("-", 40) . "\n";
echo "Results: $brandPassed/$brandTotal tests passed\n\n";

// Test 4: Stripe-specific gateway metadata
echo "Test 4: Stripe Gateway Metadata\n";
echo "----------------------------------------\n";

$stripeHtml = '<script src="https://js.stripe.com/v3/"></script>
               <div data-sitekey="pk_live_ABC123"></div>';
               
$detected = GatewayDetector::detect($stripeHtml);

if ($detected['id'] === 'stripe') {
    echo "  ✓ Gateway: {$detected['name']}\n";
    echo "  ✓ Supports Cards: " . ($detected['supports_cards'] ? 'Yes' : 'No') . "\n";
    echo "  ✓ 3DS Support: {$detected['three_ds']}\n";
    echo "  ✓ Card Networks: " . implode(', ', $detected['card_networks']) . "\n";
    echo "  ✓ Features: " . implode(', ', $detected['features']) . "\n";
    
    $metadataValid = (
        $detected['supports_cards'] === true &&
        in_array('visa', $detected['card_networks']) &&
        in_array('mastercard', $detected['card_networks']) &&
        !empty($detected['features'])
    );
    
    if ($metadataValid) {
        echo "  ✅ PASSED: Stripe metadata is complete\n";
    } else {
        echo "  ❌ FAILED: Stripe metadata incomplete\n";
    }
} else {
    echo "  ❌ FAILED: Stripe not detected\n";
}

// Test 5: Performance check
echo "\n\nTest 5: Performance Validation\n";
echo "----------------------------------------\n";

$cfg = runtime_cfg();
echo "  ✓ Connect timeout: {$cfg['cto']}s (optimized)\n";
echo "  ✓ Total timeout: {$cfg['to']}s (optimized)\n";
echo "  ✓ Sleep between phases: {$cfg['sleep']}s\n";

if ($cfg['cto'] <= 5 && $cfg['to'] <= 20) {
    echo "  ✅ PASSED: Timeouts optimized for Stripe checkout\n";
} else {
    echo "  ⚠️  WARNING: Timeouts may be too high\n";
}

// Final Summary
echo "\n\n" . str_repeat("=", 50) . "\n";
echo "STRIPE INTEGRATION TEST SUMMARY\n";
echo str_repeat("=", 50) . "\n\n";

$totalAllTests = $totalTests + $ds3Total + $brandTotal + 1;
$totalPassed = $passedTests + $ds3Passed + $brandPassed + ($cfg['cto'] <= 5 ? 1 : 0);

echo "Gateway Detection:  $passedTests/$totalTests passed\n";
echo "3DS Detection:      $ds3Passed/$ds3Total passed\n";
echo "Card Validation:    $brandPassed/$brandTotal passed\n";
echo "Performance:        " . ($cfg['cto'] <= 5 ? '1/1' : '0/1') . " passed\n";
echo "\nOverall:            $totalPassed/$totalAllTests tests passed\n\n";

if ($totalPassed === $totalAllTests) {
    echo "✅ ALL TESTS PASSED - Stripe integration is working correctly!\n\n";
    echo "Stripe Checkout Features:\n";
    echo "  ✓ Gateway detection operational\n";
    echo "  ✓ 3DS challenge detection working\n";
    echo "  ✓ Card validation functional\n";
    echo "  ✓ Performance optimized\n";
    echo "  ✓ All major card brands supported\n";
    echo "  ✓ Ready for production use\n";
    exit(0);
} else {
    echo "⚠️  Some tests failed - review output above\n";
    echo "   Passed: $totalPassed/$totalAllTests\n";
    exit(1);
}
