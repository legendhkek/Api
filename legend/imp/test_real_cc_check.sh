#!/bin/bash
echo "╔════════════════════════════════════════════════════════════╗"
echo "║         REAL CC CHECK TEST - AUTOSH.PHP                    ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""
echo "Testing with sample CC: 4532015112830366|12|2025|828"
echo "Site: Stripe Payment Gateway"
echo "Proxy: Auto-rotating from ProxyList.txt"
echo ""
echo "════════════════════════════════════════════════════════════"
php -r '
$_SERVER["REQUEST_METHOD"] = "GET";
$_GET["cc"] = "4532015112830366|12|2025|828";
$_GET["site"] = "stripe";
$_GET["debug"] = "1";
include "autosh.php";
' 2>&1 | tail -50
