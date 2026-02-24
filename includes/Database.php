<?php
/**
 * Database singleton – SQLite via PDO
 *
 * Key performance settings:
 *   busy_timeout  – wait up to 5 s instead of throwing SQLITE_BUSY immediately.
 *                   Without this, concurrent requests cause PHP exceptions which
 *                   Apache turns into HTML 500 pages, breaking the JSON API.
 *   synchronous   – NORMAL is safe with WAL and 3-5× faster than FULL (default).
 *   cache_size    – 4 MB in-process page cache reduces filesystem I/O.
 *   mmap_size     – 128 MB memory-mapped I/O for fast reads.
 *   temp_store    – keep temp tables in RAM, not on disk.
 *
 * migrate() uses PRAGMA user_version so the schema setup runs ONCE when the DB
 * is first created, not on every single HTTP request.
 */
class Database
{
    private static ?PDO $instance = null;

    /** Current schema version – bump this when you add tables/columns */
    private const SCHEMA_VERSION = 3;

    public static function getInstance(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dbPath = __DIR__ . '/../db/cdn.sqlite';
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // ── Performance & concurrency PRAGMAs ────────────────────────────────
        // ORDER MATTERS: WAL first, then the rest.
        $pdo->exec('PRAGMA journal_mode = WAL');          // concurrent readers + writer
        $pdo->exec('PRAGMA busy_timeout = 5000');         // wait 5 s on lock instead of fail
        $pdo->exec('PRAGMA synchronous = NORMAL');        // safe with WAL, 3-5× faster
        $pdo->exec('PRAGMA cache_size = -4000');          // 4 MB page cache (negative = KB)
        $pdo->exec('PRAGMA mmap_size = 134217728');       // 128 MB memory-mapped I/O
        $pdo->exec('PRAGMA temp_store = MEMORY');         // temp tables in RAM
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$instance = $pdo;
        return $pdo;
    }

    /**
     * Run schema migrations only when the DB schema version is behind.
     * This means setup runs once on first deploy, not on every HTTP request.
     */
    public static function migrate(): void
    {
        $db = self::getInstance();

        $currentVersion = (int) $db->query('PRAGMA user_version')->fetchColumn();
        if ($currentVersion >= self::SCHEMA_VERSION) {
            return; // already up to date – skip all DDL
        }

        // ── Schema v1: core tables ────────────────────────────────────────────
        if ($currentVersion < 1) {
            $db->exec("CREATE TABLE IF NOT EXISTS users (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                username     TEXT    NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT   NOT NULL,
                must_change_pw INTEGER NOT NULL DEFAULT 1,
                created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS files (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                original_name  TEXT    NOT NULL,
                stored_name    TEXT    NOT NULL UNIQUE,
                mime_type      TEXT    NOT NULL DEFAULT 'application/octet-stream',
                size           INTEGER NOT NULL DEFAULT 0,
                sha256_hash    TEXT    NOT NULL,
                extension      TEXT    NOT NULL DEFAULT '',
                download_count INTEGER NOT NULL DEFAULT 0,
                version        INTEGER NOT NULL DEFAULT 1,
                uploaded_by    INTEGER NOT NULL,
                created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at     TEXT    NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (uploaded_by) REFERENCES users(id)
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS oauth_tokens (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                name         TEXT    NOT NULL,
                token_hash   TEXT    NOT NULL UNIQUE,
                token_prefix TEXT    NOT NULL,
                permissions  TEXT    NOT NULL DEFAULT 'upload,download,metadata',
                expires_at   TEXT    DEFAULT NULL,
                revoked      INTEGER NOT NULL DEFAULT 0,
                last_used_at TEXT    DEFAULT NULL,
                created_by   INTEGER NOT NULL,
                created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (created_by) REFERENCES users(id)
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                ip           TEXT PRIMARY KEY,
                attempts     INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT    DEFAULT NULL
            )");
        }

        // ── Schema v2: indexes for fast lookups ───────────────────────────────
        if ($currentVersion < 2) {
            // files.original_name is queried on every public metadata/download request
            $db->exec('CREATE INDEX IF NOT EXISTS idx_files_original_name ON files(original_name)');
            // files sorted by date on list page
            $db->exec('CREATE INDEX IF NOT EXISTS idx_files_created_at   ON files(created_at DESC)');
            // token validation looks up by hash
            $db->exec('CREATE INDEX IF NOT EXISTS idx_tokens_hash        ON oauth_tokens(token_hash)');
            // active (non-revoked, non-expired) token filter
            $db->exec('CREATE INDEX IF NOT EXISTS idx_tokens_active      ON oauth_tokens(revoked, expires_at)');
        }

        // ── Schema v3: seed default admin if no users exist ──────────────────
        if ($currentVersion < 3) {
            $count = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($count === 0) {
                // Argon2ID is intentionally slow – only runs once ever
                $hash = password_hash('admin', PASSWORD_ARGON2ID);
                $ins = $db->prepare("INSERT INTO users (username, password_hash, must_change_pw) VALUES (?, ?, 1)");
                $ins->execute(['admin', $hash]);
            }
        }

        // Mark schema as up to date
        $db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }
}
