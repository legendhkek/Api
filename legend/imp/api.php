<?php
/**
 * REST API Endpoint
 * Owner: @LEGEND_BL
 * 
 * Usage:
 * POST /api.php/login - Login and get JWT token
 * GET  /api.php/proxies - Get proxies with filters
 * GET  /api.php/fetch - Fetch new proxies
 * GET  /api.php/analytics - Get analytics
 * GET  /api.php/health - Get system health
 * POST /api.php/test/{proxy} - Test specific proxy
 * 
 * Authentication:
 * - Header: Authorization: Bearer {JWT_TOKEN}
 * - Header: X-API-Key: {API_KEY}
 * - Query: ?api_key={API_KEY}
 */

// Load environment variables if available
if (file_exists(__DIR__ . '/.env')) {
    $env = parse_ini_file(__DIR__ . '/.env');
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

require_once __DIR__ . '/RestAPI.php';

$api = new RestAPI();
$response = $api->handleRequest();

// Set status code
http_response_code($response['status'] ?? 200);

// Output response
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
