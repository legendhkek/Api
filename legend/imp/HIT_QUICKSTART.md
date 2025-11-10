# 🚀 HIT.PHP - Quick Start Guide

## What is HIT.PHP?

**HIT.PHP** is an advanced credit card checker that allows you to **use custom addresses** for every check, with **automatic proxy rotation** just like `autosh.php`. Perfect for testing payment gateways with real address data!

---

## ⚡ 5-Minute Quick Start

### Step 1: Start the Server
```bash
# Windows
START_SERVER.bat

# Linux/Mac
./start_server.sh
```

### Step 2: Open HIT.PHP
```
http://localhost:8000/hit.php
```

### Step 3: Fill the Form
- **Card**: `4111111111111111|12|2027|123`
- **Address**: `350, 5th Ave, New York, NY, 10118`
- **Site**: `https://shop.com`
- Click **"Start Checking"**

### Step 4: View Results
You'll see:
- ✅ Card status (LIVE/DECLINED/etc.)
- ✅ Gateway detected
- ✅ Response time
- ✅ Proxy used (if rotation enabled)

---

## 📍 Address Formats (Choose Any!)

### Format 1: Comma-Separated (Easiest)
```
350, 5th Ave, New York, NY, 10118
```

### Format 2: Pipe-Separated
```
350|5th Ave|New York|NY|10118
```

### Format 3: Random US Address (Auto)
Just leave the address field empty!

### Format 4: State-Based
Use `?state=CA` in URL for California address

---

## 💡 Common Use Cases

### Use Case 1: Test Single Card with Custom Address
```bash
http://localhost:8000/hit.php?cc=4111111111111111|12|2027|123&address=350,5th Ave,New York,NY,10118&site=https://shop.com&format=json
```

### Use Case 2: Bulk Check Multiple Cards
```
Card 1: 4111111111111111|12|2027|123
Card 2: 5555555555554444|01|2026|456
Card 3: 378282246310005|12|2025|1234

Paste all into "Credit Card(s)" field!
```

### Use Case 3: Random Address for Each Check
```bash
# Omit address parameter
http://localhost:8000/hit.php?cc=CARD&site=URL&format=json
```

### Use Case 4: California Addresses Only
```bash
http://localhost:8000/hit.php?cc=CARD&state=CA&site=URL&format=json
```

---

## 🔄 Proxy Rotation (Automatic!)

✅ **Enabled by default** - No configuration needed!
✅ Rotates proxy for each check
✅ Detects rate limiting (HTTP 429/503)
✅ Auto-switches proxy on rate limit
✅ Tracks proxy performance

### Disable Rotation
```bash
?rotate=0
```

### Use Specific Proxy
```bash
?proxy=socks5://proxy.com:1080
```

---

## 📊 Response Statuses

| Status | Meaning |
|--------|---------|
| **LIVE** | ✅ Card approved! |
| **DECLINED** | ❌ Card declined |
| **INVALID** | ❌ Failed Luhn check |
| **RATE_LIMITED** | ⏳ Rate limit (auto-rotating) |
| **ERROR** | ⚠️ Connection error |

---

## 🎯 Test Cards (Safe to Use)

```
Visa:
4111111111111111|12|2027|123

Mastercard:
5555555555554444|01|2026|456

Amex:
378282246310005|12|2025|1234

Discover:
6011111111111117|12|2027|123
```

---

## 🌐 API Usage

### JSON Response
```bash
curl "http://localhost:8000/hit.php?cc=CARD&address=ADDR&site=SITE&format=json"
```

### Sample Response
```json
{
  "success": true,
  "count": 1,
  "results": [
    {
      "success": false,
      "card": "4111111111111111",
      "brand": "Visa",
      "status": "DECLINED",
      "message": "Card declined",
      "gateway": "Stripe",
      "proxy_used": "socks5://proxy.com:1080",
      "response_time": 1234,
      "timestamp": "2025-11-10 15:30:45"
    }
  ],
  "address_used": "350 5th Ave\nNew York, NY 10118",
  "proxy_rotation": true,
  "execution_time_ms": 1234
}
```

---

## 🎨 Features at a Glance

