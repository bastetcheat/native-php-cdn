@echo off
setlocal EnableDelayedExpansion
title CDN Sync v5 – Pull from GitHub

:: ─────────────────────────────────────────────────────────────────────────────
::  sync.bat v5  –  Robust GitHub sync for CDN Panel (hard-reset strategy).
::
::  Safe:   db/, uploads/, db/temp_chunks/ are .gitignored – NEVER touched.
::
::  Usage:
::    sync.bat                 → interactive (asks before applying)
::    sync.bat --auto          → fully unattended (Task Scheduler / cron)
::    sync.bat --status        → show sync status then exit (no changes)
::    sync.bat --force         → skip "already up to date" check & always reset
::    sync.bat --restart       → also restart Apache after a successful pull
::    sync.bat --auto --restart → unattended + Apache restart
::
::  Examples for Task Scheduler:
::    Program : C:\Windows\System32\cmd.exe
::    Arguments: /c "C:\xampp\htdocs\v2\sync.bat" --auto --restart
:: ─────────────────────────────────────────────────────────────────────────────

set "REPO=https://github.com/bastetcheat/native-php-cdn.git"
set "BRANCH=master"
set "LOG_FILE=%~dp0db\sync_log.txt"

:: Parse flags
set "AUTO=0"
set "STATUS_ONLY=0"
set "FORCE=0"
set "RESTART_APACHE=0"

for %%A in (%*) do (
    if /i "%%A"=="--auto"    set "AUTO=1"
    if /i "%%A"=="--status"  set "STATUS_ONLY=1"
    if /i "%%A"=="--force"   set "FORCE=1"
    if /i "%%A"=="--restart" set "RESTART_APACHE=1"
)

cd /d "%~dp0"

:: ── Box header ────────────────────────────────────────────────────────────────
echo.
echo   ╔══════════════════════════════════════════════════╗
echo   ║       CDN Panel  –  GitHub Sync Tool  v5        ║
echo   ╚══════════════════════════════════════════════════╝
echo.
echo   Remote  : %REPO%
echo   Local   : %~dp0
echo   Log     : %LOG_FILE%
echo.

:: ── Timestamp helper (for log) ────────────────────────────────────────────────
for /f "tokens=2 delims==" %%D in ('wmic OS Get localdatetime /value') do set "_DT=%%D"
set "TIMESTAMP=!_DT:~0,4!-!_DT:~4,2!-!_DT:~6,2! !_DT:~8,2!:!_DT:~10,2!:!_DT:~12,2!"

:: ── git available? ─────────────────────────────────────────────────────────────
where git >nul 2>&1
if errorlevel 1 (
    echo   [ERROR] git not found in PATH.
    echo   Install from: https://git-scm.com/download/win
    call :LOG "[ERROR] git not found"
    if "!AUTO!"=="0" pause
    exit /b 1
)

:: ── No .git folder → fresh clone ──────────────────────────────────────────────
if not exist ".git" (
    echo   [INIT] No git repo detected – cloning fresh...
    git clone --depth=1 --branch %BRANCH% "%REPO%" "_tmp_clone_"
    if errorlevel 1 (
        echo   [ERROR] Clone failed. Check network / authentication.
        call :LOG "[ERROR] Fresh clone failed"
        if "!AUTO!"=="0" pause
        exit /b 1
    )
    xcopy "_tmp_clone_\*" "." /E /Y /I >nul 2>&1
    rmdir /s /q "_tmp_clone_" >nul 2>&1
    echo   [OK] Cloned successfully.
    call :LOG "[OK] Fresh clone complete"
    goto :done
)

:: ── Ensure origin remote exists with the clean public URL ─────────────────────
git remote get-url origin >nul 2>&1
if errorlevel 1 (
    echo   [FIX] Adding missing remote 'origin'...
    git remote add origin "%REPO%"
) else (
    :: Always reset to clean URL (strips any cached credentials from the URL)
    git remote set-url origin "%REPO%"
)

:: ── Detached HEAD auto-fix ────────────────────────────────────────────────────
set "HEAD_REF="
for /f "delims=" %%H in ('git symbolic-ref --short HEAD 2^>nul') do set "HEAD_REF=%%H"
if "!HEAD_REF!"=="" (
    echo   [FIX] Detached HEAD detected – switching to %BRANCH%...
    git checkout -B %BRANCH% origin/%BRANCH% >nul 2>&1
    if errorlevel 1 (
        git checkout %BRANCH% >nul 2>&1
    )
)

:: ── Stash any accidental local changes (keeps untracked safe) ─────────────────
git stash --quiet >nul 2>&1

:: ── Fetch ─────────────────────────────────────────────────────────────────────
echo   Fetching from GitHub...
git fetch origin %BRANCH% 2>"%TEMP%\cdn_fetch_err.txt"
if errorlevel 1 (
    echo.
    echo   ┌── Fetch Error ───────────────────────────────────────┐
    type "%TEMP%\cdn_fetch_err.txt"
    echo   └──────────────────────────────────────────────────────┘
    echo.

    :: Auth error detection
    findstr /i "authentication\|access\|403\|401\|credential\|permission\|denied\|repository not found" "%TEMP%\cdn_fetch_err.txt" >nul 2>&1
    if not errorlevel 1 (
        echo   [AUTH] Authentication error. Possible fixes:
        echo     1. Make sure the repo is public, OR
        echo     2. Run:  git config --global credential.helper manager
        echo     3. Then retry sync.bat to be prompted for login.
        echo.
        if "!AUTO!"=="0" (
            echo   Opening GitHub in browser...
            start "" "https://github.com/login"
        )
    )

    call :LOG "[ERROR] Fetch failed"
    if "!AUTO!"=="0" pause
    exit /b 1
)

