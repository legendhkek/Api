# 💳 HIT.PHP - Complete Customer Details Guide

## ✅ All 11 Required Fields Collected

The updated `hit.php` now collects and uses **all 11 required customer details** for every check:

### 📋 Complete Field List

1. **Currency** (e.g., USD, EUR, GBP)
2. **Country** (e.g., US, CA, GB)
3. **Street Address** (e.g., 350 5th Ave)
4. **City** (e.g., New York)
5. **State/Province** (e.g., NY)
6. **Postal Code** (e.g., 10118)
7. **First Name** (e.g., John)
8. **Last Name** (e.g., Smith)
9. **Email Address** (e.g., john.smith@gmail.com)
10. **Phone Number** (e.g., +12125551234)
11. **Cardholder Name** (e.g., JOHN SMITH)

---

## 🎯 How It Works

### Option 1: Manual Entry (Full Control)
Fill in all fields in the web form:
- Open http://localhost:8000/hit.php
- Enter credit card(s)
- Fill in all customer details
- Specify target site
- Click "Start Checking"

### Option 2: Auto-Generation (Quick Testing)
Leave fields empty for automatic realistic data:
- Only enter credit card(s) and site URL
- All customer details auto-generated
- Uses real US addresses
- Random but realistic names, emails, phones

### Option 3: API/Programmatic (JSON)
Send via GET/POST with all parameters:

```bash
curl -X POST "http://localhost:8000/hit.php?format=json" \
  -d "cc=4111111111111111|12|2027|123" \
  -d "first_name=John" \
  -d "last_name=Smith" \
  -d "email=john.smith@gmail.com" \
  -d "phone=+12125551234" \
  -d "cardholder_name=JOHN SMITH" \
  -d "street_address=350 5th Ave" \
  -d "city=New York" \
  -d "state=NY" \
  -d "postal_code=10118" \
  -d "country=US" \
  -d "currency=USD" \
  -d "site=https://shop.com"
```

---

## 🤖 Auto-Generation Features

### Name Generator
- 50+ realistic first names (James, Mary, John, etc.)
- 50+ common last names (Smith, Johnson, Williams, etc.)
- Gender-neutral selection

### Email Generator
- Based on first + last name
- Multiple formats: firstname.lastname, firstnamelastname, etc.
- 7 popular domains: gmail.com, yahoo.com, outlook.com, etc.

### Phone Generator
- US format: +1 (XXX) XXX-XXXX
- Real US area codes (212, 310, 415, etc.)
- 17 major city area codes included

### Address Generator
- 40+ real US addresses
- Covers 30+ states
- Real street names, cities, ZIP codes
- Automatically fills all address fields

---

## 📤 Response Format

### JSON Response with All Details
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
      "customer": {
        "name": "John Smith",
        "email": "john.smith@gmail.com",
        "phone": "+12125551234",
        "address": "350 5th Ave, New York, NY 10118",
        "country": "US",
        "currency": "USD"
      },
      "proxy_used": "socks5://proxy.com:1080",
      "response_time": 1234,
      "timestamp": "2025-11-10 15:30:45"
    }
  ],
  "customer_details": {
    "first_name": "John",
    "last_name": "Smith",
    "email": "john.smith@gmail.com",
    "phone": "+12125551234",
    "cardholder_name": "JOHN SMITH",
    "street_address": "350 5th Ave",
    "city": "New York",
    "state": "NY",
    "postal_code": "10118",
    "country": "US",
    "currency": "USD"
  },
  "proxy_rotation": true,
  "execution_time_ms": 1234
}
```

---

## 🌍 Supported Countries & Currencies

### Countries
- 🇺🇸 US - United States
- 🇨🇦 CA - Canada
- 🇬🇧 GB - United Kingdom
- 🇦🇺 AU - Australia
- 🇫🇷 FR - France
- 🇩🇪 DE - Germany
- 🇮🇳 IN - India
- 🇧🇷 BR - Brazil

### Currencies
- USD - US Dollar
- EUR - Euro
- GBP - British Pound
- CAD - Canadian Dollar
- AUD - Australian Dollar
- INR - Indian Rupee
- BRL - Brazilian Real

---

## 📝 Form Fields Reference

### Card Information Section
| Field | Required | Format | Example |
|-------|----------|--------|---------|
| Credit Card(s) | ✅ Yes | number\|month\|year\|cvv | 4111111111111111\|12\|2027\|123 |

### Customer Information Section
| Field | Auto-Gen | Format | Example |
|-------|----------|--------|---------|
| First Name | ✅ Yes | Text | John |
| Last Name | ✅ Yes | Text | Smith |
| Email Address | ✅ Yes | email@domain.com | john.smith@gmail.com |
| Phone Number | ✅ Yes | +1XXXXXXXXXX | +12125551234 |
| Cardholder Name | ✅ Yes | UPPERCASE | JOHN SMITH |

### Address Information Section
| Field | Auto-Gen | Format | Example |
|-------|----------|--------|---------|
| Street Address | ✅ Yes | Text | 350 5th Ave |
| City | ✅ Yes | Text | New York |
| State/Province | ✅ Yes | 2-letter code | NY |
| Postal Code | ✅ Yes | ZIP/Postal | 10118 |
| Country | 🔧 Default US | 2-letter code | US |
| Currency | 🔧 Default USD | 3-letter code | USD |

### Target & Options Section
| Field | Required | Format | Example |
|-------|----------|--------|---------|
| Target Site | ✅ Yes | URL | https://shop.com |
| Proxy Rotation | 🔧 Default On | 0 or 1 | 1 |
| Output Format | 🔧 Default HTML | html or json | json |

---

## 🎨 Usage Examples

### Example 1: Complete Manual Entry
```bash
curl -X POST "http://localhost:8000/hit.php?format=json" \
  -d "cc=4111111111111111|12|2027|123" \
  -d "first_name=John" \
  -d "last_name=Smith" \
  -d "email=john.smith@gmail.com" \
  -d "phone=+12125551234" \
  -d "cardholder_name=JOHN SMITH" \
  -d "street_address=350 5th Ave" \
  -d "city=New York" \
  -d "state=NY" \
  -d "postal_code=10118" \
  -d "country=US" \
  -d "currency=USD" \
  -d "site=https://shop.com"
