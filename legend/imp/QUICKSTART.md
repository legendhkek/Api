# 🚀 Quick Start Guide - Background Proxy Checker

## 30-Second Setup

### 1️⃣ Get Telegram Credentials

**Bot Token:**
1. Open Telegram and search for `@BotFather`
2. Send `/newbot` and follow instructions
3. Copy your bot token (looks like: `123456789:ABCdefGHIjklMNO...`)

**Chat ID:**
1. Send any message to your bot
2. Visit: `https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates`
3. Find `"chat":{"id":123456789}` - that's your chat ID

### 2️⃣ Configure

```bash
export TELEGRAM_BOT_TOKEN="your_bot_token_here"
export TELEGRAM_CHAT_ID="your_chat_id_here"
```

### 3️⃣ Run Setup

```bash
./setup_background_checker.sh
```

That's it! ✅

---

## Manual Setup (If Preferred)

### Step 1: Configure Telegram

```bash
# Add to ~/.bashrc or ~/.bash_profile for persistence
export TELEGRAM_BOT_TOKEN="123456789:ABCdefGHIjklMNOpqrsTUVwxyz"
export TELEGRAM_CHAT_ID="123456789"

# Reload
source ~/.bashrc
```

### Step 2: Verify Setup

```bash
php test_background_checker.php
```

This will:
- ✅ Check all dependencies
- ✅ Send test message to Telegram
- ✅ Verify proxy list
- ✅ Test permissions

### Step 3: Add Proxies (if needed)

```bash
php fetch_proxies.php
```

### Step 4: Run First Check

```bash
php background_proxy_checker.php check
```

You should see:
- Console output with progress
- Telegram message with results
- Working proxy list in Telegram

### Step 5: Start Daemon Mode

```bash
./start_background_checker.sh
```

Or with custom interval:

```bash
./start_background_checker.sh 600  # Check every 10 minutes
```

---

## Common Commands

```bash
# Single check
php background_proxy_checker.php check

# Start daemon (30 min interval)
./start_background_checker.sh

# Start daemon with custom interval
./start_background_checker.sh 600  # 10 minutes

# Check status
php background_proxy_checker.php status

# Stop daemon
./stop_background_checker.sh

# View logs
tail -f background_checker.log

# Run tests
php test_background_checker.php
```

---

## Web API

Access via browser or curl:

```bash
# Status
curl http://your-server/legend/imp/background_proxy_checker.php?action=status

# Run check
curl http://your-server/legend/imp/background_proxy_checker.php?action=check

# Force check (ignore interval)
curl http://your-server/legend/imp/background_proxy_checker.php?action=check&force=1
```

---

## Automatic Scheduling

### Linux Cron

```bash
crontab -e
```

Add:
```
# Check every 30 minutes
*/30 * * * * cd /path/to/legend/imp && php background_proxy_checker.php check

# Or keep daemon alive
*/5 * * * * pgrep -f "background_proxy_checker.php daemon" || cd /path/to/legend/imp && ./start_background_checker.sh
```

### Windows Task Scheduler

1. Open Task Scheduler
2. Create Basic Task
3. **Program**: `C:\path\to\php.exe`
4. **Arguments**: `C:\path\to\legend\imp\background_proxy_checker.php check`
5. **Trigger**: Every 30 minutes

---

## What You'll Get in Telegram

### Every Check:

**1. Main Report:**
```
✅ Background Proxy Check Report

📊 Statistics:
• Total Proxies: 50
• Working: 38
• Dead: 12
• Success Rate: 76.0%
• Avg Response: 0.450s

🔧 By Protocol:
• HTTP: 20
• SOCKS5: 15
• HTTPS: 3

🌍 Top Countries:
• United States: 15
• Germany: 8
• France: 6

⏰ 2025-11-10 14:30:45
```

**2. Working Proxy List:**
```
📋 Working Proxy List (38):

http://1.2.3.4:8080
socks5://5.6.7.8:1080
http://10.20.30.40:3128
...
(all working proxies)
```

---

## Troubleshooting

### No Telegram Messages?

1. Check credentials:
   ```bash
   echo $TELEGRAM_BOT_TOKEN
   echo $TELEGRAM_CHAT_ID
   ```

2. Test manually:
   ```bash
   php test_background_checker.php
   ```

3. Check bot has permission to send messages

### No Proxies?

```bash
# Fetch new proxies
php fetch_proxies.php

# Check file
cat ProxyList.txt
```

### Daemon Won't Start?

```bash
# Check if already running
pgrep -f "background_proxy_checker.php daemon"

# Force stop
pkill -9 -f "background_proxy_checker.php daemon"

# Try again
./start_background_checker.sh
```

### All Proxies Dead?

- Proxies may have expired
- Fetch fresh ones: `php fetch_proxies.php`
- Check network connectivity

---

## Configuration Options

Edit `background_proxy_checker.php` around line 38:

```php
$checker = new BackgroundProxyChecker([
    'proxyFile' => 'ProxyList.txt',
    'checkInterval' => 1800,              // 30 minutes in seconds
    'useProxyForChecks' => true,          // Route checks through proxies
    'telegram_token' => '',               // Leave empty to use env vars
    'telegram_chat_id' => ''              // Leave empty to use env vars
]);
```

---

## Advanced Usage

### Custom Check Intervals

```bash
# Every 5 minutes (300 seconds)
php background_proxy_checker.php daemon 300

# Every 1 hour (3600 seconds)
php background_proxy_checker.php daemon 3600

# Every 2 hours (7200 seconds)
php background_proxy_checker.php daemon 7200
```

### Using with Systemd (Linux Service)

Create `/etc/systemd/system/proxy-checker.service`:

```ini
[Unit]
Description=Background Proxy Checker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/legend/imp
Environment="TELEGRAM_BOT_TOKEN=your_token"
Environment="TELEGRAM_CHAT_ID=your_chat_id"
ExecStart=/usr/bin/php background_proxy_checker.php daemon 1800
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable:
```bash
sudo systemctl enable proxy-checker
sudo systemctl start proxy-checker
sudo systemctl status proxy-checker
```

---

## Files Overview

| File | Purpose |
|------|---------|
| `background_proxy_checker.php` | Main checker system |
| `start_background_checker.sh` | Start daemon easily |
| `stop_background_checker.sh` | Stop daemon |
| `setup_background_checker.sh` | Interactive setup wizard |
| `test_background_checker.php` | Test suite |
| `BACKGROUND_CHECKER_README.md` | Full documentation |
| `QUICKSTART.md` | This file |
| `.env.example` | Configuration template |

---

## Next Steps

After setup:

1. ✅ Watch logs: `tail -f background_checker.log`
2. ✅ Check Telegram for messages
3. ✅ Verify ProxyList.txt is updated
4. ✅ Monitor system status
5. ✅ Schedule automatic checks (cron/systemd)

---

## Need Help?

- 📖 Full docs: `BACKGROUND_CHECKER_README.md`
- 🧪 Run tests: `php test_background_checker.php`
- 📝 Check logs: `tail -f background_checker.log`
- 💬 Telegram bot not responding? Verify token and chat ID

---

## That's It! 🎉

You now have a fully automated proxy checking system that:
- ✅ Tests proxies continuously
- ✅ Sends reports to Telegram
- ✅ Sends working proxy list to your bot
- ✅ Cleans dead proxies automatically
- ✅ Runs in background
- ✅ Logs everything

**Happy proxy monitoring!** 🚀
