@echo off
REM WebRTC Server Starter for Windows
echo.
echo ========================================
echo  WebRTC Livestream Signaling Server
echo ========================================
echo.

REM Check if Node.js is installed
where node >nul 2>nul
if errorlevel 1 (
    echo [ERROR] Node.js is not installed or not in PATH
    echo Please install Node.js from https://nodejs.org/
    pause
    exit /b 1
)

REM Check if npm is installed
where npm >nul 2>nul
if errorlevel 1 (
    echo [ERROR] npm is not installed
    pause
    exit /b 1
)

REM Get current directory
cd /d "%~dp0"

echo [*] Node.js version:
node --version
echo [*] npm version:
npm --version
echo.

REM Check if node_modules exists
if not exist "node_modules\" (
    echo [*] Installing dependencies...
    call npm install
    if errorlevel 1 (
        echo [ERROR] npm install failed
        pause
        exit /b 1
    )
    echo [OK] Dependencies installed
) else (
    echo [OK] Dependencies already installed
)

echo.
echo [*] Starting WebRTC Signaling Server...
echo [*] Server will run on http://localhost:3000
echo [*] Press Ctrl+C to stop the server
echo.

call npm start
pause
