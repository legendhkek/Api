#!/bin/bash
# Quick Setup Script for Background Proxy Checker

cd "$(dirname "$0")"

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║     BACKGROUND PROXY CHECKER - QUICK SETUP                   ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

# Check if running as root
if [ "$EUID" -eq 0 ]; then 
    echo "⚠️  Please don't run this script as root"
    exit 1
fi

# Step 1: Check PHP
echo "📋 Step 1: Checking PHP installation..."
if ! command -v php &> /dev/null; then
    echo "❌ PHP is not installed"
    echo "   Install PHP 7.4+ with: sudo apt install php php-curl php-mbstring"
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_VERSION;")
echo "   ✅ PHP $PHP_VERSION found"
echo ""

# Step 2: Check PHP extensions
echo "📋 Step 2: Checking PHP extensions..."
MISSING_EXTS=""

for ext in curl json mbstring; do
    if php -m | grep -q "^$ext$"; then
        echo "   ✅ $ext extension loaded"
    else
        echo "   ❌ $ext extension NOT loaded"
        MISSING_EXTS="$MISSING_EXTS php-$ext"
    fi
done

if [ -n "$MISSING_EXTS" ]; then
    echo ""
    echo "⚠️  Missing extensions. Install with:"
    echo "   sudo apt install$MISSING_EXTS"
    echo ""
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi
echo ""

# Step 3: Configure Telegram
echo "📋 Step 3: Configuring Telegram..."
echo ""

if [ -z "$TELEGRAM_BOT_TOKEN" ]; then
    echo "🤖 Telegram Bot Token is not set"
    echo "   Get your bot token from @BotFather on Telegram"
    echo ""
    read -p "   Enter bot token (or press Enter to skip): " BOT_TOKEN
    if [ -n "$BOT_TOKEN" ]; then
        export TELEGRAM_BOT_TOKEN="$BOT_TOKEN"
        echo "   ✅ Bot token set temporarily"
        echo "   To make permanent, add to ~/.bashrc:"
        echo "   export TELEGRAM_BOT_TOKEN='$BOT_TOKEN'"
    else
        echo "   ⏭️  Skipping Telegram configuration"
    fi
else
    echo "   ✅ Bot token already set: ${TELEGRAM_BOT_TOKEN:0:20}..."
fi
echo ""

if [ -z "$TELEGRAM_CHAT_ID" ]; then
    echo "💬 Telegram Chat ID is not set"
    echo "   Get your chat ID by sending a message to your bot, then visit:"
    echo "   https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates"
    echo ""
    read -p "   Enter chat ID (or press Enter to skip): " CHAT_ID
    if [ -n "$CHAT_ID" ]; then
        export TELEGRAM_CHAT_ID="$CHAT_ID"
        echo "   ✅ Chat ID set temporarily"
        echo "   To make permanent, add to ~/.bashrc:"
        echo "   export TELEGRAM_CHAT_ID='$CHAT_ID'"
    else
        echo "   ⏭️  Skipping chat ID configuration"
    fi
else
    echo "   ✅ Chat ID already set: $TELEGRAM_CHAT_ID"
fi
echo ""

# Step 4: Check/Create ProxyList.txt
echo "📋 Step 4: Checking proxy list..."
if [ ! -f "ProxyList.txt" ]; then
    echo "   ⚠️  ProxyList.txt not found"
    touch ProxyList.txt
    echo "   ✅ Created empty ProxyList.txt"
    echo ""
    
    read -p "   Would you like to fetch proxies now? (Y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]] || [[ -z $REPLY ]]; then
        if [ -f "fetch_proxies.php" ]; then
            echo "   🔄 Fetching proxies..."
            php fetch_proxies.php
            echo ""
        else
            echo "   ⚠️  fetch_proxies.php not found"
        fi
    fi
else
    PROXY_COUNT=$(grep -v "^#" ProxyList.txt | grep -v "^$" | wc -l)
    echo "   ✅ ProxyList.txt exists with $PROXY_COUNT proxies"
fi
echo ""

# Step 5: Set permissions
echo "📋 Step 5: Setting file permissions..."
chmod +x start_background_checker.sh stop_background_checker.sh
chmod 666 ProxyList.txt 2>/dev/null || true
chmod 666 background_checker.log 2>/dev/null || true
touch last_background_check.txt
chmod 666 last_background_check.txt
echo "   ✅ Permissions set"
echo ""

# Step 6: Run tests
echo "📋 Step 6: Running system tests..."
echo ""
php test_background_checker.php
echo ""

# Step 7: Ask to start
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║                       SETUP COMPLETE!                        ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

read -p "Would you like to start the background checker now? (Y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]] || [[ -z $REPLY ]]; then
    echo ""
    echo "Choose mode:"
    echo "  1) Run single check (recommended first time)"
    echo "  2) Start daemon mode (continuous monitoring)"
    echo ""
    read -p "Enter choice (1 or 2): " -n 1 -r
    echo ""
    echo ""
    
    case $REPLY in
        1)
            echo "🔄 Running single check..."
            echo ""
            php background_proxy_checker.php check
            ;;
        2)
            echo "🚀 Starting daemon mode..."
            echo ""
            ./start_background_checker.sh
            ;;
        *)
            echo "❌ Invalid choice"
            ;;
    esac
else
    echo ""
    echo "ℹ️  To start later, run:"
    echo "   php background_proxy_checker.php check    # Single check"
    echo "   ./start_background_checker.sh             # Daemon mode"
fi

echo ""
echo "📖 For more information, see BACKGROUND_CHECKER_README.md"
echo ""
