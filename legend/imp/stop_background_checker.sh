#!/bin/bash
# Stop Background Proxy Checker Daemon

cd "$(dirname "$0")"

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║     STOPPING BACKGROUND PROXY CHECKER DAEMON                 ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

# Check if running
if ! pgrep -f "background_proxy_checker.php daemon" > /dev/null; then
    echo "ℹ️  Background checker is not running."
    exit 0
fi

PID=$(pgrep -f "background_proxy_checker.php daemon")

echo "Found daemon with PID: $PID"
echo "Stopping..."

pkill -f "background_proxy_checker.php daemon"

sleep 2

if pgrep -f "background_proxy_checker.php daemon" > /dev/null; then
    echo "⚠️  Process still running, forcing kill..."
    pkill -9 -f "background_proxy_checker.php daemon"
    sleep 1
fi

if ! pgrep -f "background_proxy_checker.php daemon" > /dev/null; then
    echo "✅ Background checker stopped successfully!"
else
    echo "❌ Failed to stop background checker"
    exit 1
fi