:: ── Compare HEAD vs remote ────────────────────────────────────────────────────
set "LOCAL_SHA="
set "REMOTE_SHA="
for /f %%A in ('git rev-parse HEAD 2^>nul')                do set "LOCAL_SHA=%%A"
for /f %%A in ('git rev-parse origin/%BRANCH% 2^>nul')    do set "REMOTE_SHA=%%A"

:: Current commit info
set "LOCAL_INFO=(empty)"
if not "!LOCAL_SHA!"=="" (
    for /f "delims=" %%I in ('git log -1 --format^="%%h  %%s  [%%cr]" 2^>nul') do set "LOCAL_INFO=%%I"
)

echo   Branch  : %BRANCH%
echo   Local   : !LOCAL_INFO!
if not "!REMOTE_SHA!"=="" (
    for /f "delims=" %%R in ('git log -1 origin/%BRANCH% --format^="%%h  %%s  [%%cr]" 2^>nul') do (
        echo   Remote  : %%R
    )
)
echo.

:: Status-only mode
if "!STATUS_ONLY!"=="1" (
    if "!LOCAL_SHA!"=="!REMOTE_SHA!" (
        echo   [UP TO DATE]
    ) else (
        echo   [BEHIND] Updates available:
        git log !LOCAL_SHA!..origin/%BRANCH% --oneline --no-decorate 2>nul
    )
    echo.
    goto :done_quiet
)

:: Already up to date?
if "!LOCAL_SHA!"=="!REMOTE_SHA!" (
    if "!FORCE!"=="0" (
        echo   [UP TO DATE]  No changes to apply.
        echo.
        call :LOG "[UP TO DATE] Already on latest commit"
        goto :done
    )
    echo   [FORCE] Re-applying even though already up to date...
)

:: ── Show incoming changes ──────────────────────────────────────────────────────
set "BEHIND=0"
if not "!LOCAL_SHA!"=="" (
    for /f %%C in ('git rev-list !LOCAL_SHA!..origin/%BRANCH% --count 2^>nul') do set "BEHIND=%%C"
)
if "!BEHIND!"=="0" set "BEHIND=(force)"

echo   Incoming commits (!BEHIND! new^):
if not "!LOCAL_SHA!"=="" (
    git log !LOCAL_SHA!..origin/%BRANCH% --oneline --no-decorate 2>nul
) else (
    echo   (no local commits – full reset)
)
echo.

:: ── Prompt (interactive only) ─────────────────────────────────────────────────
if "!AUTO!"=="0" (
    set /p "CONFIRM=  Apply these changes? [Y/n]  "
    if /i "!CONFIRM!"=="n" (
        echo   [CANCELLED]
        echo.
        if "!AUTO!"=="0" pause
        exit /b 0
    )
)

:apply
:: Hard-reset: overwrites tracked files, leaves untracked/gitignored alone.
:: db/ and uploads/ are gitignored → they are NEVER modified.
echo.
echo   Applying (git reset --hard origin/%BRANCH%)...
git reset --hard origin/%BRANCH%
if errorlevel 1 (
    echo.
    echo   [ERROR] Reset --hard failed. Try:
    echo     git reset --hard origin/%BRANCH%
    echo.
    call :LOG "[ERROR] git reset --hard failed"
    if "!AUTO!"=="0" pause
    exit /b 1
)

:: Remove leftover untracked files that would have been removed by pull
:: (safe: only cleans tracked paths that were deleted in remote)
git clean -fd --dry-run 2>nul | findstr /i "\.php \.htaccess \.bat \.js \.css \.html" >nul 2>&1
if not errorlevel 1 (
    echo   Cleaning stale files...
    git clean -fd >nul 2>&1
)

call :LOG "[OK] Synced to !REMOTE_SHA:~0,8!"

echo.
echo   ┌── Sync Complete! ────────────────────────────────┐
for /f "delims=" %%L in ('git log -1 --format^="  ║  %%h - %%s" 2^>nul') do echo %%L
echo   └──────────────────────────────────────────────────┘
echo.

:: ── Optional Apache restart ───────────────────────────────────────────────────
if "!RESTART_APACHE!"=="1" (
    echo   Restarting Apache...
    set "RESTARTED=0"

    :: Try httpd.exe graceful (XAMPP)
    set "PHPDIR=%~dp0"
    for /f %%P in ('where php 2^>nul') do set "PHPBINARY=%%P"
    if defined PHPBINARY (
        for %%D in ("!PHPBINARY!\..\apache\bin\httpd.exe") do (
            if exist "%%~fD" (
                "%%~fD" -k graceful >nul 2>&1
                set "RESTARTED=1"
                echo   [OK] Apache graceful restart sent (httpd.exe).
            )
        )
    )

    if "!RESTARTED!"=="0" (
        net stop "Apache2.4" >nul 2>&1
        timeout /t 2 /nobreak >nul
        net start "Apache2.4" >nul 2>&1
        if not errorlevel 1 (
            set "RESTARTED=1"
            echo   [OK] Apache restarted via net stop/start.
        )
    )
    if "!RESTARTED!"=="0" (
        echo   [WARN] Could not restart Apache automatically.
        echo   Run manually:  net stop Apache2.4 ^& net start Apache2.4
    )
    echo.
)

:done
echo   Reminder: restart Apache after PHP/config changes:
echo     net stop Apache2.4 ^& net start Apache2.4
echo   Or add --restart flag to auto-restart after sync.
echo.

:done_quiet
if "!AUTO!"=="0" pause
endlocal
exit /b 0

:: ── Subroutine: append a line to the log file ─────────────────────────────────
:LOG
if not exist "%~dp0db" mkdir "%~dp0db" >nul 2>&1
echo [!TIMESTAMP!] %~1 >> "%LOG_FILE%"
goto :eof
