<?php
/**
 * Database singleton – SQLite via PDO
 * Security: ERRMODE_EXCEPTION + real prepared statements (no emulation)
 */
class Database {
    private static ?PDO $instance = null;
    private static string $dbPath;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::$dbPath = __DIR__ . '/../db/cdn.sqlite';
            $dir = dirname(self::$dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            self::$instance = new PDO('sqlite:' . self::$dbPath, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Enable WAL mode for better concurrency
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
        }
        return self::$instance;
    }

    /** Run all CREATE TABLE statements */
    public static function migrate(): void {
        $db = self::getInstance();

        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            must_change_pw INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL UNIQUE,
            mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
            size INTEGER NOT NULL DEFAULT 0,
            sha256_hash TEXT NOT NULL,
            extension TEXT NOT NULL DEFAULT '',
            download_count INTEGER NOT NULL DEFAULT 0,
            version INTEGER NOT NULL DEFAULT 1,
            uploaded_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS oauth_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            token_prefix TEXT NOT NULL,
            permissions TEXT NOT NULL DEFAULT 'upload,download,metadata',
            expires_at TEXT DEFAULT NULL,
            revoked INTEGER NOT NULL DEFAULT 0,
            last_used_at TEXT DEFAULT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (created_by) REFERENCES users(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            ip TEXT PRIMARY KEY,
            attempts INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT DEFAULT NULL
        )");

        // Seed default admin user if none exists
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM users");
        $count = $stmt->fetch()['cnt'];
        if ((int)$count === 0) {
            $hash = password_hash('admin', PASSWORD_ARGON2ID);
            $ins = $db->prepare("INSERT INTO users (username, password_hash, must_change_pw) VALUES (?, ?, 1)");
            $ins->execute(['admin', $hash]);
        }
    }
}
