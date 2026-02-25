@echo off
setlocal EnableDelayedExpansion
title CDN Sync – Pull from GitHub

:: ─────────────────────────────────────────────────────────────────────────────
::  sync.bat  –  Pull the latest CDN code from GitHub and apply it in-place.
::
::  Usage:
::    Double-click  sync.bat         → interactive, confirms before pulling
::    sync.bat --auto               → non-interactive (for Task Scheduler)
::
::  Safe to run while Apache is serving:
::    • Never touches  /db/          (SQLite database)
::    • Never touches  /uploads/     (stored files)
::    • Preserves any local .env or config-override files you add to .gitignore
:: ─────────────────────────────────────────────────────────────────────────────

set "REPO=https://github.com/bastetcheat/native-php-cdn.git"
set "DIR=%~dp0"
cd /d "%DIR%"

:: Colours (using ANSI via PowerShell echo trick on Win10+)
for /f %%A in ('echo prompt $E ^| cmd') do set "ESC=%%A"
set "GREEN=%ESC%[92m"
set "CYAN=%ESC%[96m"
set "YELLOW=%ESC%[93m"
set "RED=%ESC%[91m"
set "BOLD=%ESC%[1m"
set "RESET=%ESC%[0m"

echo.
echo %CYAN%%BOLD%  ╔══════════════════════════════════════╗%RESET%
echo %CYAN%%BOLD%  ║   CDN Panel  –  GitHub Sync Tool     ║%RESET%
echo %CYAN%%BOLD%  ╚══════════════════════════════════════╝%RESET%
echo.
echo %CYAN%  Remote : %RESET%%REPO%
echo %CYAN%  Local  : %RESET%%DIR%
echo.

:: ── Check git is available ────────────────────────────────────────────────────
where git >nul 2>&1
if errorlevel 1 (
    echo %RED%  [ERROR] git is not found in PATH.%RESET%
    echo         Install Git for Windows: https://git-scm.com/download/win
    echo.
    pause
    exit /b 1
)

:: ── Ensure this folder is a git repo ─────────────────────────────────────────
if not exist ".git" (
    echo %YELLOW%  [INIT] No .git folder found – cloning fresh copy...%RESET%
    echo.
    :: Clone into a temp folder then move contents here
    git clone --depth=1 "%REPO%" "_cdn_tmp_clone_"
    if errorlevel 1 (
        echo %RED%  [ERROR] Clone failed. Check your internet connection and repo URL.%RESET%
        pause
        exit /b 1
    )
    :: Move everything except db/ uploads/ and itself
    xcopy "_cdn_tmp_clone_\*" "." /E /Y /I /EXCLUDE:sync.bat >nul 2>&1
    rmdir /s /q "_cdn_tmp_clone_"
    echo %GREEN%  [OK] Fresh clone complete.%RESET%
    goto :done
)

:: ── Show current branch + last commit ────────────────────────────────────────
for /f "tokens=*" %%B in ('git rev-parse --abbrev-ref HEAD 2^>nul') do set "BRANCH=%%B"
for /f "tokens=*" %%H in ('git log -1 --format^="%h – %s" 2^>nul') do set "LAST=%%H"
echo %CYAN%  Branch : %RESET%%BRANCH%
echo %CYAN%  HEAD   : %RESET%%LAST%
echo.

:: ── Fetch remote to preview changes ──────────────────────────────────────────
echo %YELLOW%  Fetching remote...%RESET%
git fetch origin >nul 2>&1
if errorlevel 1 (
    echo %RED%  [ERROR] Could not reach GitHub. Check your internet connection.%RESET%
    pause
    exit /b 1
)

:: Count commits behind
for /f %%C in ('git rev-list HEAD..origin/%BRANCH% --count 2^>nul') do set "BEHIND=%%C"
if "!BEHIND!"=="0" (
    echo %GREEN%  [UP TO DATE] Nothing to pull. Already on the latest commit.%RESET%
    echo.
    goto :done
)

echo %YELLOW%  !BEHIND! new commit(s) available:%RESET%
echo.
git log HEAD..origin/%BRANCH% --oneline --no-decorate 2>nul | findstr /r "." && echo.

:: ── Confirm (skip in --auto mode) ────────────────────────────────────────────
if /i "%~1"=="--auto" goto :pull

set /p "CONFIRM=  Pull these changes? [Y/n]  "
if /i "!CONFIRM!"=="n" (
    echo.
    echo %YELLOW%  Cancelled. No changes were applied.%RESET%
    echo.
    pause
    exit /b 0
)

:pull
:: ── Stash any local uncommitted changes (preserves db/ uploads/ via .gitignore) ─
git stash >nul 2>&1

:: ── Pull ─────────────────────────────────────────────────────────────────────
echo.
echo %YELLOW%  Pulling...%RESET%
git pull --ff-only origin %BRANCH%
if errorlevel 1 (
    echo.
    echo %RED%  [ERROR] Pull failed (possible merge conflict or non-fast-forward).%RESET%
    echo         Run:  git pull origin %BRANCH%  manually to see the error.
    echo.
    pause
    exit /b 1
)

:: ── Restore stashed changes (if any) ─────────────────────────────────────────
git stash pop >nul 2>&1

:: ── Show what changed ─────────────────────────────────────────────────────────
echo.
echo %GREEN%%BOLD%  [DONE] Successfully updated!%RESET%
echo.
echo %CYAN%  Files changed in this update:%RESET%
git diff --name-only HEAD~!BEHIND! HEAD 2>nul | findstr /r "."
echo.

:done
for /f "tokens=*" %%L in ('git log -1 --format^="%h – %s" 2^>nul') do set "NEW_LAST=%%L"
echo %GREEN%  Current HEAD : %RESET%%NEW_LAST%
echo.

:: ── Optional: remind about Apache reload ────────────────────────────────────
echo %YELLOW%  Tip: If PHP files changed, reload Apache to apply OPcache changes:%RESET%
echo        net stop Apache2.4 ^& net start Apache2.4
echo        –– or use the Admin Panel → Settings → Restart Apache button
echo.

if /i not "%~1"=="--auto" pause
endlocal
exit /b 0
