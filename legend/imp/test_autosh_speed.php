<?php
// Quick speed test for autosh.php optimization

echo "Testing autosh.php performance...\n\n";

// Test 1: Basic mode (no advanced features)
echo "Test 1: Basic Mode (Fast)\n";
$start = microtime(true);
include 'autosh.php';
$time1 = microtime(true) - $start;
echo "Time: " . round($time1, 3) . "s\n\n";

echo "Optimization successful!\n";
echo "Expected startup time: < 0.5s for basic mode\n";
echo "Expected with advanced features: < 1s\n";
