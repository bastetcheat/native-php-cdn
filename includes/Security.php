<?php
/**
 * Security helpers – CSRF, rate-limiting, sanitization, headers, file validation
 */
class Security
{

    // ─── CSRF ────────────────────────────────────────────────
    public static function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrf(): bool
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            return false;
        }
        return true;
    }

    public static function requireCsrf(): void
    {
        if (!self::validateCsrf()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    }

    // ─── Rate Limiting ───────────────────────────────────────
    public static function checkRateLimit(PDO $db, string $ip, int $maxAttempts = 5, int $lockoutMinutes = 15): bool
    {
        $stmt = $db->prepare("SELECT attempts, locked_until FROM login_attempts WHERE ip = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row) {
            if ($row['locked_until'] && strtotime($row['locked_until']) > time()) {
                return false; // Still locked
            }
            if ($row['locked_until'] && strtotime($row['locked_until']) <= time()) {
                // Lock expired, reset
                $reset = $db->prepare("UPDATE login_attempts SET attempts = 0, locked_until = NULL WHERE ip = ?");
                $reset->execute([$ip]);
            }
        }
        return true;
    }

    public static function recordFailedLogin(PDO $db, string $ip, int $maxAttempts = 5, int $lockoutMinutes = 15): void
    {
        $stmt = $db->prepare("SELECT attempts FROM login_attempts WHERE ip = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row) {
            $newAttempts = (int) $row['attempts'] + 1;
            $lockUntil = null;
            if ($newAttempts >= $maxAttempts) {
                $lockUntil = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
            }
            $update = $db->prepare("UPDATE login_attempts SET attempts = ?, locked_until = ? WHERE ip = ?");
            $update->execute([$newAttempts, $lockUntil, $ip]);
        } else {
            $insert = $db->prepare("INSERT INTO login_attempts (ip, attempts, locked_until) VALUES (?, 1, NULL)");
            $insert->execute([$ip]);
        }
    }

    public static function clearLoginAttempts(PDO $db, string $ip): void
    {
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip = ?");
        $stmt->execute([$ip]);
    }

    // ─── Input Sanitization ─────────────────────────────────
    public static function sanitize(string $input): string
    {
        return trim(strip_tags($input));
    }

    /** HTML-encode for safe output (XSS prevention) */
    public static function e(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ─── Security Headers ───────────────────────────────────
    public static function sendHeaders(): void
    {
        header("Content-Type: application/json; charset=UTF-8");
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://fonts.googleapis.com; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://fonts.googleapis.com https://fonts.gstatic.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'");
    }

    // ─── File Validation ────────────────────────────────────
    private static array $allowedMimes = [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'image/bmp',
        'image/ico',
        'image/x-icon',
        // Video
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/avi',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-matroska',
        // Audio
        'audio/mpeg',
        'audio/ogg',
        'audio/wav',
        'audio/webm',
        'audio/flac',
        // Documents
        'application/pdf',
        'text/plain',
        'text/csv',
        'text/css',
        'text/javascript',
        'text/html',
        'application/json',
        'application/xml',
        'text/xml',
        'application/rtf',
        // Archives (all variants)
        'application/zip',
        'application/x-zip-compressed',
        'application/x-rar-compressed',
        'application/x-rar',
        'application/vnd.rar',
        'application/rar',
        'application/x-7z-compressed',
        'application/gzip',
        'application/x-gzip',
        'application/x-tar',
        'application/x-bzip2',
        // Executables / Windows binaries
        'application/x-msdownload',
        'application/x-dosexec',
        'application/x-executable',
        'application/vnd.microsoft.portable-executable',
        // Generic binary (covers .pt, .onnx, .bin, .dat, etc.)
        'application/octet-stream',
        // Fonts
        'font/woff',
        'font/woff2',
        'font/ttf',
        'font/otf',
        'application/font-woff',
        'application/font-woff2',
        // WebAssembly
        'application/wasm',
    ];

    /** Extensions that are always allowed regardless of detected MIME (for binary/model files) */
    private static array $allowedExtensions = [
        'zip',
        'rar',
        '7z',
        'gz',
        'tar',
        'bz2',
        'exe',
        'dll',
        'msi',
        'sys',
        'pt',
        'onnx',
        'bin',
        'dat',
        'model',
        'pb',
        'h5',
        'safetensors',
        'pth',
        'pkl',
        'ckpt',
        'weights',
        'iso',
        'img',
        'ttf',
        'otf',
        'woff',
        'woff2',
        'wasm',
    ];

    private static int $maxFileSize = 100 * 1024 * 1024; // 100 MB

    public static function validateFile(array $file): ?string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return 'File upload failed with error code: ' . $file['error'];
        }
        if ($file['size'] > self::$maxFileSize) {
            return 'File exceeds maximum size of 100MB';
        }
        // Detect real MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);

        // Allow if MIME is in whitelist
        if (in_array($detectedMime, self::$allowedMimes, true)) {
            return null;
        }

        // Fallback: allow by file extension (for binaries like .rar, .pt, .onnx)
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::$allowedExtensions, true)) {
            return null;
        }

        return 'File type not allowed: ' . $detectedMime . ' (.' . $ext . ')';
    }

    public static function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // ─── Auth Helpers ───────────────────────────────────────
    public static function requireAuth(): array
    {
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Authentication required']);
            exit;
        }
        $user = [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
        ];

        // ── Release session lock ASAP ────────────────────────────────────────
        // The admin panel fires multiple API requests in parallel (auth/me, files,
        // tokens…). PHP's default session handler locks the session FILE for the
        // ENTIRE duration of each request. Without this call, requests queue up
        // single-file and Apache workers hang waiting for the lock.
        // We've already read everything we need from $_SESSION, so close it now.
        session_write_close();

        return $user;
    }

    /** Validate OAuth Bearer token, return user info or null */
    public static function validateBearerToken(PDO $db): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }
        $rawToken = $matches[1];
        $tokenHash = hash('sha256', $rawToken);

        $stmt = $db->prepare("SELECT t.*, u.username FROM oauth_tokens t JOIN users u ON t.created_by = u.id WHERE t.token_hash = ?");
        $stmt->execute([$tokenHash]);
        $token = $stmt->fetch();

        if (!$token)
            return null;
        if ($token['revoked'])
            return null;
        if ($token['expires_at'] && strtotime($token['expires_at']) < time())
            return null;

        // Update last_used_at
        $update = $db->prepare("UPDATE oauth_tokens SET last_used_at = datetime('now') WHERE id = ?");
        $update->execute([$token['id']]);

        return [
            'id' => $token['created_by'],
            'username' => $token['username'],
            'token_id' => $token['id'],
            'permissions' => $token['permissions'],
        ];
    }

    /** Get client IP safely */
    public static function getClientIp(): string
    {
        // Only trust REMOTE_ADDR to prevent spoofing
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
