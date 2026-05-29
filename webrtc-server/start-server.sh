#!/bin/bash

# WebRTC Server Starter for Linux/Mac
echo ""
echo "========================================"
echo " WebRTC Livestream Signaling Server"
echo "========================================"
echo ""

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "[ERROR] Node.js is not installed"
    echo "Please install Node.js from https://nodejs.org/"
    exit 1
fi

# Check if npm is installed
if ! command -v npm &> /dev/null; then
    echo "[ERROR] npm is not installed"
    exit 1
fi

# Get directory
cd "$(dirname "$0")"

echo "[*] Node.js version:"
node --version
echo "[*] npm version:"
npm --version
echo ""

# Check if node_modules exists
if [ ! -d "node_modules" ]; then
    echo "[*] Installing dependencies..."
    npm install
    if [ $? -ne 0 ]; then
        echo "[ERROR] npm install failed"
        exit 1
    fi
    echo "[OK] Dependencies installed"
else
    echo "[OK] Dependencies already installed"
fi

echo ""
echo "[*] Starting WebRTC Signaling Server..."
echo "[*] Server will run on http://localhost:3000"
echo "[*] Press Ctrl+C to stop the server"
echo ""

npm start
