<?php
/**
 * Bootstrap – session config, autoload includes, run migrations
 *
 * Session locking note
 * --------------------
 * PHP's default session handler locks the session FILE for the duration of
 * the entire request. The admin panel fires several API calls in parallel
 * (auth/me, files list, tokens list…). Because they all share one session
 * file, they queue up one-by-one behind the lock, making the whole server
 * feel slow or frozen.
 *
 * Fix: call session_write_close() as soon as we've read/written everything
 * we need from $_SESSION. Each API endpoint does this explicitly after its
 * auth check. Public endpoints (public_file.php) close the session
 * immediately since they don't need it at all.
 */

// Strict error handling – log, never display (displaying leaks stack traces)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Session security
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
// Shorter GC interval – keeps session storage tidy
ini_set('session.gc_maxlifetime', '7200');  // 2 hours

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load helpers
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Security.php';

// Ensure uploads directory exists (fast fs check)
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0700, true);
}

// Run migrations – internally skips everything if schema is current.
// Cost on warm runs: one PRAGMA user_version read (microseconds).
Database::migrate();

// ── Probabilistic housekeeping (runs ~1% of requests) ────────────────────────
// Cleans up expired login_attempts rows so the table never grows unbounded.
// Using random rather than a cron job keeps the project dependency-free.
if (random_int(1, 100) === 1) {
    try {
        Database::getInstance()->exec(
            "DELETE FROM login_attempts WHERE locked_until IS NOT NULL AND locked_until < datetime('now')"
        );
    } catch (Throwable $e) {
        // Non-critical – log and continue
        error_log('Housekeeping error: ' . $e->getMessage());
    }
}
