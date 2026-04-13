#!/bin/bash
# SelfRecover demo launcher
# Usage: ./run.sh  → starts PHP built-in server on localhost:8080

set -e
cd "$(dirname "$0")"

# Check requirements
if ! command -v php >/dev/null 2>&1; then
    echo "Error: PHP CLI not found. Install PHP 8.0+ first."
    echo "  Debian/Ubuntu: sudo apt install php-cli php-sqlite3"
    echo "  macOS:         brew install php"
    exit 1
fi

# Check SQLite driver
if ! php -m | grep -qi "^sqlite3$"; then
    echo "Error: PHP SQLite driver not installed."
    echo "  Debian/Ubuntu: sudo apt install php-sqlite3"
    echo "  macOS:         already bundled with brew php"
    echo "  Other:         enable pdo_sqlite + sqlite3 in php.ini"
    exit 1
fi

# Fresh DB (delete any previous SQLite file)
rm -f selfrecover.sqlite

# Initialize SQLite schema if sqlite3 is available, otherwise it will be auto-initialized on first API call
if command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 selfrecover.sqlite < schema.sql
fi

echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║   SelfRecover demo running at http://localhost:8080  ║"
echo "║                                                      ║"
echo "║   Press Ctrl+C to stop                               ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""

# Try to open browser
(xdg-open http://localhost:8080 2>/dev/null || open http://localhost:8080 2>/dev/null || start http://localhost:8080 2>/dev/null) &

# Start PHP built-in server with a router that maps /api/ to api/index.php
php -S localhost:8080 -t . router.php 2>&1 || php -S localhost:8080 -t .
