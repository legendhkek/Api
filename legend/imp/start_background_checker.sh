#!/bin/bash
# Start Background Proxy Checker Daemon
# This script starts the background proxy checker in daemon mode

cd "$(dirname "$0")"

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║     STARTING BACKGROUND PROXY CHECKER DAEMON                 ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

# Check if already running
if pgrep -f "background_proxy_checker.php daemon" > /dev/null; then
    echo "⚠️  Background checker is already running!"
    echo "   PID: $(pgrep -f 'background_proxy_checker.php daemon')"
    echo ""
    echo "To stop it, run:"
    echo "   pkill -f 'background_proxy_checker.php daemon'"
    exit 1
fi

# Default check interval (30 minutes = 1800 seconds)
INTERVAL=${1:-1800}

echo "📋 Configuration:"
echo "   Check Interval: $INTERVAL seconds ($(($INTERVAL / 60)) minutes)"
echo "   Log File: background_checker.log"
echo "   Proxy File: ProxyList.txt"
echo ""

# Check for Telegram credentials
if [ -z "$TELEGRAM_BOT_TOKEN" ] && [ -z "$TELEGRAM_CHAT_ID" ]; then
    echo "⚠️  WARNING: Telegram credentials not set!"
    echo "   Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID environment variables"
    echo "   or they will be disabled."
    echo ""
fi

echo "🚀 Starting daemon..."
echo ""

# Start in background with nohup
nohup php background_proxy_checker.php daemon $INTERVAL >> background_checker.log 2>&1 &

DAEMON_PID=$!

echo "✅ Daemon started successfully!"
echo "   PID: $DAEMON_PID"
echo ""
echo "📝 To view logs in real-time:"
echo "   tail -f background_checker.log"
echo ""
echo "🛑 To stop the daemon:"
echo "   kill $DAEMON_PID"
echo "   or"
echo "   pkill -f 'background_proxy_checker.php daemon'"
echo ""
