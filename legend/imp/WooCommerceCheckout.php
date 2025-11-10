<?php
declare(strict_types=1);

if (!class_exists('WooCommerceCheckout')) {
class WooCommerceCheckout
{
    public static function run(array $siteInfo, array $context = []): void
    {
        global $proxy_used, $proxy_ip, $proxy_port;

        $cookieFile = $context['cookie'] ?? ('cookie_wc_' . uniqid('', true) . '.txt');
        $GLOBALS['__cookie_file'] = $cookieFile;

        $preflight = $context['preflight'] ?? [];
        $product = self::resolveProduct($siteInfo, $cookieFile, $preflight);
        if ($product === null) {
            send_final_response([
                'Response' => 'Unable to auto-discover a purchasable product on the WooCommerce storefront.',
                'Platform' => 'WooCommerce',
                'Hint' => 'Provide ?product=<product-slug> or ?product=<product-id> to guide the scanner.',
            ], $proxy_used, $proxy_ip, $proxy_port);
        }

        $addOutcome = self::addToCart($siteInfo, $product, $cookieFile);
        if (!$addOutcome['success']) {
            send_final_response([
                'Response' => 'WooCommerce add-to-cart failed',
                'Platform' => 'WooCommerce',
                'Error' => $addOutcome['error'] ?? 'unknown failure',
                'Details' => [
                    'mode' => $addOutcome['mode'] ?? null,
                    'http_code' => $addOutcome['http_code'] ?? null,
                ],
            ], $proxy_used, $proxy_ip, $proxy_port);
        }

        $checkout = self::fetchCheckout($siteInfo, $cookieFile);
        if (empty($checkout['body'])) {
            send_final_response([
                'Response' => 'Unable to reach WooCommerce checkout endpoint',
                'Platform' => 'WooCommerce',
                'HttpCode' => $checkout['http_code'] ?? null,
                'Error' => $checkout['error'] ?? null,
            ], $proxy_used, $proxy_ip, $proxy_port);
        }

        $analysis = self::analyzeCheckout($checkout['body'], $checkout['effective_url'] ?? self::buildCheckoutUrl($siteInfo));
        $paymentMethods = $analysis['payment_method_ids'];

        $gatewayCandidates = GatewayDetector::detectAll($checkout['body'], $analysis['checkout_url'], $paymentMethods);
        $primaryGateway = $gatewayCandidates[0] ?? GatewayDetector::unknown();

        $GLOBALS['__gateway_primary'] = $primaryGateway;
        $GLOBALS['__gateway_candidates'] = $gatewayCandidates;

        $paymentContext = [
            'platform' => 'woocommerce',
            'currency' => $analysis['currency'] ?? $product['currency'],
            'total' => $analysis['total'] ?? $product['price'],
            'product' => $product,
            'payment_methods' => $paymentMethods,
            'checkout_url' => $analysis['checkout_url'],
            'supports_cards' => $primaryGateway['supports_cards'] ?? null,
            'card_networks' => $primaryGateway['card_networks'] ?? [],
            'stripe' => $analysis['stripe'] ?? null,
            'razorpay' => $analysis['razorpay'] ?? null,
            'payu' => $analysis['payu'] ?? null,
            'notes' => $analysis['notes'] ?? [],
        ];
        $GLOBALS['__payment_context'] = $paymentContext;

        $result = [
            'Response' => 'WooCommerce checkout fingerprint captured. Review payment context for next automation steps.',
            'Platform' => 'WooCommerce',
            'Product' => [
                'Id' => $product['id'],
                'Name' => $product['name'],
                'Price' => $product['price'],
                'Currency' => $paymentContext['currency'],
                'URL' => $product['permalink'],
            ],
            'CheckoutURL' => $analysis['checkout_url'],
            'DetectedPaymentMethods' => $paymentMethods,
            'Gateway' => $primaryGateway['label'] ?? $primaryGateway['name'],
            'GatewaySignals' => $primaryGateway['signals'] ?? [],
            'Cart' => [
                'Mode' => $addOutcome['mode'] ?? 'wc-ajax',
                'CartHash' => $addOutcome['cart_hash'] ?? null,
            ],
        ];

        if (!empty($analysis['stripe'])) {
            $result['Stripe'] = $analysis['stripe'];
        }
        if (!empty($analysis['razorpay'])) {
            $result['Razorpay'] = $analysis['razorpay'];
        }
        if (!empty($analysis['payu'])) {
            $result['PayU'] = $analysis['payu'];
        }
        if (!empty($analysis['notes'])) {
            $result['Notes'] = $analysis['notes'];
        }

        send_final_response($result, $proxy_used, $proxy_ip, $proxy_port);
    }

    private static function buildCheckoutUrl(array $siteInfo): string
    {
        $base = rtrim($siteInfo['base'] ?? '', '/');
        return $base . '/checkout/';
    }

    private static function resolveProduct(array $siteInfo, string $cookieFile, array $preflight): ?array
    {
        $productParam = isset($_GET['product']) ? trim((string) $_GET['product']) : '';
        if ($productParam !== '') {
            if (ctype_digit($productParam)) {
                $product = self::fetchProductById($siteInfo, $cookieFile, (int) $productParam);
                if ($product !== null) {
                    return $product;
                }
            }
            $productUrl = filter_var($productParam, FILTER_VALIDATE_URL)
                ? $productParam
                : self::absoluteUrl($siteInfo['base'], $productParam);
            $product = self::fetchProductFromPage($productUrl, $cookieFile);
            if ($product !== null) {
                return $product;
            }
        }

        $apiProducts = self::fetchProductsViaStoreApi($siteInfo, $cookieFile, 12);
        if (!empty($apiProducts)) {
            return $apiProducts[0];
        }

        if (!empty($preflight['document'])) {
            $fromDoc = self::extractProductFromDocument($siteInfo, $preflight['document'], $cookieFile);
            if ($fromDoc !== null) {
                return $fromDoc;
            }
        }

        return null;
    }

    private static function fetchProductsViaStoreApi(array $siteInfo, string $cookieFile, int $limit = 10): array
    {
        $base = rtrim($siteInfo['base'] ?? '', '/');
        $endpoints = [
            $base . '/wp-json/wc/store/products?per_page=' . $limit . '&orderby=price&order=asc',
            $base . '/wp-json/wc/store/products?per_page=' . $limit,
        ];
        $products = [];
        foreach ($endpoints as $endpoint) {
            $response = http_fetch_document($endpoint, $cookieFile, [
                'Accept: application/json',
                'User-Agent: ' . flow_user_agent(),
            ]);
            if (($response['http_code'] ?? 0) >= 200 && ($response['http_code'] ?? 0) < 300) {
                $data = json_decode($response['body'] ?? '', true);
                if (is_array($data) && !empty($data)) {
                    foreach ($data as $product) {
                        $normalized = self::normalizeProductFromApi($product);
                        if ($normalized !== null && $normalized['price'] !== null && $normalized['price'] >= 0.01) {
                            $products[] = $normalized;
                        }
                    }
                }
            }
            if (!empty($products)) {
                break;
            }
        }

        usort($products, static function (array $a, array $b): int {
            return ($a['price'] ?? 0) <=> ($b['price'] ?? 0);
        });

        return $products;
    }

    private static function normalizeProductFromApi(array $product): ?array
    {
        if (empty($product['id'])) {
            return null;
        }
        $price = null;
        $currency = null;

        if (isset($product['prices']) && is_array($product['prices'])) {
            $priceValue = $product['prices']['price'] ?? $product['prices']['regular_price'] ?? null;
            $currency = $product['prices']['currency_code'] ?? $product['prices']['currency'] ?? null;
            $price = self::toFloatPrice($priceValue, $currency);
        } elseif (isset($product['price'])) {
            $price = self::toFloatPrice($product['price']);
        }

        $permalink = $product['permalink'] ?? $product['link'] ?? null;
        if ($permalink === null && isset($product['slug'])) {
            $permalink = $product['slug'];
        }

        return [
            'id' => (int) $product['id'],
            'name' => self::cleanHtmlText($product['name'] ?? $product['title'] ?? null),
            'price' => $price,
            'currency' => $currency,
            'permalink' => $permalink,
        ];
    }

    private static function fetchProductById(array $siteInfo, string $cookieFile, int $id): ?array
    {
        $base = rtrim($siteInfo['base'] ?? '', '/');
        $endpoint = $base . '/wp-json/wc/store/products/' . $id;
        $response = http_fetch_document($endpoint, $cookieFile, [
            'Accept: application/json',
            'User-Agent: ' . flow_user_agent(),
        ]);
        if (($response['http_code'] ?? 0) >= 200 && ($response['http_code'] ?? 0) < 300) {
            $data = json_decode($response['body'] ?? '', true);
            if (is_array($data)) {
                $normalized = self::normalizeProductFromApi($data);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }
        return null;
    }

    private static function fetchProductFromPage(string $url, string $cookieFile): ?array
    {
        $response = http_fetch_document($url, $cookieFile, [
            'Accept: text/html,application/xhtml+xml',
            'User-Agent: ' . flow_user_agent(),
            'Referer: ' . $url,
        ]);
        if (($response['http_code'] ?? 0) >= 200 && ($response['http_code'] ?? 0) < 400) {
            $product = self::parseProductPage($response['body'] ?? '', $response['effective_url'] ?? $url);
            if (!empty($product['id'])) {
                return $product;
            }
        }
        return null;
    }

    private static function parseProductPage(string $html, string $url): array
    {
        $result = [
            'id' => null,
            'name' => null,
            'price' => null,
            'currency' => null,
            'permalink' => $url,
        ];

        if (preg_match('/data-product_id="(\d+)"/i', $html, $m)) {
            $result['id'] = (int) $m[1];
        } elseif (preg_match('/id="product-(\d+)"/i', $html, $m)) {
            $result['id'] = (int) $m[1];
        }

        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $result['name'] = self::cleanHtmlText($m[1]);
        } elseif (preg_match('/<h1[^>]*class=["\'][^"\']*product_title[^"\']*["\'][^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $result['name'] = self::cleanHtmlText($m[1]);
        }

        if (preg_match('/<meta[^>]+property=["\']product:price:amount["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $result['price'] = self::toFloatPrice($m[1]);
        } elseif (preg_match('/itemprop=["\']price["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $result['price'] = self::toFloatPrice($m[1]);
        } elseif (preg_match('/class=["\'][^"\']*price[^"\']*["\'][^>]*>(.*?)<\/span>/is', $html, $m)) {
            $result['price'] = self::toFloatPrice(strip_tags($m[1]));
        }

        if (preg_match('/<meta[^>]+property=["\']product:price:currency["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $result['currency'] = strtoupper(trim($m[1]));
        } elseif (preg_match('/itemprop=["\']priceCurrency["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $result['currency'] = strtoupper(trim($m[1]));
        }

        return $result;
    }

    private static function extractProductFromDocument(array $siteInfo, string $document, string $cookieFile): ?array
    {
        if (preg_match('/data-product_id="(\d+)"/i', $document, $m)) {
            $id = (int) $m[1];
            $product = self::fetchProductById($siteInfo, $cookieFile, $id);
            if ($product !== null) {
                return $product;
            }
        }
        if (preg_match('/<a[^>]+class=["\'][^"\']*add_to_cart_button[^"\']*["\'][^>]*href=["\']([^"\']+)["\']/i', $document, $m)) {
            $url = self::absoluteUrl($siteInfo['base'], html_entity_decode($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $product = self::fetchProductFromPage($url, $cookieFile);
            if ($product !== null) {
                return $product;
            }
        }
        return null;
    }

    private static function addToCart(array $siteInfo, array $product, string $cookieFile): array
    {
        $base = rtrim($siteInfo['base'] ?? '', '/');
        $endpoint = $base . '/?wc-ajax=add_to_cart';
        $payload = http_build_query([
            'product_id' => $product['id'],
            'quantity' => 1,
            'add-to-cart' => $product['id'],
        ]);
        $headers = [
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Accept: application/json, text/javascript, */*; q=0.01',
            'User-Agent: ' . flow_user_agent(),
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . ($product['permalink'] ?? $base),
        ];
        $response = http_fetch_document($endpoint, $cookieFile, $headers, 'POST', $payload);

        if (($response['http_code'] ?? 0) >= 200 && ($response['http_code'] ?? 0) < 300) {
            $decoded = json_decode($response['body'] ?? '', true);
            if (is_array($decoded)) {
                return [
                    'success' => !empty($decoded['cart_hash']) || isset($decoded['fragments']),
                    'mode' => 'wc-ajax',
                    'cart_hash' => $decoded['cart_hash'] ?? null,
                ];
            }
        }

        $fallbackUrl = $base . '/?add-to-cart=' . urlencode((string) $product['id']);
        $fallback = http_fetch_document($fallbackUrl, $cookieFile, [
            'Accept: text/html,application/xhtml+xml',
            'User-Agent: ' . flow_user_agent(),
            'Referer: ' . ($product['permalink'] ?? $base),
        ]);
        if (($fallback['http_code'] ?? 0) >= 200 && ($fallback['http_code'] ?? 0) < 400) {
            return [
                'success' => true,
                'mode' => 'query-param',
                'cart_hash' => null,
            ];
        }

        return [
            'success' => false,
            'mode' => 'wc-ajax',
            'error' => 'Unable to add product to cart',
            'http_code' => $response['http_code'] ?? null,
            'body' => $response['body'] ?? null,
        ];
    }

    private static function fetchCheckout(array $siteInfo, string $cookieFile): array
    {
        $attempts = [
            self::buildCheckoutUrl($siteInfo),
            rtrim($siteInfo['base'] ?? '', '/') . '/checkout',
        ];
        foreach ($attempts as $url) {
            $response = http_fetch_document($url, $cookieFile, [
                'Accept: text/html,application/xhtml+xml',
                'User-Agent: ' . flow_user_agent(),
                'Referer: ' . ($siteInfo['base'] ?? $url),
            ]);
            if (($response['http_code'] ?? 0) >= 200 && ($response['http_code'] ?? 0) < 500 && !empty($response['body'])) {
                $response['checkout_url'] = $url;
                return $response;
            }
        }
        return ['body' => '', 'http_code' => null, 'error' => 'Unable to load checkout'];
    }

    private static function analyzeCheckout(string $html, string $checkoutUrl): array
    {
        $paymentMethods = [];
        if (preg_match_all('/name=["\']payment_method["\'][^>]*value=["\']([^"\']+)["\']/', $html, $matches)) {
            foreach ($matches[1] as $method) {
                $paymentMethods[] = strtolower(trim($method));
            }
        }
        if (preg_match_all('/data-payment_method=["\']([^"\']+)["\']/', $html, $matches)) {
            foreach ($matches[1] as $method) {
                $paymentMethods[] = strtolower(trim($method));
            }
        }

        $paymentMethods = array_values(array_unique(array_filter($paymentMethods)));

        $currency = null;
        $total = null;
        $notes = [];

        $checkoutConfig = self::extractJsonFromScriptTag($html, 'wc-blocks--checkout-config');
        if (is_array($checkoutConfig)) {
            if (!empty($checkoutConfig['orderData']['currency']['code'])) {
                $currency = strtoupper((string) $checkoutConfig['orderData']['currency']['code']);
            } elseif (!empty($checkoutConfig['orderData']['currency'])) {
                $currency = strtoupper((string) $checkoutConfig['orderData']['currency']);
            }
            if (!empty($checkoutConfig['orderData']['totals']['totalPrice'])) {
                $total = self::toFloatPrice($checkoutConfig['orderData']['totals']['totalPrice'], $currency);
            }
            if (empty($paymentMethods) && !empty($checkoutConfig['paymentMethods'])) {
                foreach ($checkoutConfig['paymentMethods'] as $method) {
                    if (!empty($method['id'])) {
                        $paymentMethods[] = strtolower(trim((string) $method['id']));
                    }
                }
            }
        }

        $wcParams = self::extractJsonFromVar($html, 'wc_checkout_params');
        if (is_array($wcParams)) {
            if ($currency === null && !empty($wcParams['currency'])) {
                $currency = strtoupper((string) $wcParams['currency']);
            }
            if ($currency === null && !empty($wcParams['currency_code'])) {
                $currency = strtoupper((string) $wcParams['currency_code']);
            }
            if ($total === null && !empty($wcParams['i18n_total'])) {
                $total = self::toFloatPrice($wcParams['i18n_total'], $currency);
            }
        }

        $stripeSettings = self::extractJsonFromVar($html, 'wc_stripe_settings') ?? self::extractJsonFromVar($html, 'wcStripeSettings');
        $stripe = null;
        if (is_array($stripeSettings) && (!empty($stripeSettings['publishableKey']) || !empty($stripeSettings['key']))) {
            $stripe = [
                'publishable_key' => $stripeSettings['publishableKey'] ?? $stripeSettings['key'],
                'account_country' => $stripeSettings['accountCountry'] ?? null,
                'statement_descriptor' => $stripeSettings['statementDescriptor'] ?? null,
                'supports' => $stripeSettings['supports'] ?? null,
            ];
            $paymentMethods[] = 'stripe';
        }

        $razorpay = null;
        if (preg_match('/data-key=["\'](rzp_[^"\']+)["\']/', $html, $m)) {
            $razorpay = ['key_id' => $m[1]];
            $paymentMethods[] = 'razorpay';
        } elseif (preg_match('/razorpay_key_id["\']?\s*[:=]\s*["\']([^"\']+)["\']/', $html, $m)) {
            $razorpay = ['key_id' => $m[1]];
            $paymentMethods[] = 'razorpay';
        }

        $payu = null;
        if (preg_match('/payu_(?:merchant_key|key)["\']?\s*[:=]\s*["\']([^"\']+)["\']/', $html, $m)) {
            $payu = ['merchant_key' => $m[1]];
            $paymentMethods[] = 'payu';
        }

        $paymentMethods = array_values(array_unique(array_filter($paymentMethods)));

        if (empty($paymentMethods)) {
            $notes[] = 'No payment_method inputs detected. Site may be using custom checkout blocks.';
        }

        return [
            'payment_method_ids' => $paymentMethods,
            'currency' => $currency,
            'total' => $total,
            'stripe' => $stripe,
            'razorpay' => $razorpay,
            'payu' => $payu,
            'notes' => $notes,
            'checkout_url' => $checkoutUrl,
        ];
    }

    private static function extractJsonFromVar(string $html, string $varName): ?array
    {
        $pattern = sprintf('/%s\s*=\s*(\{.*?\});/s', preg_quote($varName, '/'));
        if (preg_match($pattern, $html, $m)) {
            $json = trim($m[1]);
            if (substr($json, -1) === ';') {
                $json = substr($json, 0, -1);
            }
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $json = html_entity_decode($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private static function extractJsonFromScriptTag(string $html, string $id): ?array
    {
        $pattern = sprintf('/<script[^>]+id=["\']%s["\'][^>]*>(.*?)<\/script>/s', preg_quote($id, '/'));
        if (preg_match($pattern, $html, $m)) {
            $json = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private static function absoluteUrl(string $base, string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        $base = rtrim($base, '/');
        if ($path === '' || $path === '/') {
            return $base . '/';
        }
        if ($path[0] === '/') {
            return $base . $path;
        }
        return $base . '/' . $path;
    }

    private static function toFloatPrice($value, ?string $currency = null): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $float = (float) $value;
            if ($float > 0 && $float > 100 && $currency !== null) {
                return round($float / 100, 2);
            }
            return round($float, 2);
        }
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }
        $float = (float) $clean;
        return round($float, 2);
    }

    private static function cleanHtmlText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
}