```

### Example 2: Auto-Generated (Minimal Input)
```bash
# Only card and site - all customer details auto-generated
curl "http://localhost:8000/hit.php?cc=4111111111111111|12|2027|123&site=https://shop.com&format=json"
```

### Example 3: Partial Manual + Auto
```bash
# Specify name, auto-generate rest
curl -X POST "http://localhost:8000/hit.php?format=json" \
  -d "cc=4111111111111111|12|2027|123" \
  -d "first_name=John" \
  -d "last_name=Smith" \
  -d "site=https://shop.com"
  
# Email, phone, address auto-generated
```

### Example 4: Different Country/Currency
```bash
curl -X POST "http://localhost:8000/hit.php?format=json" \
  -d "cc=4111111111111111|12|2027|123" \
  -d "country=GB" \
  -d "currency=GBP" \
  -d "site=https://shop.co.uk"
  
# Customer details auto-generated for UK
```

### Example 5: Bulk Check with Same Customer
```bash
curl -X POST "http://localhost:8000/hit.php?format=json" \
  -d "cc=4111111111111111|12|2027|123
5555555555554444|01|2026|456
378282246310005|12|2025|1234" \
  -d "first_name=John" \
  -d "last_name=Smith" \
  -d "site=https://shop.com"
  
# All cards checked with same customer details
```

---

## 🔄 Data Flow

1. **Input Collection**: Form/API receives all 11 fields
2. **Auto-Generation**: Empty fields filled with realistic data
3. **Validation**: Card Luhn check, email format, etc.
4. **Proxy Selection**: Automatic rotation if enabled
5. **Request Building**: All customer details included
6. **Gateway Check**: HTTP request to target site
7. **Response Analysis**: Detect LIVE/DECLINED/ERROR
8. **Result Return**: JSON/HTML with all details

---

## 🛡️ Security & Privacy

### Auto-Generated Data
- ✅ Completely random
- ✅ No real person data
- ✅ Realistic formats
- ✅ Test-safe

### Manual Entry
- ⚠️ Use test data only
- ⚠️ Never use real customer info
- ⚠️ For testing purposes only

---

## 💡 Best Practices

### 1. Let It Auto-Generate
For quick testing, don't fill anything except card + site:
```bash
?cc=CARD&site=URL
```
Everything else generates automatically!

### 2. Use Consistent Data for Testing
Specify customer once, check multiple cards:
```
POST with same customer details
Multiple cards in cc field
```

### 3. Country/Currency Matching
Match currency to country:
- US → USD
- GB → GBP
- EU → EUR
- CA → CAD

### 4. Realistic Phone Formats
US: +1XXXXXXXXXX
UK: +44XXXXXXXXXX
Others: +[country code][number]

### 5. Email Domain Variety
Use popular domains for realism:
- gmail.com (most common)
- yahoo.com
- outlook.com
- hotmail.com

---

## 📊 Field Priority

### Always Required
1. Credit Card(s)
2. Target Site

### Auto-Generated if Empty (in order)
1. First Name → random
2. Last Name → random
3. Email → from name
4. Phone → random US
5. Cardholder Name → from first+last
6. Address → random US address
7. City → from address
8. State → from address
9. Postal Code → from address
10. Country → default US
11. Currency → default USD

---

## 🧪 Testing Workflow

### Quick Test (Auto Mode)
```bash
# 1. Just card + site
curl "http://localhost:8000/hit.php?cc=4111111111111111|12|2027|123&site=https://shop.com&format=json"

# 2. View auto-generated data in response
# 3. All 11 fields populated automatically
```

### Full Test (Manual Mode)
```bash
# 1. Fill all 11 fields
# 2. Check response matches input
# 3. Verify gateway received all data
```

### Bulk Test
```bash
# 1. Multiple cards, one customer
# 2. Each card checked with same details
# 3. Fast bulk processing
```

---

## 🎯 Summary

✅ **All 11 fields collected**: Currency, Country, Street, City, State, Postal, First Name, Last Name, Email, Phone, Cardholder Name

✅ **Auto-generation**: Leave empty for realistic random data

✅ **Flexible input**: Form, GET, POST, JSON

✅ **Complete output**: All details in results

✅ **Proxy rotation**: Automatic like autosh.php

✅ **Gateway detection**: Auto-detect payment gateways

✅ **Bulk support**: Multiple cards, same customer

---

## 🚀 Get Started

1. **Open hit.php**: http://localhost:8000/hit.php
2. **Enter card + site**: That's all! (or fill all 11 fields)
3. **Click "Start Checking"**: See complete results
4. **View customer details**: All 11 fields shown in results

**That's it! Hit.php now collects and uses all 11 required customer details!** 🎉

---

**Powered by @LEGEND_BL** 👑
