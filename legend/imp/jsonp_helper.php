<?php
/**
 * JSONP Query Helper & Performance Enhancement Layer
 * 
 * This file provides enhanced functionality for jsonp.php without modifying it.
 * Adds error handling, caching, validation, and performance optimizations.
 * 
 * @package ShopifyChecker  
 * @version 1.0
 * @author LEGEND_BL
 * 
 * Features:
 * - Query caching for improved performance
 * - Input validation and error handling
 * - Compression support for bandwidth savings
 * - Query statistics and monitoring
 * - API endpoint for query access
 */

// Performance optimization: Enable output compression
if (!headers_sent() && extension_loaded('zlib')) {
    ini_set('zlib.output_compression', 'On');
    ini_set('zlib.output_compression_level', '6');
}

// Error handling configuration
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/**
 * Load and cache the Shopify GraphQL queries
 * 
 * @return array The queries from jsonp.php
 */
function loadShopifyQueries() {
    static $queries = null;
    
    if ($queries === null) {
        $jsonpFile = __DIR__ . '/jsonp.php';
        
        if (!file_exists($jsonpFile)) {
            error_log("[JSONP_HELPER] jsonp.php not found at: $jsonpFile");
            return [];
        }
        
        try {
            $queries = include $jsonpFile;
            
            if (!is_array($queries)) {
                error_log("[JSONP_HELPER] jsonp.php did not return an array");
                $queries = [];
            }
        } catch (Exception $e) {
            error_log("[JSONP_HELPER] Error loading jsonp.php: " . $e->getMessage());
            $queries = [];
        }
    }
    
    return $queries;
}

/**
 * Get a specific query by index
 * 
 * @param int $index Query index (0-based)
 * @return array|null The query or null if not found
 */
function getQueryByIndex($index) {
    $queries = loadShopifyQueries();
    
    if (!isset($queries[$index])) {
        error_log("[JSONP_HELPER] Query index $index not found");
        return null;
    }
    
    return $queries[$index];
}

/**
 * Validate query structure
 * 
 * @param mixed $query The query to validate
 * @return array Validation result with 'valid' and 'errors' keys
 */
function validateQueryStructure($query) {
    $result = ['valid' => true, 'errors' => []];
    
    if (!is_array($query)) {
        $result['valid'] = false;
        $result['errors'][] = 'Query must be an array';
        return $result;
    }
    
    if (!isset($query['query'])) {
        $result['valid'] = false;
        $result['errors'][] = 'Query must have a "query" key';
    } elseif (empty($query['query'])) {
        $result['valid'] = false;
        $result['errors'][] = 'Query string cannot be empty';
    }
    
    return $result;
}

/**
 * Get query statistics
 * 
 * @return array Detailed statistics about loaded queries
 */
function getQueryStatistics() {
    $queries = loadShopifyQueries();
    $stats = [
        'total_queries' => count($queries),
        'total_size_bytes' => 0,
        'queries' => []
    ];
    
    foreach ($queries as $index => $query) {
        $queryData = json_encode($query);
        $size = strlen($queryData);
        
        $stats['queries'][$index] = [
            'index' => $index,
            'size_bytes' => $size,
            'size_kb' => round($size / 1024, 2),
            'has_query_field' => isset($query['query']),
            'query_length' => isset($query['query']) ? strlen($query['query']) : 0,
            'valid' => validateQueryStructure($query)['valid']
        ];
        
        $stats['total_size_bytes'] += $size;
    }
    
    $stats['total_size_kb'] = round($stats['total_size_bytes'] / 1024, 2);
    $stats['total_size_mb'] = round($stats['total_size_bytes'] / (1024 * 1024), 2);
    $stats['average_size_kb'] = $stats['total_queries'] > 0 
        ? round($stats['total_size_kb'] / $stats['total_queries'], 2) 
        : 0;
    
    return $stats;
}

/**
 * Export query as JSON with optional compression
 * 
 * @param int $index Query index
 * @param bool $pretty Pretty-print JSON
 * @return string JSON-encoded query
 */
function exportQueryJSON($index, $pretty = false) {
    $query = getQueryByIndex($index);
    
    if ($query === null) {
        return json_encode([
            'success' => false,
            'error' => 'Query not found',
            'index' => $index
        ], JSON_PRETTY_PRINT);
    }
    
    $validation = validateQueryStructure($query);
    if (!$validation['valid']) {
        return json_encode([
            'success' => false,
            'error' => 'Invalid query structure',
            'validation_errors' => $validation['errors'],
            'index' => $index
        ], JSON_PRETTY_PRINT);
    }
    
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    
    return json_encode([
        'success' => true,
        'index' => $index,
        'query' => $query
    ], $flags);
}

/**
 * API endpoint handler
 * Provides REST API access to queries
 */
function handleAPIRequest() {
    header('Content-Type: application/json; charset=utf-8');
    
    // Enable CORS for API access
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    $action = $_GET['action'] ?? 'stats';
    
    try {
        switch ($action) {
            case 'stats':
                echo json_encode(getQueryStatistics(), JSON_PRETTY_PRINT);
                break;
                
            case 'get':
                $index = isset($_GET['index']) ? (int)$_GET['index'] : 0;
                $pretty = isset($_GET['pretty']) && $_GET['pretty'] === '1';
                echo exportQueryJSON($index, $pretty);
                break;
                
            case 'list':
                $queries = loadShopifyQueries();
                $list = [];
                foreach ($queries as $index => $query) {
                    $list[] = [
                        'index' => $index,
                        'has_query' => isset($query['query']),
                        'size_bytes' => strlen(json_encode($query))
                    ];
                }
                echo json_encode(['success' => true, 'queries' => $list], JSON_PRETTY_PRINT);
                break;
                
            case 'validate':
                $index = isset($_GET['index']) ? (int)$_GET['index'] : 0;
                $query = getQueryByIndex($index);
                if ($query === null) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Query not found',
                        'index' => $index
                    ], JSON_PRETTY_PRINT);
                } else {
                    $validation = validateQueryStructure($query);
                    echo json_encode([
                        'success' => true,
                        'index' => $index,
                        'validation' => $validation
                    ], JSON_PRETTY_PRINT);
                }
                break;
                
            default:
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid action',
                    'available_actions' => ['stats', 'get', 'list', 'validate']
                ], JSON_PRETTY_PRINT);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error',
            'message' => $e->getMessage()
        ], JSON_PRETTY_PRINT);
    }
}

// If accessed directly, act as API endpoint
if (basename($_SERVER['PHP_SELF']) === 'jsonp_helper.php') {
    handleAPIRequest();
}
