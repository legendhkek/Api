<!DOCTYPE html>
<html>
<head>
    <title>Debug Viewer</title>
    <style>
        body { 
            font-family: 'Consolas', monospace; 
            background: #1e1e1e; 
            color: #d4d4d4; 
            padding: 20px; 
        }
        pre { 
            background: #2d2d2d; 
            padding: 20px; 
            border-radius: 8px; 
            overflow-x: auto;
            border-left: 4px solid #007acc;
        }
        h1 { color: #4ec9b0; }
        .info { 
            background: #264f78; 
            padding: 15px; 
            border-radius: 5px; 
            margin-bottom: 20px;
        }
        a { color: #4ec9b0; }
    </style>
</head>
<body>
    <h1>🔍 Debug Response Viewer</h1>
    
    <div class="info">
        <strong>Usage:</strong> Add <code>?debug=1</code> to your autosh.php URL to save debug info.<br>
        Example: <code>autosh.php?cc=4111111111111111|12|2025|123&debug=1</code>
    </div>
    
    <?php
    if (file_exists('proposal_debug.json')) {
        echo "<h2>📄 Latest Proposal Response:</h2>";
        $content = file_get_contents('proposal_debug.json');
        $data = json_decode($content, true);
        
        echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</pre>";
        
        // Show structure
        echo "<h2>📊 Response Structure:</h2>";
        echo "<ul style='background: #2d2d2d; padding: 20px; border-radius: 8px;'>";
        
        if (isset($data['data']['session']['negotiate']['result']['sellerProposal'])) {
            $proposal = $data['data']['session']['negotiate']['result']['sellerProposal'];
            
            echo "<li><strong>Delivery:</strong> ";
            if (isset($proposal['delivery']['deliveryLines'][0])) {
                $line = $proposal['delivery']['deliveryLines'][0];
                echo "Found delivery line<ul>";
                
                if (isset($line['availableDeliveryStrategies'])) {
                    echo "<li>Available strategies: " . count($line['availableDeliveryStrategies']) . "</li>";
                    foreach ($line['availableDeliveryStrategies'] as $idx => $strategy) {
                        echo "<li>Strategy $idx: " . ($strategy['handle'] ?? 'NO HANDLE') . "</li>";
                    }
                } else {
                    echo "<li style='color: #f44;'>❌ No availableDeliveryStrategies</li>";
                }
                
                echo "</ul></li>";
            } else {
                echo "<span style='color: #f44;'>❌ Missing</span></li>";
            }
            
            echo "<li><strong>Payment:</strong> ";
            if (isset($proposal['payment']['availablePaymentLines'])) {
                echo "Found " . count($proposal['payment']['availablePaymentLines']) . " payment methods";
            } else {
                echo "<span style='color: #f44;'>❌ Missing</span>";
            }
            echo "</li>";
            
            echo "<li><strong>Tax:</strong> ";
            if (isset($proposal['tax']['totalTaxAmount']['value']['amount'])) {
                echo $proposal['tax']['totalTaxAmount']['value']['amount'];
            } else {
                echo "<span style='color: #f44;'>❌ Missing</span>";
            }
            echo "</li>";
        } else {
            echo "<li style='color: #f44;'>❌ sellerProposal not found in response</li>";
        }
        
        if (isset($data['errors'])) {
            echo "<li style='color: #f44;'><strong>⚠️ Errors:</strong><ul>";
            foreach ($data['errors'] as $error) {
                echo "<li>" . ($error['message'] ?? 'Unknown error') . "</li>";
            }
            echo "</ul></li>";
        }
        
        echo "</ul>";
        
        echo "<p><small>Last updated: " . date('Y-m-d H:i:s', filemtime('proposal_debug.json')) . "</small></p>";
    } else {
        echo "<div style='background: #3c3c3c; padding: 20px; border-radius: 8px;'>";
        echo "<p>⚠️ No debug file found yet.</p>";
        echo "<p>Run autosh.php with <code>?debug=1</code> parameter to generate debug info.</p>";
        echo "</div>";
    }
    ?>
    
    <hr style="border-color: #3c3c3c; margin: 30px 0;">
    <a href="/">← Back to Dashboard</a>
</body>
</html>
