<?php
/**
 * Bootstrap – session config, autoload includes, run migrations
 */

// Strict error handling in production – log, don't display
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Session security configuration
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load includes
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Security.php';

// Ensure uploads directory exists
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0700, true);
}

// Run migrations (create tables + seed admin)
Database::migrate();
