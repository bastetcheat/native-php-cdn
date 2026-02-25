@echo off
setlocal EnableDelayedExpansion
title CDN Sync – Pull from GitHub

:: ─────────────────────────────────────────────────────────────────────────────
::  sync.bat  –  Pull the latest CDN code from GitHub (public repo).
::  Usage:
::    sync.bat          → interactive
::    sync.bat --auto  → no prompts (Task Scheduler / CI)
:: ─────────────────────────────────────────────────────────────────────────────

set "REPO=https://github.com/bastetcheat/native-php-cdn.git"
set "REPO_WEB=https://github.com/bastetcheat/native-php-cdn"
set "BRANCH=master"

cd /d "%~dp0"

:: ── git available? ────────────────────────────────────────────────────────────
where git >nul 2>&1
if errorlevel 1 (
    echo [ERROR] git not found. Install from: https://git-scm.com/download/win
    pause & exit /b 1
)

echo.
echo   =========================================
echo    CDN Panel - GitHub Sync Tool
echo   =========================================
echo.
echo   Remote : %REPO%
echo   Local  : %~dp0
echo.

:: ── First-run: no .git →  clone ───────────────────────────────────────────────
if not exist ".git" (
    echo   [INIT] No git repo – cloning...
    git clone --depth=1 --branch %BRANCH% "%REPO%" "_tmp_cdn_clone_"
    if errorlevel 1 (
        echo.
        echo   [ERROR] Clone failed. Check git error above.
        pause & exit /b 1
    )
    xcopy "_tmp_cdn_clone_\*" "." /E /Y /I >nul 2>&1
    rmdir /s /q "_tmp_cdn_clone_" >nul 2>&1
    echo   [OK] Clone done.
    goto :done
)

:: ── Ensure origin remote exists with the correct URL ─────────────────────────
git remote get-url origin >nul 2>&1
if errorlevel 1 (
    echo   [FIX] No origin remote – adding...
    git remote add origin "%REPO%"
) else (
    git remote set-url origin "%REPO%"
)

:: ── Fix detached HEAD ─────────────────────────────────────────────────────────
set "CUR_BRANCH="
for /f "delims=" %%B in ('git rev-parse --abbrev-ref HEAD 2^>nul') do set "CUR_BRANCH=%%B"
if "!CUR_BRANCH!"=="HEAD" (
    echo   [FIX] Detached HEAD – switching to %BRANCH%...
    git checkout %BRANCH% >nul 2>&1
    if errorlevel 1 (
        git fetch origin %BRANCH% >nul 2>&1
        git checkout -b %BRANCH% origin/%BRANCH% >nul 2>&1
    )
)

:: ── Show current HEAD ─────────────────────────────────────────────────────────
set "LAST_COMMIT=unknown"
for /f "delims=" %%H in ('git log -1 --format^="%%h - %%s" 2^>nul') do set "LAST_COMMIT=%%H"
echo   Branch : %BRANCH%
echo   HEAD   : !LAST_COMMIT!
echo.

:: ── Fetch  ───────────────────────────────────────────────────────────────────
echo   Fetching from GitHub...
git fetch origin %BRANCH% 2>"%TEMP%\cdn_fetch_err.txt"
if errorlevel 1 (
    echo.
    echo   [ERROR] Fetch failed. Git says:
    type "%TEMP%\cdn_fetch_err.txt"
    echo.

    :: Check if it looks like an auth/access error
    findstr /i "authentication\|access\|403\|401\|credential\|permission" "%TEMP%\cdn_fetch_err.txt" >nul 2>&1
    if not errorlevel 1 (
        echo   [AUTH] Opening GitHub login in your browser...
        start "" "https://github.com/login?return_to=%REPO_WEB%"
        echo   Sign in, then run sync.bat again.
    )
    echo.
    if /i not "%~1"=="--auto" pause
    exit /b 1
)

:: ── Count commits behind ──────────────────────────────────────────────────────
set "BEHIND=0"
for /f %%C in ('git rev-list HEAD..origin/%BRANCH% --count 2^>nul') do set "BEHIND=%%C"

if "!BEHIND!"=="0" (
    echo   [UP TO DATE] Already on the latest commit.
    echo.
    goto :done
)

echo   !BEHIND! new commit(s) available:
echo.
git log HEAD..origin/%BRANCH% --oneline --no-decorate 2>nul
echo.

:: ── Confirm (interactive only) ────────────────────────────────────────────────
if /i "%~1"=="--auto" goto :pull
set /p "CONFIRM=  Pull? [Y/n]  "
if /i "!CONFIRM!"=="n" (
    echo   Cancelled. & echo. & pause & exit /b 0
)

:pull
git stash >nul 2>&1
echo   Pulling...
git pull --ff-only origin %BRANCH%
if errorlevel 1 (
    echo.
    echo   [ERROR] Pull failed. Try running:  git pull origin %BRANCH%
    echo.
    if /i not "%~1"=="--auto" pause
    exit /b 1
)
git stash pop >nul 2>&1

echo.
echo   [DONE] Updated successfully!
echo.
echo   Changed files:
git diff --name-only HEAD~%BEHIND% HEAD 2>nul
echo.

:done
set "LAST_COMMIT=unknown"
for /f "delims=" %%L in ('git log -1 --format^="%%h - %%s" 2^>nul') do set "LAST_COMMIT=%%L"
echo   Current HEAD : !LAST_COMMIT!
echo.
echo   Reminder: restart Apache if PHP files changed:
echo     net stop Apache2.4 ^& net start Apache2.4
echo.
if /i not "%~1"=="--auto" pause
endlocal
exit /b 0
