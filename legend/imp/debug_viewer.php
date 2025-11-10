<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔍 Debug Response Viewer | LEGEND_BL</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: #e2e8f0; 
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #1e293b;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        
        h1 { 
            color: #60a5fa;
            margin-bottom: 20px;
            font-size: 32px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        h2 {
            color: #93c5fd;
            margin: 30px 0 15px 0;
            font-size: 22px;
        }
        
        .info { 
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            border-left: 4px solid #60a5fa;
        }
        
        .info code {
            background: rgba(0, 0, 0, 0.3);
            padding: 3px 8px;
            border-radius: 4px;
            font-family: 'Consolas', monospace;
            color: #fbbf24;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #991b1b, #7f1d1d);
            border-left: 4px solid #ef4444;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #92400e, #78350f);
            border-left: 4px solid #f59e0b;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #065f46, #064e3b);
            border-left: 4px solid #10b981;
        }
        
        pre { 
            background: #0f172a;
            padding: 20px;
            border-radius: 12px;
            overflow-x: auto;
            border-left: 4px solid #60a5fa;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            line-height: 1.6;
            max-height: 600px;
            overflow-y: auto;
        }
        
        a { 
            color: #60a5fa;
            text-decoration: none;
            transition: color 0.2s;
        }
        
        a:hover {
            color: #93c5fd;
            text-decoration: underline;
        }
        
        ul {
            background: #0f172a;
            padding: 20px 20px 20px 40px;
            border-radius: 12px;
            border-left: 4px solid #60a5fa;
        }
        
        ul li {
            margin: 10px 0;
            color: #cbd5e1;
        }
        
        ul strong {
            color: #60a5fa;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .status-success {
            background: #065f46;
            color: #10b981;
        }
        
        .status-error {
            background: #991b1b;
            color: #ef4444;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border-radius: 10px;
            text-decoration: none;
            transition: all 0.3s;
            margin: 10px 10px 10px 0;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        .metadata {
            background: #0f172a;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 12px;
            color: #94a3b8;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #60a5fa;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            <span>🔍</span>
            Debug Response Viewer
        </h1>
        
        <div class="info">
            <strong>Usage:</strong> Add <code>?debug=1</code> to your autosh.php URL to save debug info.<br>
            <strong>Example:</strong> <code>autosh.php?cc=4111111111111111|12|2025|123&site=https://example.com&debug=1</code>
        </div>
        
        <div>
            <a href="/" class="btn">← Back to Dashboard</a>
            <a href="?refresh=1" class="btn">🔄 Refresh</a>
            <?php if (file_exists('proposal_debug.json')): ?>
                <a href="?delete=1" class="btn btn-danger" onclick="return confirm('Delete debug file?')">🗑️ Clear Debug Data</a>
            <?php endif; ?>
        </div>
    
    <?php
    // Handle delete request
    if (isset($_GET['delete']) && file_exists('proposal_debug.json')) {
        if (unlink('proposal_debug.json')) {
            echo '<div class="alert alert-success">✓ Debug file deleted successfully</div>';
        } else {
            echo '<div class="alert alert-error">✗ Failed to delete debug file</div>';
        }
    }
    
    // Main debug display with comprehensive error handling
    try {
        if (file_exists('proposal_debug.json')) {
            $content = @file_get_contents('proposal_debug.json');
            
            if ($content === false) {
                throw new Exception('Failed to read debug file');
            }
            
            $data = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }
            
            if (!$data) {
                throw new Exception('Empty or invalid debug data');
            }
            
            // File metadata
            $fileSize = filesize('proposal_debug.json');
            $lastModified = filemtime('proposal_debug.json');
            
            echo '<div class="metadata">';
            echo '<strong>File Info:</strong> ';
            echo 'Size: ' . number_format($fileSize) . ' bytes | ';
            echo 'Last Modified: ' . date('Y-m-d H:i:s', $lastModified) . ' (' . human_time_diff($lastModified) . ')';
            echo '</div>';
            
            // Display raw JSON
            echo "<h2>📄 Raw JSON Response</h2>";
            echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "</pre>";
            
            // Analyze structure
            echo "<h2>📊 Response Structure Analysis</h2>";
            echo "<ul>";
            
            // Check for errors first
            if (isset($data['errors']) && is_array($data['errors'])) {
                echo "<li class='alert alert-error'><strong>⚠️ Errors Found:</strong><ul>";
                foreach ($data['errors'] as $idx => $error) {
                    $message = isset($error['message']) ? htmlspecialchars($error['message']) : 'Unknown error';
                    $code = isset($error['code']) ? htmlspecialchars($error['code']) : '';
                    echo "<li>Error " . ($idx + 1) . ": " . $message;
                    if ($code) echo " <span class='status-badge status-error'>" . $code . "</span>";
                    echo "</li>";
                }
                echo "</ul></li>";
            } else {
                echo "<li><strong>Errors:</strong> <span class='status-badge status-success'>None</span></li>";
            }
            
            // Check sellerProposal
            if (isset($data['data']['session']['negotiate']['result']['sellerProposal'])) {
                $proposal = $data['data']['session']['negotiate']['result']['sellerProposal'];
                echo "<li><strong>Seller Proposal:</strong> <span class='status-badge status-success'>Found</span>";
                echo "<ul>";
                
                // Delivery analysis
                echo "<li><strong>Delivery:</strong> ";
                if (isset($proposal['delivery']['deliveryLines'][0])) {
                    $line = $proposal['delivery']['deliveryLines'][0];
                    echo "<span class='status-badge status-success'>Available</span><ul>";
                    
                    if (isset($line['availableDeliveryStrategies']) && is_array($line['availableDeliveryStrategies'])) {
                        echo "<li>Strategies: " . count($line['availableDeliveryStrategies']) . " available</li>";
                        foreach ($line['availableDeliveryStrategies'] as $idx => $strategy) {
                            $handle = isset($strategy['handle']) ? htmlspecialchars($strategy['handle']) : 'NO HANDLE';
                            $title = isset($strategy['title']) ? htmlspecialchars($strategy['title']) : '';
                            echo "<li>Strategy " . ($idx + 1) . ": " . $handle;
                            if ($title) echo " (" . $title . ")";
                            echo "</li>";
                        }
                    } else {
                        echo "<li><span class='status-badge status-error'>No delivery strategies</span></li>";
                    }
                    
                    echo "</ul>";
                } else {
                    echo "<span class='status-badge status-error'>Missing</span>";
                }
                echo "</li>";
                
                // Payment analysis
                echo "<li><strong>Payment:</strong> ";
                if (isset($proposal['payment']['availablePaymentLines']) && is_array($proposal['payment']['availablePaymentLines'])) {
                    $count = count($proposal['payment']['availablePaymentLines']);
                    echo "<span class='status-badge status-success'>" . $count . " method(s)</span>";
                } else {
                    echo "<span class='status-badge status-error'>Missing</span>";
                }
                echo "</li>";
                
                // Tax analysis
                echo "<li><strong>Tax:</strong> ";
                if (isset($proposal['tax']['totalTaxAmount']['value']['amount'])) {
                    $amount = htmlspecialchars($proposal['tax']['totalTaxAmount']['value']['amount']);
                    $currency = isset($proposal['tax']['totalTaxAmount']['value']['currencyCode']) 
                        ? htmlspecialchars($proposal['tax']['totalTaxAmount']['value']['currencyCode']) 
                        : '';
                    echo "<span class='status-badge status-success'>" . $amount . " " . $currency . "</span>";
                } else {
                    echo "<span class='status-badge status-warning'>Not calculated</span>";
                }
                echo "</li>";
                
                echo "</ul></li>";
            } else {
                echo "<li><strong>Seller Proposal:</strong> <span class='status-badge status-error'>Not Found</span></li>";
            }
            
            // Data structure summary
            $keys = array_keys($data);
            echo "<li><strong>Top-level keys:</strong> " . implode(', ', array_map('htmlspecialchars', $keys)) . "</li>";
            
            echo "</ul>";
            
        } else {
            echo '<div class="alert alert-warning">';
            echo '<span style="font-size: 24px;">⚠️</span>';
            echo '<div>';
            echo '<strong>No Debug File Found</strong><br>';
            echo 'Run autosh.php with <code>?debug=1</code> parameter to generate debug info.<br>';
            echo 'The debug file will be saved as <code>proposal_debug.json</code> in this directory.';
            echo '</div>';
            echo '</div>';
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-error">';
        echo '<span style="font-size: 24px;">✗</span>';
        echo '<div>';
        echo '<strong>Error Loading Debug Data</strong><br>';
        echo 'Message: ' . htmlspecialchars($e->getMessage());
        echo '</div>';
        echo '</div>';
    }
    
    // Helper function
    function human_time_diff($from, $to = null) {
        if ($to === null) {
            $to = time();
        }
        $diff = abs($to - $from);
        
        if ($diff < 60) return $diff . 's ago';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return floor($diff / 86400) . 'd ago';
    }
    ?>
    </div>
    
    <script>
    // Auto-refresh option
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('auto') === '1') {
        setTimeout(() => {
            window.location.reload();
        }, 5000);
    }
    
    // Add keyboard shortcut for refresh
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            e.preventDefault();
            window.location.reload();
        }
    });
    </script>
</body>
</html>
