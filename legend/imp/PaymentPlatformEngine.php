<?php
/**
 * Lightweight platform detection and analysis helpers used by autosh.php.
 *
 * Provides:
 *  - Site profiling (Shopify vs WooCommerce vs hosted checkouts)
 *  - Gateway signal normalization for Stripe / Razorpay / PayU
 *  - Metadata-only analysis flows for non-Shopify platforms
 *
 * The helpers deliberately stop short of attempting card submissions on
 * non-Shopify stacks (tokenisation is JS-driven). Instead we surface
 * normalized intelligence so that callers can plug in custom automation.
 */

if (!function_exists('platform_engine_fetch_document')) {

    /**
     * Fetch a document with the same proxy/timeouts used by autosh.php.
     *
     * @param string $url
     * @param array  $options {cookie, headers, referer, method, body}
     *
     * @return array{url:string,status:int,headers_raw:string,body:string,content_type:?string,info:array,error:?string,errno:int}
     */
    function platform_engine_fetch_document(string $url, array $options = []): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if (!empty($options['cookie'])) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $options['cookie']);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $options['cookie']);
        }

        if (!empty($options['method']) && strtoupper((string)$options['method']) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body'] ?? '');
        }

        if (function_exists('apply_common_timeouts')) {
            apply_common_timeouts($ch);
        }
        if (function_exists('apply_proxy_if_used')) {
            apply_proxy_if_used($ch, $url);
        }

        $defaultHeaders = [
            'User-Agent: ' . (function_exists('flow_user_agent') ? flow_user_agent() : 'Mozilla/5.0'),
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
            'Accept-Language: en-US,en;q=0.9',
            'Connection: keep-alive',
        ];
        if (!empty($options['referer'])) {
            $defaultHeaders[] = 'Referer: ' . $options['referer'];
        }
        if (!empty($options['headers']) && is_array($options['headers'])) {
            $defaultHeaders = array_merge($defaultHeaders, $options['headers']);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $defaultHeaders);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        curl_close($ch);

        if ($response === false) {
            return [
                'url' => $url,
                'status' => 0,
                'headers_raw' => '',
                'body' => '',
                'content_type' => null,
                'info' => $info ?: [],
                'error' => $error,
                'errno' => $errno,
            ];
        }

        $headerSize = $info['header_size'] ?? 0;
        $headersRaw = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        return [
            'url' => $url,
            'status' => (int)($info['http_code'] ?? 0),
            'headers_raw' => $headersRaw,
            'body' => $body,
            'content_type' => $info['content_type'] ?? null,
            'info' => $info ?: [],
            'error' => $error,
            'errno' => $errno,
        ];
    }

    /**
     * Attempt to detect the e-commerce platform powering the site.
     *
     * @param string $baseUrl Fully qualified URL (https://example.com or checkout page).
     * @param array  $options Optional fetch options.
     *
     * @return array Profile metadata.
     */
    function platform_engine_detect_profile(string $baseUrl, array $options = []): array
    {
        static $cache = [];
        $normalizedUrl = rtrim($baseUrl, '/');
        $cacheKey = md5(strtolower($normalizedUrl));
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $document = platform_engine_fetch_document($normalizedUrl, $options);
        $html = $document['body'] ?? '';
        $lower = strtolower($html);

        $platform = 'unknown';
        $indicators = [];

        if (strpos($lower, 'cdn.shopify.com') !== false || strpos($lower, 'shopify') !== false) {
            $platform = 'shopify';
            $indicators[] = 'cdn.shopify.com';
        }

        if ($platform === 'unknown' && (strpos($lower, 'woocommerce') !== false || strpos($lower, 'wc-checkout') !== false || strpos($lower, 'wp-content/plugins/woocommerce') !== false)) {
            $platform = 'woocommerce';
            $indicators[] = 'woocommerce';
        }

        if ($platform === 'unknown' && (strpos($lower, 'checkout.stripe.com') !== false || strpos($lower, 'stripe-session-id') !== false)) {
            $platform = 'stripe_checkout';
            $indicators[] = 'stripe_checkout';
        }

        if ($platform === 'unknown' && (strpos($lower, 'checkout.razorpay.com') !== false || strpos($lower, 'razorpay_order_id') !== false)) {
            $platform = 'razorpay_checkout';
            $indicators[] = 'razorpay_checkout';
        }

        if ($platform === 'unknown' && (strpos($lower, 'secure.payu') !== false || strpos($lower, 'payubiz') !== false || strpos($lower, 'boltpay') !== false)) {
            $platform = 'payu_checkout';
            $indicators[] = 'payu_checkout';
        }

        if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(strip_tags($m[1]));
        } else {
            $title = null;
        }

        $gatewayExtra = [];
        if ($platform === 'woocommerce') {
            $gatewayExtra[] = 'woocommerce';
        } elseif ($platform === 'stripe_checkout') {
            $gatewayExtra[] = 'stripe checkout';
        } elseif ($platform === 'razorpay_checkout') {
            $gatewayExtra[] = 'razorpay';
        } elseif ($platform === 'payu_checkout') {
            $gatewayExtra[] = 'payu';
        }

        $gatewayCandidates = class_exists('GatewayDetector')
            ? GatewayDetector::detectAll($html, $normalizedUrl, $gatewayExtra)
            : [];

        $checkoutPaths = [];
        $requiresJs = false;
        switch ($platform) {
            case 'woocommerce':
                $checkoutPaths = ['/checkout/', '/?wc-ajax=checkout'];
                $requiresJs = true; // most card gateways rely on JS tokenisation
                break;
            case 'stripe_checkout':
                $checkoutPaths = ['/checkout/', '/pay/'];
                $requiresJs = true;
                break;
            case 'razorpay_checkout':
                $checkoutPaths = ['/checkout/', '/?razorpay=1'];
                $requiresJs = true;
                break;
            case 'payu_checkout':
                $checkoutPaths = ['/_payment', '/checkout/', '/payuform'];
                $requiresJs = true;
                break;
        }

        $profile = [
            'url' => $normalizedUrl,
            'platform' => $platform,
            'title' => $title,
            'indicators' => array_values(array_unique($indicators)),
            'http_code' => $document['status'],
            'content_type' => $document['content_type'],
            'gateway_candidates' => $gatewayCandidates,
            'checkout_paths' => $checkoutPaths,
            'requires_js_checkout' => $requiresJs,
            'html' => $html,
            'document' => [
                'status' => $document['status'],
                'length' => strlen($html),
                'errno' => $document['errno'],
                'error' => $document['error'],
            ],
        ];

        // Register globals for downstream reporting if not already set.
        if (empty($GLOBALS['__gateway_primary']) && !empty($gatewayCandidates)) {
            $GLOBALS['__gateway_primary'] = $gatewayCandidates[0];
        }
        if (!empty($gatewayCandidates)) {
            $GLOBALS['__gateway_candidates'] = $gatewayCandidates;
        }
        if (!isset($GLOBALS['__payment_context']) || !is_array($GLOBALS['__payment_context'])) {
            $GLOBALS['__payment_context'] = [];
        }
        $GLOBALS['__payment_context']['platform'] = $platform;
        $GLOBALS['__payment_context']['platform_title'] = $title;
        $GLOBALS['__payment_context']['site_http_code'] = $document['status'];
        $GLOBALS['__payment_context']['analysis'] = [
            'indicators' => $profile['indicators'],
            'checkout_paths' => $checkoutPaths,
            'requires_js_checkout' => $requiresJs,
        ];

        return $cache[$cacheKey] = $profile;
    }

    /**
     * Attempt to handle non-Shopify flows. Returns a response array when handled,
     * or null to fallback to the legacy Shopify executor.
     *
     * @param array $profile  Result from platform_engine_detect_profile().
     * @param array $context  Additional context (site, card, billing, customer).
     *
     * @return array|null
     */
    function platform_engine_try_handle(array $profile, array $context)
    {
        $platform = $profile['platform'] ?? 'unknown';
        if ($platform === 'shopify' || $platform === 'unknown') {
            return null;
        }

        switch ($platform) {
            case 'woocommerce':
                return platform_engine_handle_woocommerce($profile, $context);
            case 'stripe_checkout':
                return platform_engine_handle_hosted_gateway($profile, $context, 'stripe');
            case 'razorpay_checkout':
                return platform_engine_handle_hosted_gateway($profile, $context, 'razorpay');
            case 'payu_checkout':
                return platform_engine_handle_hosted_gateway($profile, $context, 'payu');
            default:
                return [
                    'Response' => 'Detected platform ' . $platform . ' (analysis only)',
                    'Flow' => 'analysis:' . $platform,
                    'GatewayCandidates' => $profile['gateway_candidates'] ?? [],
                    'Notes' => 'Automation requires custom flow handlers. No action taken.',
                    'Platform' => $platform,
                ];
        }
    }

    /**
     * Render metadata for WooCommerce sites (analysis mode).
     */
    function platform_engine_handle_woocommerce(array $profile, array $context): array
    {
        $site = rtrim($context['site'] ?? $profile['url'], '/');
        $checkoutUrl = $site . '/checkout/';
        $checkoutDocument = platform_engine_fetch_document($checkoutUrl, [
            'referer' => $site . '/',
        ]);
        $checkoutHtml = $checkoutDocument['body'] ?? '';

        $paymentMethods = platform_engine_parse_woocommerce_payment_methods($checkoutHtml);
        $nonce = null;
        if (preg_match('/name="woocommerce-process-checkout-nonce"\s+value="([^"]+)"/i', $checkoutHtml, $m)) {
            $nonce = $m[1];
        }

        $stripeKey = null;
        if (preg_match('/"publishableKey":"([^"]+)"/', $checkoutHtml, $m)) {
            $stripeKey = $m[1];
        } elseif (preg_match('/data-publishable-key="([^"]+)"/', $checkoutHtml, $m)) {
            $stripeKey = $m[1];
        }

        $razorpayKey = null;
        if (preg_match('/data-key="(rzp_[^"]+)"/', $checkoutHtml, $m)) {
            $razorpayKey = $m[1];
        }

        $payuKey = null;
        if (preg_match('/data-merchant-key="([^"]+)"/', $checkoutHtml, $m)) {
            $payuKey = $m[1];
        }

        $productsEndpoint = $site . '/wp-json/wc/store/products?per_page=8&orderby=price&order=asc';
        $productsResponse = platform_engine_fetch_document($productsEndpoint, [
            'headers' => ['Accept: application/json'],
        ]);
        $productsBody = $productsResponse['body'] ?? '';
        $cheapestProduct = null;
        if ($productsResponse['status'] >= 200 && $productsResponse['status'] < 300) {
            $productsJson = json_decode($productsBody, true);
            if (is_array($productsJson)) {
                foreach ($productsJson as $product) {
                    if (!isset($product['prices']['price'])) {
                        continue;
                    }
                    $priceValue = (float)($product['prices']['price'] / 100);
                    $cheapestProduct = [
                        'id' => $product['id'] ?? null,
                        'name' => $product['name'] ?? null,
                        'price' => $priceValue,
                        'currency' => $product['prices']['currency_code'] ?? 'USD',
                        'type' => $product['type'] ?? null,
                    ];
                    break;
                }
            }
        }

        $gatewayHints = [];
        if ($stripeKey) {
            $gatewayHints['stripe_publishable_key'] = $stripeKey;
        }
        if ($razorpayKey) {
            $gatewayHints['razorpay_key'] = $razorpayKey;
        }
        if ($payuKey) {
            $gatewayHints['payu_key'] = $payuKey;
        }

        $supportsCards = false;
        foreach ($paymentMethods as $pm) {
            if (!empty($pm['supports_cards'])) {
                $supportsCards = true;
                break;
            }
        }

        $primaryGateway = $profile['gateway_candidates'][0]['name'] ?? ($supportsCards ? 'Card Processor' : 'WooCommerce');

        return [
            'Response' => 'WooCommerce storefront detected (analysis mode)',
            'Flow' => 'analysis:woocommerce',
            'Platform' => 'WooCommerce',
            'Gateway' => $primaryGateway,
            'GatewayCandidates' => $profile['gateway_candidates'],
            'CheckoutUrl' => $checkoutUrl,
            'NonceDetected' => $nonce !== null,
            'PublishableKeys' => $gatewayHints,
            'PaymentMethods' => $paymentMethods,
            'CheapestProduct' => $cheapestProduct,
            'SupportsCards' => $supportsCards,
            'RequiresJsTokenisation' => true,
            'Notes' => 'Tokenisation is handled client-side (Stripe/Razorpay/PayU). Use the publishable key hints to bootstrap custom integrations.',
        ];
    }

    /**
     * Parse WooCommerce payment method list.
     *
     * @param string $html Checkout HTML.
     * @return array<int, array<string,mixed>>
     */
    function platform_engine_parse_woocommerce_payment_methods(string $html): array
    {
        $methods = [];
        if (!is_string($html) || $html === '') {
            return $methods;
        }

        $pattern = '/<li[^>]*class="[^"]*wc_payment_method[^"]*payment_method_([a-z0-9_]+)[^"]*"[^>]*>(.*?)<\/li>/is';
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $id = strtolower(trim($match[1]));
                $segment = $match[2] ?? '';
                $label = null;

                if (preg_match('/<label[^>]*>(.*?)<\/label>/is', $segment, $labelMatch)) {
                    $label = trim(strip_tags($labelMatch[1]));
                } else {
                    $label = strtoupper($id);
                }

                $requiresToken = false;
                $supportsCards = false;
                $family = 'other';

                if (strpos($id, 'stripe') !== false || strpos($segment, 'stripe') !== false) {
                    $requiresToken = true;
                    $supportsCards = true;
                    $family = 'stripe';
                } elseif (strpos($id, 'razorpay') !== false) {
                    $requiresToken = true;
                    $supportsCards = true;
                    $family = 'razorpay';
                } elseif (strpos($id, 'payu') !== false) {
                    $requiresToken = true;
                    $supportsCards = true;
                    $family = 'payu';
                } elseif (strpos($id, 'paypal') !== false || strpos($segment, 'paypal') !== false) {
                    $supportsCards = true;
                    $family = 'paypal';
                } elseif (strpos($id, 'cod') !== false) {
                    $family = 'cash_on_delivery';
                } elseif (strpos($id, 'bacs') !== false || strpos($id, 'bank') !== false) {
                    $family = 'bank_transfer';
                }

                $methods[] = [
                    'id' => $id,
                    'label' => $label,
                    'family' => $family,
                    'requires_tokenisation' => $requiresToken,
                    'supports_cards' => $supportsCards,
                ];
            }
        }

        return $methods;
    }

    /**
     * Generic hosted checkout analysis (Stripe / Razorpay / PayU).
     */
    function platform_engine_handle_hosted_gateway(array $profile, array $context, string $provider): array
    {
        $html = $profile['html'] ?? '';
        $hints = [];
        $supportsCards = true;

        if ($provider === 'stripe') {
            if (preg_match('/data-session-id="([^"]+)"/', $html, $m)) {
                $hints['session_id'] = $m[1];
            }
            if (preg_match('/"publishableKey":"([^"]+)"/', $html, $m)) {
                $hints['publishable_key'] = $m[1];
            }
            $notes = 'Stripe Checkout relies on Stripe.js for card tokenisation. Use the publishable key to create PaymentIntents via Stripe API before redirecting customers.';
        } elseif ($provider === 'razorpay') {
            if (preg_match('/data-key="(rzp_[^"]+)"/', $html, $m)) {
                $hints['key_id'] = $m[1];
            }
            if (preg_match('/data-order-id="([^"]+)"/', $html, $m)) {
                $hints['order_id'] = $m[1];
            }
            $notes = 'Hosted Razorpay checkout detected. Create orders via Razorpay API server-side, then supply key/order_id in your integration.';
        } elseif ($provider === 'payu') {
            if (preg_match('/name="key"\s+value="([^"]+)"/', $html, $m)) {
                $hints['merchant_key'] = $m[1];
            }
            if (preg_match('/name="txnid"\s+value="([^"]+)"/', $html, $m)) {
                $hints['txnid'] = $m[1];
            }
            $notes = 'PayU hosted checkout requires hash generation and form POST. Use the merchant key hint and generate hashes with your secret.';
        } else {
            $notes = ucfirst($provider) . ' hosted checkout detected. Custom automation required.';
        }

        $gatewayName = $profile['gateway_candidates'][0]['name'] ?? ucfirst($provider) . ' Checkout';

        return [
            'Response' => ucfirst($provider) . ' hosted checkout detected (analysis mode)',
            'Flow' => 'analysis:' . $provider,
            'Platform' => ucfirst($provider) . ' Checkout',
            'Gateway' => $gatewayName,
            'GatewayCandidates' => $profile['gateway_candidates'],
            'SupportsCards' => $supportsCards,
            'RequiresJsTokenisation' => true,
            'CheckoutHints' => $hints,
            'Notes' => $notes,
        ];
    }
}
