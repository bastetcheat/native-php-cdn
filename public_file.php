<?php
/**
 * Public file endpoint – metadata & download
 * Supports session auth OR OAuth Bearer token
 */
require_once __DIR__ . '/bootstrap.php';

$db = Database::getInstance();

$action = $_GET['action'] ?? '';
$fileName = $_GET['file'] ?? '';

if (empty($fileName) || empty($action)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Missing file name or action']);
    exit;
}

// Prevent path traversal
$fileName = basename($fileName);

// Look up file by original_name (use the most recent version)
// Note: we intentionally do NOT join the users table here.
// Exposing real usernames to public consumers is an information-disclosure risk.
$stmt = $db->prepare("SELECT * FROM files WHERE original_name = ? ORDER BY version DESC LIMIT 1");
$stmt->execute([$fileName]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'File not found']);
    exit;
}

$uploadsDir = __DIR__ . '/uploads';
$filePath = $uploadsDir . '/' . $file['stored_name'];

switch ($action) {
    case 'metadata':
        header('Content-Type: application/json');
        header("X-Content-Type-Options: nosniff");

        // Resolve a safe, non-identifying uploader label.
        // We never expose the real username to public consumers.
        // If the file was uploaded via an OAuth token, show the token name;
        // otherwise fall back to the generic "Admin" label.
        $uploaderLabel = 'Admin';
        if (!empty($file['token_id'])) {
            $tkStmt = $db->prepare("SELECT name FROM oauth_tokens WHERE id = ?");
            $tkStmt->execute([$file['token_id']]);
            $tk = $tkStmt->fetch();
            if ($tk && !empty($tk['name'])) {
                $uploaderLabel = 'via token: ' . $tk['name'];
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => (int) $file['id'],
                'original_name' => $file['original_name'],
                'mime_type' => $file['mime_type'],
                'size' => (int) $file['size'],
                'size_human' => formatBytes((int) $file['size']),
                'sha256_hash' => $file['sha256_hash'],
                'extension' => $file['extension'],
                'download_count' => (int) $file['download_count'],
                'version' => (int) $file['version'],
                'uploaded_by' => $uploaderLabel,   // never the real username
                'created_at' => $file['created_at'],
                'updated_at' => $file['updated_at'],
                'download_url' => 'files/download/' . urlencode($file['original_name']),
            ]
        ]);
        break;

    case 'download':
        if (!file_exists($filePath)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'File not found on disk']);
            exit;
        }

        // ETag / 304 check BEFORE incrementing — a cached hit is not a real download
        $etag = '"' . $file['sha256_hash'] . '"';
        $ifNone = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($ifNone === $etag) {
            header('ETag: ' . $etag);
            http_response_code(304);
            exit;
        }

        // Increment download count (only for real, non-cached downloads)
        $upd = $db->prepare("UPDATE files SET download_count = download_count + 1 WHERE id = ?");
        $upd->execute([$file['id']]);

        // Sanitize MIME: remap any executable/renderable type to safe binary
        $safeMime = sanitizeMime($file['mime_type'], $file['extension']);

        // Disposition: always 'attachment' for executable/renderable types,
        // 'inline' only for truly safe media types (images, audio, video, pdf)
        $disposition = isSafeInline($safeMime) ? 'inline' : 'attachment';

        $safeFilename = str_replace(['"', '\\', "\r", "\n"], '', $file['original_name']);

        header('Content-Type: ' . $safeMime);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
        header('Cache-Control: public, max-age=86400');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');

        readfile($filePath);
        break;

    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}

/**
 * Remap executable or renderable MIME types to application/octet-stream.
 * This is the key security layer: even if a PHP or HTML file was uploaded,
 * the browser will never execute or render it – it just downloads binary bytes.
 */
function sanitizeMime(string $mime, string $ext): string
{
    // Explicit dangerous MIMEs → force binary
    $dangerous = [
        'text/html',
        'text/xhtml+xml',
        'application/xhtml+xml',
        'application/x-php',
        'application/php',
        'text/php',
        'text/x-php',
        'application/x-httpd-php',
        'text/javascript',
        'application/javascript',
        'application/ecmascript',
        'text/ecmascript',
        'application/x-sh',
        'text/x-shellscript',
        'application/x-cgi',
        'application/x-perl',
        'text/x-perl',
        'application/x-python',
        'text/x-python',
        'application/x-ruby',
        'image/svg+xml',          // SVG can contain embedded JS
        'application/xml',
        'text/xml',
    ];

    if (in_array(strtolower($mime), $dangerous, true)) {
        return 'application/octet-stream';
    }

    // Also catch by extension as a fallback
    $dangerousExts = [
        'php',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'phtml',
        'phar',
        'html',
        'htm',
        'xhtml',
        'js',
        'mjs',
        'cjs',
        'ts',
        'sh',
        'bash',
        'cgi',
        'pl',
        'py',
        'rb',
        'cmd',
        'bat',
        'ps1',
        'svg',
        'xml',
        'xsl'
    ];
    if (in_array(strtolower($ext), $dangerousExts, true)) {
        return 'application/octet-stream';
    }

    return $mime;
}

/**
 * Returns true only for MIME types that are safe to display inline in a browser
 * (images, audio, video, PDF) — everything else forces a download.
 */
function isSafeInline(string $mime): bool
{
    $safeInline = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/ico',
        'image/x-icon',
        'audio/mpeg',
        'audio/ogg',
        'audio/wav',
        'audio/flac',
        'audio/webm',
        'video/mp4',
        'video/webm',
        'video/ogg',
        'application/pdf',
    ];
    return in_array(strtolower($mime), $safeInline, true);
}

/** Convert bytes to human-readable format */
function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}
