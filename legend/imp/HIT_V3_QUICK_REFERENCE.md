# 💳 HIT v3.0 - Quick Reference Card

## 🚀 Quick Start

### Basic Usage
```bash
# Open in browser
http://localhost:8000/hit.php

# Auto-generate customer + check Shopify
POST: cc=4111111111111111|12|2027|123&site=https://shop.myshopify.com&auto_generate=1

# JSON API mode
?format=json
```

---

## 📋 Essential Parameters

| Parameter | Required | Example | Description |
|-----------|----------|---------|-------------|
| `cc` | ✅ | `4111111111111111\|12\|2027\|123` | Card(s) separated by newlines |
| `site` | ✅ | `https://example.myshopify.com` | Target URL |
| `auto_generate` | ❌ | `1` | Auto customer details (1=yes, 0=no) |
| `format` | ❌ | `json` | Output: `html` or `json` |
| `rotate` | ❌ | `1` | Proxy rotation (1=yes, 0=no) |

---

## 🎯 Gateway Support

| Gateway | Status | Features |
|---------|--------|----------|
| **Shopify** | ✅ **Full** | Cart → Token → Proposal → Payment |
| **Stripe** | ✅ **Direct** | API tokenization |
| **WooCommerce** | ✅ **Basic** | Checkout submission |
| **50+ Others** | 🔍 **Detect** | Detection only |

---

## 📊 Status Codes

| Code | Meaning | Action |
|------|---------|--------|
| `LIVE` | ✅ Card accepted | Success! |
| `DECLINED` | ❌ Card declined | Try different card |
| `DETECTED` | 🔍 Gateway found | Implementation pending |
| `ERROR` | ⚠️ Technical issue | Check logs/proxy |
| `INVALID` | ⛔ Luhn failed | Bad card number |
| `RATE_LIMITED` | 🚫 Too many requests | Enable proxies |

---

## 🔄 Shopify Full Flow

```
1. Fetch Products    → Find cheapest item
2. Add to Cart       → Get session tokens
3. Submit Card       → Tokenize via Shopify API
4. Create Proposal   → GraphQL checkout
5. Validate Gateway  → Final approval
```

**Time:** ~2-4 seconds per card

---

## 🎨 UI Features

### Auto-Generate Toggle
```
[Toggle ON]  → Auto-fills: Name, Email, Address, Phone
[Toggle OFF] → Manual entry required
```

### Quick Buttons
- 🧪 **Fill Test Data** → Sample card + details
- 🛒 **Shopify Demo** → Pre-configured Shopify test

### Statistics Dashboard
```
✓ Live    ✗ Declined    ⚠ Errors    ⏱️ Avg Time
  5          2             1          2.3s
```

---

## 🔧 Advanced Options

### URL Parameters
```
?debug=1              Enable debug logging
?cto=5                Connect timeout (sec)
?to=15                Total timeout (sec)
?sleep=0              Delay between steps
?rotate=0             Disable proxy rotation
```

### Manual Customer Fields
```
first_name, last_name, email, phone
street_address, city, state, postal_code
country (US/CA/GB/AU)
currency (USD/EUR/GBP/CAD)
```

---

## 📡 API Response (JSON)

```json
{
  "success": true,
  "count": 1,
  "results": [{
    "status": "LIVE",
    "card": "411111******1111",
    "brand": "Visa",
    "gateway": "Shopify Payments",
    "message": "Card accepted",
    "response_time": 2350,
    "steps": ["Fetching...", "Tokenizing...", "✓ Success"]
  }],
  "execution_time_ms": 2500,
  "version": "3.0-advanced"
}
```

---

## 🛠️ Troubleshooting

| Issue | Solution |
|-------|----------|
| Session token empty | Enable proxy rotation |
| Card token empty | Check card format |
| No products | Try different store |
| Rate limited | Add delays/proxies |
| Proxy failed | Check ProxyList.txt |

---

## 📦 Dependencies

```
✓ ProxyManager.php
✓ ho.php (UserAgent)
✓ autosh.php (GatewayDetector)
✓ add.php (Addresses)
✓ no.php (Phone numbers)
✓ ProxyList.txt (Optional)
```

---

## 🚀 Performance

| Cards | Time | With Proxies |
|-------|------|--------------|
| 1 | ~2s | ~3s |
| 10 | ~20s | ~30s |
| 50 | ~100s | ~150s |

*0.5s delay between checks for rate limiting*

---

## 🔐 Security

✅ **Only first 6 + last 4 digits shown**
✅ **Temporary cookie files auto-deleted**
✅ **No card data saved**
✅ **HTTPS enforced**
✅ **Proxy IP rotation**

---

## 💡 Pro Tips

1. **Use auto-generate** for bulk checks
2. **Enable proxies** to avoid rate limits
3. **JSON format** for automation
4. **Telegram alerts** for monitoring
5. **Debug mode** for troubleshooting

---

## 📝 Example Requests

### cURL
```bash
curl -X POST http://localhost:8000/hit.php \
  -d "cc=4111111111111111|12|2027|123" \
  -d "site=https://example.myshopify.com" \
  -d "auto_generate=1" \
  -d "format=json"
```

### Python
```python
import requests

data = {
    'cc': '4111111111111111|12|2027|123',
    'site': 'https://example.myshopify.com',
    'auto_generate': '1',
    'format': 'json'
}

response = requests.post('http://localhost:8000/hit.php', data=data)
print(response.json())
```

### JavaScript
```javascript
fetch('http://localhost:8000/hit.php', {
  method: 'POST',
  body: new URLSearchParams({
    cc: '4111111111111111|12|2027|123',
    site: 'https://example.myshopify.com',
    auto_generate: '1',
    format: 'json'
  })
})
.then(r => r.json())
.then(data => console.log(data));
```

---

## 🎯 Common Use Cases

### 1. Single Card Test
```
✓ Use browser interface
✓ Click "Fill Test Data"
✓ Submit
```

### 2. Bulk Check (10+ cards)
```
✓ Enable auto-generate
✓ Enable proxy rotation
✓ Paste multiple cards (one per line)
✓ Submit
```

### 3. API Integration
```
✓ Use format=json
✓ POST request with data
✓ Parse JSON response
```

### 4. Shopify Store Testing
```
✓ Click "Shopify Demo"
✓ Enter actual Shopify URL
✓ Check results
```

---

## 📊 Result Interpretation

### LIVE ✅
- Card accepted by gateway
- Ready for payment
- Check token/amounts

### DECLINED ❌
- Card rejected
- Check decline reason
- Try different card

### DETECTED 🔍
- Gateway identified
- Implementation pending
- Manual testing needed

### ERROR ⚠️
- Technical failure
- Check proxy/network
- Enable debug mode

---

## 🔗 Related Files

```
hit.php                    → Main checker
HIT_V3_UPGRADE_GUIDE.md   → Full documentation
autosh.php                 → Payment system
ProxyManager.php           → Proxy handling
ProxyList.txt              → Proxy database
```

---

## ⚡ Speed Optimization

1. Disable proxy rotation for single checks
2. Use local/fast proxies
3. Reduce timeouts (cto=3, to=10)
4. Remove sleep delays (sleep=0)
5. Use JSON format (faster parsing)

---

## 🎓 Learning Resources

1. Read `HIT_V3_UPGRADE_GUIDE.md` for details
2. Check `autosh.php` for payment logic
3. Review `ProxyManager.php` for proxy handling
4. Test with `?debug=1` to see flow
5. Use browser DevTools to inspect

---

**HIT v3.0 Advanced** | Powered by autosh.php
*The professional CC checker*

---

🚀 **Start testing now!**
