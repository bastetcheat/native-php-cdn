@echo off
setlocal EnableDelayedExpansion
title CDN Sync – Pull from GitHub

:: ─────────────────────────────────────────────────────────────────────────────
::  sync.bat  –  Keep this folder in sync with GitHub (hard reset strategy).
::
::  Safe:  db/ and uploads/ are in .gitignore and are NEVER touched.
::  Usage: sync.bat          → interactive
::         sync.bat --auto  → no prompts (Task Scheduler)
:: ─────────────────────────────────────────────────────────────────────────────

set "REPO=https://github.com/bastetcheat/native-php-cdn.git"
set "BRANCH=master"

cd /d "%~dp0"

echo.
echo   =========================================
echo    CDN Panel - GitHub Sync Tool
echo   =========================================
echo.
echo   Remote : %REPO%
echo   Local  : %~dp0
echo.

:: ── git available? ────────────────────────────────────────────────────────────
where git >nul 2>&1
if errorlevel 1 (
    echo [ERROR] git not found. Install from: https://git-scm.com/download/win
    pause & exit /b 1
)

:: ── No .git at all → clone fresh ─────────────────────────────────────────────
if not exist ".git" (
    echo   [INIT] No git repo found – cloning fresh copy...
    git clone --depth=1 --branch %BRANCH% "%REPO%" "_tmp_clone_"
    if errorlevel 1 (
        echo   [ERROR] Clone failed.
        pause & exit /b 1
    )
    xcopy "_tmp_clone_\*" "." /E /Y /I >nul 2>&1
    rmdir /s /q "_tmp_clone_" >nul 2>&1
    echo   [OK] Clone complete.
    goto :done
)

:: ── Ensure origin remote exists with correct URL ──────────────────────────────
git remote get-url origin >nul 2>&1
if errorlevel 1 (
    echo   [FIX] Adding missing origin remote...
    git remote add origin "%REPO%"
) else (
    git remote set-url origin "%REPO%"
)

:: ── Fetch latest from GitHub ──────────────────────────────────────────────────
echo   Fetching from GitHub...
git fetch origin %BRANCH% 2>"%TEMP%\cdn_sync_err.txt"
if errorlevel 1 (
    echo.
    echo   [ERROR] Fetch failed:
    type "%TEMP%\cdn_sync_err.txt"
    echo.
    findstr /i "authentication\|access\|403\|401\|credential\|permission\|denied" "%TEMP%\cdn_sync_err.txt" >nul 2>&1
    if not errorlevel 1 (
        echo   [AUTH] Opening GitHub login in your browser...
        start "" "https://github.com/login"
        echo   Sign in, then run sync.bat again.
    )
    echo.
    if /i not "%~1"=="--auto" pause
    exit /b 1
)

:: ── Compare local HEAD vs remote ─────────────────────────────────────────────
set "LOCAL_SHA="
set "REMOTE_SHA="
for /f %%A in ('git rev-parse HEAD 2^>nul')                  do set "LOCAL_SHA=%%A"
for /f %%A in ('git rev-parse origin/%BRANCH% 2^>nul')       do set "REMOTE_SHA=%%A"

:: Show what we have
set "LAST_COMMIT=not yet synced"
if not "!LOCAL_SHA!"=="" (
    for /f "delims=" %%H in ('git log -1 --format^="%%h - %%s" 2^>nul') do set "LAST_COMMIT=%%H"
)
echo   Branch : %BRANCH%
echo   HEAD   : !LAST_COMMIT!
echo.

if "!LOCAL_SHA!"=="!REMOTE_SHA!" (
    echo   [UP TO DATE] Already on the latest commit.
    echo.
    goto :done
)

:: Count new commits
set "BEHIND=0"
if not "!LOCAL_SHA!"=="" (
    for /f %%C in ('git rev-list !LOCAL_SHA!..origin/%BRANCH% --count 2^>nul') do set "BEHIND=%%C"
)
if "!BEHIND!"=="0" set "BEHIND=?"

echo   New commits on GitHub:
git log !LOCAL_SHA!..origin/%BRANCH% --oneline --no-decorate 2>nul
if "!LOCAL_SHA!"=="" echo   (local has no commits – will do a full reset)
echo.

:: ── Confirm (interactive only) ────────────────────────────────────────────────
if /i "%~1"=="--auto" goto :apply

set /p "CONFIRM=  Apply these changes? [Y/n]  "
if /i "!CONFIRM!"=="n" (
    echo   Cancelled.
    echo.
    pause & exit /b 0
)

:apply
:: Hard-reset to remote – overwrites tracked files, leaves untracked alone.
:: db/ and uploads/ are .gitignore'd so they are NEVER touched.
echo.
echo   Applying changes (git reset --hard)...
git reset --hard origin/%BRANCH%
if errorlevel 1 (
    echo.
    echo   [ERROR] Reset failed. Try manually:  git reset --hard origin/%BRANCH%
    echo.
    if /i not "%~1"=="--auto" pause
    exit /b 1
)

echo.
echo   [DONE] Successfully updated!
echo.

:done
set "LAST_COMMIT=unknown"
for /f "delims=" %%L in ('git log -1 --format^="%%h - %%s" 2^>nul') do set "LAST_COMMIT=%%L"
echo   Current HEAD : !LAST_COMMIT!
echo.
echo   Reminder: restart Apache after PHP changes:
echo     net stop Apache2.4 ^& net start Apache2.4
echo.
if /i not "%~1"=="--auto" pause
endlocal
exit /b 0
