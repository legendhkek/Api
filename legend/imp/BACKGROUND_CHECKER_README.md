# Background Proxy Checker with Telegram Notifications

A comprehensive background proxy health checking system that monitors proxies, removes dead ones, and sends detailed reports to Telegram.

## Features

✅ **Background Health Checks** - Automatically checks proxy health in the background
✅ **Proxy-based Checking** - Can route checks through working proxies
✅ **Telegram Notifications** - Sends detailed reports to your Telegram bot
✅ **Automatic Cleanup** - Removes dead proxies from ProxyList.txt
✅ **Parallel Testing** - Tests multiple proxies simultaneously for speed
✅ **Geographic Info** - Shows proxy locations and ISP information
✅ **Protocol Support** - HTTP, HTTPS, SOCKS4, SOCKS5
✅ **Daemon Mode** - Runs continuously with configurable intervals
✅ **Detailed Logging** - Comprehensive logs for debugging

## Quick Start

### 1. Configure Telegram (Required for Notifications)

Set environment variables with your Telegram bot credentials:

```bash
export TELEGRAM_BOT_TOKEN="your_bot_token_here"
export TELEGRAM_CHAT_ID="your_chat_id_here"
```

**How to get Telegram credentials:**

1. **Bot Token**: 
   - Talk to [@BotFather](https://t.me/botfather) on Telegram
   - Send `/newbot` and follow instructions
   - Copy the bot token

2. **Chat ID**:
   - Send a message to your bot
   - Visit: `https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates`
   - Find your `chat_id` in the response

### 2. Run Single Check

```bash
php background_proxy_checker.php check
```

This will:
- Test all proxies in ProxyList.txt
- Send results to Telegram
- Update ProxyList.txt with only working proxies

### 3. Start Daemon Mode

```bash
# Using the shell script (recommended)
./start_background_checker.sh

# Or directly with PHP (30 minute interval)
php background_proxy_checker.php daemon 1800

# Custom interval (10 minutes = 600 seconds)
php background_proxy_checker.php daemon 600
```

### 4. Stop Daemon

```bash
./stop_background_checker.sh

# Or manually
pkill -f "background_proxy_checker.php daemon"
```

## Usage Examples

### Check Status

```bash
php background_proxy_checker.php status
```

Shows:
- Last check time
- Proxy count
- Telegram status
- Check interval

### Web API

Access via HTTP:

```bash
# Get status
curl http://localhost/legend/imp/background_proxy_checker.php?action=status

# Run check
curl http://localhost/legend/imp/background_proxy_checker.php?action=check

# Force check
curl http://localhost/legend/imp/background_proxy_checker.php?action=check&force=1
```

## Configuration

Edit `background_proxy_checker.php` to customize:

```php
$checker = new BackgroundProxyChecker([
    'proxyFile' => 'ProxyList.txt',           // Proxy list file
    'checkInterval' => 1800,                  // 30 minutes
    'useProxyForChecks' => true,              // Route checks through proxies
    'telegram_token' => 'your_token',         // Or use env var
    'telegram_chat_id' => 'your_chat_id'      // Or use env var
]);
```

## Telegram Notification Format

The bot sends rich, formatted messages:

```
✅ Background Proxy Check Report

📊 Statistics:
• Total Proxies: 50
• Working: 38
• Dead: 12
• Success Rate: 76.0%
• Avg Response: 0.450s
• Check Duration: 15.3s

🔧 By Protocol:
• HTTP: 20
• SOCKS5: 15
• HTTPS: 3

🌍 Top Countries:
• United States: 15
• Germany: 8
• France: 6
• Japan: 5
• Canada: 4

⏰ 2025-11-10 14:30:45
```

Plus a list of working proxies (if ≤50):

```
📋 Working Proxy List (38):

http://1.2.3.4:8080
socks5://5.6.7.8:1080
...
```

## Integration with Existing System

The background checker integrates with:

1. **proxy_cache_refresh.php** - Now sends Telegram notifications
2. **ProxyManager.php** - Uses existing proxy rotation
3. **TelegramNotifier.php** - Sends formatted notifications
4. **ProxyAnalytics.php** - Tracks proxy performance

## Automatic Scheduling

### Linux Cron

Add to crontab (`crontab -e`):

```bash
# Run check every 30 minutes
*/30 * * * * cd /path/to/legend/imp && php background_proxy_checker.php check

# Or keep daemon running (check every 5 minutes, restart if dead)
*/5 * * * * pgrep -f "background_proxy_checker.php daemon" || cd /path/to/legend/imp && ./start_background_checker.sh
```

### Windows Task Scheduler

Create a scheduled task:
- **Program**: `php.exe`
- **Arguments**: `C:\path\to\legend\imp\background_proxy_checker.php check`
- **Trigger**: Every 30 minutes

### Systemd Service (Linux)

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
ExecStart=/usr/bin/php /var/www/legend/imp/background_proxy_checker.php daemon 1800
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl enable proxy-checker
sudo systemctl start proxy-checker
sudo systemctl status proxy-checker
```

## Logs

- **background_checker.log** - Main application log
- **proxy_rotation.log** - Proxy manager log
- All logs include timestamps and severity levels

View logs in real-time:

```bash
tail -f background_checker.log
```

## Troubleshooting

### No Telegram Notifications

1. Check credentials:
   ```bash
   echo $TELEGRAM_BOT_TOKEN
   echo $TELEGRAM_CHAT_ID
   ```

2. Test manually:
   ```php
   require_once 'TelegramNotifier.php';
   $telegram = new TelegramNotifier('YOUR_TOKEN', 'YOUR_CHAT_ID');
   $telegram->testConnection();
   ```

### No Proxies Found

1. Check ProxyList.txt exists and has proxies
2. Run fetch_proxies.php to get fresh proxies:
   ```bash
   php fetch_proxies.php
   ```

### All Proxies Dead

1. Proxies may have expired - fetch new ones
2. Check network connectivity
3. Try lowering timeout in code

### Daemon Not Starting

1. Check if already running:
   ```bash
   pgrep -f "background_proxy_checker.php daemon"
   ```

2. Check logs for errors:
   ```bash
   tail -n 50 background_checker.log
   ```

## Advanced Features

### Use Proxies for Checking

The system can route health checks through working proxies:

```php
'useProxyForChecks' => true  // Route through proxies (more anonymous)
'useProxyForChecks' => false // Direct connection (faster)
```

### Custom Check Intervals

```bash
# Every 10 minutes
php background_proxy_checker.php daemon 600

# Every hour
php background_proxy_checker.php daemon 3600

# Every 5 minutes
php background_proxy_checker.php daemon 300
```

### Batch Size Control

Edit `$maxConcurrentChecks` in the class:

```php
private $maxConcurrentChecks = 50; // Test 50 proxies at once
```

Higher = faster but more resources
Lower = slower but more stable

## Performance

- **50 proxies**: ~10-15 seconds
- **100 proxies**: ~20-30 seconds
- **500 proxies**: ~2-3 minutes

Parallel processing ensures fast checks even with large proxy lists.

## Security

- Proxy credentials are never logged
- Telegram tokens should use environment variables
- All HTTPS connections verify SSL by default
- Dead proxy list is cleaned automatically

## API Reference

### BackgroundProxyChecker Class

```php
// Create instance
$checker = new BackgroundProxyChecker($config);

// Run check
$result = $checker->runBackgroundCheck($force = false);

// Check if needs check
$needs = $checker->needsCheck();

// Get status
$status = $checker->getStatus();

// Start daemon
$checker->startDaemon($interval);
```

## Support

For issues or questions:
1. Check logs first
2. Verify Telegram credentials
3. Test proxy list manually
4. Review configuration

## License

Part of the Legend Proxy Management System
