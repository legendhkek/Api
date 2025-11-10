#!/bin/bash

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

clear
echo -e "${BLUE}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     SHOPIFY API SERVER - DEPLOYMENT SCRIPT              ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""

# Find PHP executable
PHP_PATH=""

if command -v php &> /dev/null; then
    PHP_PATH="php"
    echo -e "${GREEN}[OK] Found PHP: $(which php)${NC}"
elif [ -f "/usr/bin/php" ]; then
    PHP_PATH="/usr/bin/php"
    echo -e "${GREEN}[OK] Found PHP at /usr/bin/php${NC}"
elif [ -f "/usr/local/bin/php" ]; then
    PHP_PATH="/usr/local/bin/php"
    echo -e "${GREEN}[OK] Found PHP at /usr/local/bin/php${NC}"
else
    echo -e "${RED}[ERROR] PHP not found!${NC}"
    echo "Please install PHP: sudo apt-get install php-cli"
    exit 1
fi

echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                    SERVER INFORMATION                    ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e " ${GREEN}►${NC} PHP Version:"
$PHP_PATH -v | head -n 1
echo ""
echo -e " ${GREEN}►${NC} Server Port: 8000"
echo -e " ${GREEN}►${NC} Server URL:  http://localhost:8000"
echo -e " ${GREEN}►${NC} Directory:   $(pwd)"
echo ""

echo -e "${BLUE}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                    STARTING SERVER...                    ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e " ${GREEN}✓${NC} Press Ctrl+C to stop the server"
echo -e " ${GREEN}✓${NC} Open http://localhost:8000 in your browser"
echo -e " ${GREEN}✓${NC} Main script: http://localhost:8000/autosh.php"
echo -e " ${GREEN}✓${NC} Fetch proxies: http://localhost:8000/fetch_proxies.php"
echo ""
echo "════════════════════════════════════════════════════════════"
echo ""

# Start PHP built-in server
$PHP_PATH -S localhost:8000 -t "$(pwd)" router.php
