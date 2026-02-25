@echo off
setlocal EnableDelayedExpansion
title CDN Sync – Pull from GitHub

:: ─────────────────────────────────────────────────────────────────────────────
::  sync.bat  –  Pull the latest CDN code from GitHub.
::
::  Usage:
::    Double-click  sync.bat         → interactive (asks Y/n before pulling)
::    sync.bat --auto               → non-interactive (for Task Scheduler)
::
::  Safe to run while Apache is serving:
::    • Never touches  /db/       (SQLite database)
::    • Never touches  /uploads/  (stored files)
:: ─────────────────────────────────────────────────────────────────────────────

set "REPO=https://github.com/bastetcheat/native-php-cdn.git"
set "BRANCH=master"
set "DIR=%~dp0"
cd /d "%DIR%"

:: ── ANSI colours (works on Win10 1607+) ──────────────────────────────────────
for /f %%A in ('echo prompt $E ^| cmd') do set "ESC=%%A"
set "GREEN=%ESC%[92m"
set "CYAN=%ESC%[96m"
set "YELLOW=%ESC%[93m"
set "RED=%ESC%[91m"
set "BOLD=%ESC%[1m"
set "RST=%ESC%[0m"

echo.
echo %CYAN%%BOLD%  ╔══════════════════════════════════════╗%RST%
echo %CYAN%%BOLD%  ║   CDN Panel  –  GitHub Sync Tool     ║%RST%
echo %CYAN%%BOLD%  ╚══════════════════════════════════════╝%RST%
echo.
echo %CYAN%  Remote : %RST%%REPO%
echo %CYAN%  Local  : %RST%%DIR%
echo.

:: ── Check git is available ────────────────────────────────────────────────────
where git >nul 2>&1
if errorlevel 1 (
    echo %RED%  [ERROR] git not found in PATH.%RST%
    echo         Install Git for Windows from: https://git-scm.com/download/win
    echo.
    pause & exit /b 1
)

:: ── First run: no .git folder → clone fresh ───────────────────────────────────
if not exist ".git" (
    echo %YELLOW%  [INIT] No git repo here – cloning from GitHub...%RST%
    echo.
    git clone --depth=1 --branch %BRANCH% "%REPO%" "_cdn_tmp_"
    if errorlevel 1 (
        echo %RED%  [ERROR] Clone failed. See error above.%RST%
        pause & exit /b 1
    )
    xcopy "_cdn_tmp_\*" "." /E /Y /I >nul 2>&1
    rmdir /s /q "_cdn_tmp_"
    echo %GREEN%  [OK] Fresh clone done.%RST%
    goto :done
)

:: ── Ensure the remote URL is correct (no embedded auth tokens) ────────────────
git remote set-url origin "%REPO%" >nul 2>&1

:: ── Fix detached HEAD – always work on master ─────────────────────────────────
for /f "tokens=*" %%B in ('git rev-parse --abbrev-ref HEAD 2^>nul') do set "CURRENT=%%B"
if "!CURRENT!"=="HEAD" (
    echo %YELLOW%  [FIX] Detached HEAD detected – switching to %BRANCH%...%RST%
    git checkout %BRANCH% >nul 2>&1
    if errorlevel 1 (
        :: Branch may not exist locally yet – fetch first
        git fetch origin %BRANCH% >nul 2>&1
        git checkout -b %BRANCH% origin/%BRANCH% >nul 2>&1
    )
)

:: ── Show current state ────────────────────────────────────────────────────────
for /f "tokens=*" %%H in ('git log -1 --format^="%h – %s" 2^>nul') do set "LAST=%%H"
echo %CYAN%  Branch : %RST%%BRANCH%
echo %CYAN%  HEAD   : %RST%%LAST%
echo.

:: ── Fetch (show real error if it fails) ──────────────────────────────────────
echo %YELLOW%  Fetching from GitHub...%RST%
git fetch origin %BRANCH%
if errorlevel 1 (
    echo.
    echo %RED%  [ERROR] git fetch failed (see message above).%RST%
    echo.
    echo         Possible causes:
    echo           1. No internet connection to github.com
    echo           2. Repo was renamed – check the URL in this script
    echo           3. Credential issue – run:
    echo              git credential reject
    echo              then try again
    echo.
    pause & exit /b 1
)

:: ── How many commits behind? ──────────────────────────────────────────────────
for /f %%C in ('git rev-list HEAD..origin/%BRANCH% --count 2^>nul') do set "BEHIND=%%C"
if "!BEHIND!"=="" set "BEHIND=0"

if "!BEHIND!"=="0" (
    echo %GREEN%  [UP TO DATE] Nothing to pull. Already on the latest commit.%RST%
    echo.
    goto :done
)

echo %YELLOW%  !BEHIND! new commit(s) available:%RST%
echo.
git log HEAD..origin/%BRANCH% --oneline --no-decorate 2>nul
echo.

:: ── Confirm (skip in --auto mode) ────────────────────────────────────────────
if /i "%~1"=="--auto" goto :pull

set /p "CONFIRM=  Pull these changes? [Y/n]  "
if /i "!CONFIRM!"=="n" (
    echo.
    echo %YELLOW%  Cancelled. No changes applied.%RST%
    echo.
    pause & exit /b 0
)

:pull
:: Stash any local working-tree changes (db/ and uploads/ are in .gitignore)
git stash >nul 2>&1

:: Pull
echo.
echo %YELLOW%  Pulling...%RST%
git pull --ff-only origin %BRANCH%
if errorlevel 1 (
    echo.
    echo %RED%  [ERROR] Pull failed. Run:  git pull origin %BRANCH%  to diagnose.%RST%
    echo.
    pause & exit /b 1
)

:: Restore stash
git stash pop >nul 2>&1

:: Show changed files
echo.
echo %GREEN%%BOLD%  [DONE] Successfully updated!%RST%
echo.
echo %CYAN%  Files changed:%RST%
git diff --name-only HEAD~!BEHIND! HEAD 2>nul
echo.

:done
for /f "tokens=*" %%L in ('git log -1 --format^="%h – %s" 2^>nul') do set "NEW_LAST=%%L"
echo %GREEN%  Current HEAD : %RST%%NEW_LAST%
echo.
echo %YELLOW%  Tip: Reload Apache after pulling PHP changes:%RST%
echo        net stop Apache2.4 ^& net start Apache2.4
echo.
if /i not "%~1"=="--auto" pause
endlocal
exit /b 0
