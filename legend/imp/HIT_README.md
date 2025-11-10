# 💳 HIT.PHP - Advanced CC Checker with Custom Address & Proxy Rotation

## Overview

`hit.php` is an advanced credit card checking tool that allows you to:
- ✅ **Use custom addresses** for every check (required by most gateways)
- ✅ **Check multiple cards** in bulk
- ✅ **Automatic proxy rotation** like autosh.php
- ✅ **Gateway auto-detection** (Stripe, PayPal, Shopify, etc.)
- ✅ **Rate limit handling** with intelligent proxy switching
- ✅ **Luhn validation** before checking
- ✅ **JSON & HTML output** for automation or manual use

---

## 🚀 Quick Start

### Web Interface
1. Open `hit.php` in your browser
2. Fill in:
   - Credit card(s) in format: `number|month|year|cvv`
   - Address (multiple formats supported)
   - Target site URL
3. Click "Start Checking"

### API Usage

#### Basic Check (Single Card)
```bash
http://localhost:8000/hit.php?cc=4111111111111111|12|2027|123&address=350,5th Ave,New York,NY,10118&site=https://shop.com&format=json
```

#### Multiple Cards
```bash
# Cards separated by newlines (URL encoded as %0A)
http://localhost:8000/hit.php?cc=4111111111111111|12|2027|123%0A5555555555554444|01|2026|456&address=...&site=...&format=json
```

#### Use Random Address
```bash
# Omit address parameter for random US address
http://localhost:8000/hit.php?cc=CARD&site=URL&format=json
```

#### State-Based Address
```bash
# Get random address from specific state
http://localhost:8000/hit.php?cc=CARD&state=CA&site=URL&format=json
```

---

## 📍 Address Formats

### Comma-Separated
```
350, 5th Ave, New York, NY, 10118
```

### Pipe-Separated
```
350|5th Ave|New York|NY|10118
```

### JSON Format
```json
{
  "numd": "350",
  "address1": "5th Ave",
  "address2": "",
  "city": "New York",
  "state": "NY",
  "zip": "10118",
  "country": "US"
}
```

### State-Based (Random)
```
Use parameter: state=CA
```

### Auto-Random
```
Omit address parameter - generates random US address
```

---

## 🔧 Parameters

### Required Parameters
| Parameter | Description | Example |
|-----------|-------------|---------|
| `cc` | Credit card(s) in `number\|month\|year\|cvv` format | `4111111111111111\|12\|2027\|123` |
| `site` | Target website URL | `https://shop.myshopify.com` |

### Optional Parameters
| Parameter | Description | Default | Example |
|-----------|-------------|---------|---------|
| `address` | Custom address (comma or pipe separated) | Random | `350, 5th Ave, New York, NY, 10118` |
| `state` | Get random address from state | - | `CA`, `NY`, `TX` |
| `format` | Output format (`html` or `json`) | `html` | `json` |
| `rotate` | Enable proxy rotation | `1` | `0` (disable), `1` (enable) |
| `proxy` | Use specific proxy (disables rotation) | - | `socks5://proxy.com:1080` |
| `debug` | Enable debug mode | `0` | `1` |

---

## 📊 Response Format

### JSON Response
```json
{
  "success": true,
  "count": 2,
  "results": [
    {
      "success": false,
      "card": "4111111111111111",
      "brand": "Visa",
      "address": "350 5th Ave\nNew York, NY 10118",
      "site": "https://shop.com",
      "message": "Card declined",
      "gateway": "Stripe",
      "proxy_used": "socks5://proxy.com:1080",
      "response_time": 1234,
      "status": "DECLINED",
      "http_code": 200,
      "timestamp": "2025-11-10 15:30:45"
    }
  ],
  "address_used": "350 5th Ave\nNew York, NY 10118",
  "address_source": "custom",
  "proxy_rotation": true,
  "proxies_loaded": 150,
  "execution_time_ms": 2500,
  "timestamp": "2025-11-10 15:30:45"
}
```

### Status Codes
| Status | Description |
|--------|-------------|
| `LIVE` | Card is valid and payment went through |
| `DECLINED` | Card was declined by gateway |
| `INVALID` | Card failed Luhn validation |
| `RATE_LIMITED` | Hit rate limit (will auto-rotate proxy) |
| `SERVER_ERROR` | Target server error (5xx) |
| `ERROR` | Connection or other error |
| `UNKNOWN` | Unable to determine status |

---

## 🌐 Supported Gateways

The tool auto-detects these payment gateways:
- ✅ Stripe
- ✅ PayPal / Braintree
- ✅ Shopify Payments
- ✅ WooCommerce
- ✅ Razorpay
- ✅ Square
- ✅ Authorize.Net
- ✅ Adyen
- ✅ PayU
- ✅ And many more...

---

## 🔄 Proxy Rotation

### How It Works
1. Loads proxies from `ProxyList.txt`
2. Rotates to a new proxy for each check
3. Detects rate limiting (HTTP 429, 503)
4. Automatically switches proxy on rate limit
5. Tracks proxy performance and health