✅ **Custom addresses** - Use any format you want
✅ **Bulk checking** - Multiple cards at once
✅ **Auto proxy rotation** - Like autosh.php
✅ **Gateway detection** - Stripe, PayPal, Shopify, etc.
✅ **Rate limit handling** - Auto-switches proxies
✅ **Luhn validation** - Pre-validates cards
✅ **JSON & HTML output** - For automation or manual use
✅ **Response time tracking** - Performance metrics
✅ **40+ US addresses** - Real addresses included
✅ **State filtering** - Get addresses from specific states

---

## 🔧 Parameters Reference

### Required
- `cc` - Card(s) in `number|month|year|cvv` format
- `site` - Target URL

### Optional
- `address` - Custom address (comma or pipe separated)
- `state` - State code (CA, NY, TX, etc.)
- `format` - `html` or `json` (default: html)
- `rotate` - Enable rotation: `1` (default) or `0`
- `proxy` - Use specific proxy
- `debug` - Debug mode: `0` or `1`

---

## 🛠️ Troubleshooting

### "No proxies available"
👉 Run `fetch_proxies.php` to get proxies

### "Invalid card number"
👉 Card failed Luhn validation - check the number

### "Connection failed"
👉 Verify target site is accessible

### "Rate limited"
👉 Tool auto-rotates - ensure proxies are loaded

---

## 📚 Full Documentation

- `HIT_README.md` - Complete documentation
- `HIT_EXAMPLES.txt` - 12+ usage examples
- `test_hit.php` - Test suite

---

## 🔗 Quick Links

- **Dashboard**: http://localhost:8000/
- **HIT Checker**: http://localhost:8000/hit.php
- **Fetch Proxies**: http://localhost:8000/fetch_proxies.php
- **Auto Gateway**: http://localhost:8000/autosh.php

---

## 💡 Pro Tips

1. **Use JSON format** for automation: `&format=json`
2. **Enable debug** for troubleshooting: `&debug=1`
3. **Test with known cards** before production
4. **Load proxies first** via fetch_proxies.php
5. **Use real addresses** for better gateway compatibility
6. **Bulk check efficiently** - separate cards with newlines
7. **Monitor rate limits** - tool auto-handles them!

---

## 🎓 Learning Path

1. ✅ Read this Quick Start (you're here!)
2. 📖 Try examples in browser
3. 🧪 Run `test_hit.php` to see tests
4. 📚 Read `HIT_README.md` for advanced usage
5. 🚀 Check `HIT_EXAMPLES.txt` for 12+ examples

---

## 🆚 HIT vs AUTOSH

| Feature | HIT.PHP | AUTOSH.PHP |
|---------|---------|------------|
| Custom Address | ✅ Required | ❌ Random only |
| Bulk Checking | ✅ Yes | ❌ Single |
| Address Formats | ✅ Multiple | ❌ N/A |
| Proxy Rotation | ✅ Yes | ✅ Yes |
| Gateway Detection | ✅ Basic | ✅ Advanced (50+) |
| Rate Limit Handle | ✅ Yes | ✅ Yes |
| JSON Output | ✅ Yes | ✅ Yes |
| **Use When** | Need custom addresses | Auto gateway detection |

---

## ⚠️ Important Notes

- **Legal Use Only**: For testing sites you own or have permission to test
- **Test Cards**: Use test cards (4111111111111111) for demos
- **Proxies Required**: Load proxies for rotation to work
- **Address Validation**: Some gateways validate ZIP/state matching
- **Rate Limits**: Tool handles automatically via proxy rotation

---

## 🎯 Next Steps

1. **Test it now**: http://localhost:8000/hit.php
2. **Read examples**: `HIT_EXAMPLES.txt`
3. **Run tests**: http://localhost:8000/test_hit.php
4. **Get proxies**: http://localhost:8000/fetch_proxies.php
5. **View dashboard**: http://localhost:8000/

---

**Made with ❤️ by @LEGEND_BL** 👑

---

## 📞 Need Help?

1. Check `?debug=1` for detailed errors
2. Run `test_hit.php` to verify setup
3. Review `HIT_README.md` for details
4. Check dashboard for proxy status
5. Ensure ProxyList.txt has proxies

**Ready to start? Open http://localhost:8000/hit.php now!** 🚀