### Configuration
```php
// In hit.php, rate limiting is pre-configured:
- Rate limit detection: Enabled
- Auto-rotate on rate limit: Enabled  
- Rate limit cooldown: 60 seconds
- Max rate limit retries: 5
```

### Disable Rotation
```bash
# Use specific proxy
hit.php?cc=CARD&address=ADDR&site=URL&proxy=socks5://proxy.com:1080

# Or disable rotation
hit.php?cc=CARD&address=ADDR&site=URL&rotate=0
```

---

## 💡 Usage Examples

### Example 1: Single Card with Custom Address
```bash
curl "http://localhost:8000/hit.php?cc=4111111111111111|12|2027|123&address=350,5th Ave,New York,NY,10118&site=https://shop.com&format=json"
```

### Example 2: Bulk Checking (Multiple Cards)
```bash
# Create cards.txt
cat > cards.txt << EOF
4111111111111111|12|2027|123
5555555555554444|01|2026|456
378282246310005|12|2025|1234
EOF

# Check all cards
for card in $(cat cards.txt); do
  curl "http://localhost:8000/hit.php?cc=$card&address=350,5th Ave,New York,NY,10118&site=https://shop.com&format=json"
done
```

### Example 3: Random Address per Check
```bash
# Each check gets a random US address
curl "http://localhost:8000/hit.php?cc=4111111111111111|12|2027|123&site=https://shop.com&format=json"
```

### Example 4: California Addresses Only
```bash
curl "http://localhost:8000/hit.php?cc=4111111111111111|12|2027|123&state=CA&site=https://shop.com&format=json"
```

### Example 5: POST Request
```bash
curl -X POST "http://localhost:8000/hit.php?format=json" \
  -d "cc=4111111111111111|12|2027|123" \
  -d "address=350, 5th Ave, New York, NY, 10118" \
  -d "site=https://shop.com"
```

---

## 🔍 Card Validation

The tool performs **Luhn algorithm** validation before checking:

```
Valid Test Cards:
✅ 4111111111111111 (Visa)
✅ 5555555555554444 (Mastercard)
✅ 378282246310005 (Amex)
✅ 6011111111111117 (Discover)

Invalid Cards (Luhn fail):
❌ 4111111111111112
❌ 1234567890123456
```

---

## 🛠️ Advanced Features

### Debug Mode
```bash
# Enable debug output
hit.php?cc=CARD&address=ADDR&site=URL&debug=1&format=json
```

Debug output includes:
- HTTP response codes
- Response body length
- Gateway detection details
- Proxy usage info
- Curl errors

### Address Validation
The tool supports real US addresses from `add.php`:
- 40+ real US addresses across 30+ states
- Major cities included
- Real ZIP codes and state abbreviations

### Response Time Tracking
Every check includes:
- Individual request response time (ms)
- Total execution time for bulk checks
- Proxy performance metrics

---

## 📋 Requirements

- PHP 7.4+ with cURL extension
- `ProxyList.txt` with working proxies (for rotation)
- Files: `ProxyManager.php`, `ho.php`, `add.php`

---

## 🚨 Important Notes

⚠️ **Legal & Ethical Use Only**
- This tool is for testing and development purposes
- Only use with sites you own or have permission to test
- Never use for fraud or unauthorized access

⚠️ **Proxy Requirements**
- Proxy rotation requires proxies in `ProxyList.txt`
- Use `fetch_proxies.php` to get proxies
- Format: `type://ip:port:user:pass`

⚠️ **Address Validation**
- Many gateways validate address format
- ZIP code must match state/city
- Use real address formats for best results

---

## 🔗 Related Files

- `autosh.php` - Main auto-detection script with 50+ gateways
- `ProxyManager.php` - Proxy rotation and rate limit handling
- `add.php` - US address provider
- `fetch_proxies.php` - Proxy scraper and tester
- `index.php` - Main dashboard

---

## 📞 Support

For issues or questions:
1. Check `hit.php?debug=1` for detailed error info
2. Verify proxies are loaded: Check dashboard at `index.php`
3. Test with known working card: `4111111111111111|12|2027|123`
4. Ensure target site is accessible

---

## 📝 Changelog

### Version 1.0 (2025-11-10)
- ✅ Initial release
- ✅ Custom address support (multiple formats)
- ✅ Bulk CC checking
- ✅ Proxy rotation with rate limit handling
- ✅ Gateway auto-detection
- ✅ Luhn validation
- ✅ JSON & HTML output
- ✅ Debug mode
- ✅ Response time tracking

---

## 🎯 Examples Output

### Success Response
```json
{
  "success": true,
  "status": "LIVE",
  "message": "Payment successful",
  "gateway": "Stripe",
  "response_time": 1234
}
```

### Declined Response
```json
{
  "success": false,
  "status": "DECLINED",
  "message": "Card declined",
  "gateway": "Stripe",
  "response_time": 890
}
```

### Rate Limited (Auto-Rotates)
```json
{
  "success": false,
  "status": "RATE_LIMITED",
  "message": "Rate limited - rotating proxy",
  "gateway": "Shopify",
  "proxy_used": "socks5://old-proxy.com:1080"
}
```

---

**Powered by @LEGEND_BL** 👑
