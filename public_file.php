<?php
/**
 * Public file endpoint – metadata & download
 * Supports session auth OR OAuth Bearer token
 */
require_once __DIR__ . '/bootstrap.php';

// Public endpoints don't use sessions. Release the session file lock immediately
// so concurrent requests from the same browser are never blocked by a download.
session_write_close();

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

$uploadsDir = Database::getUploadsDir();
$filePath = $uploadsDir . DIRECTORY_SEPARATOR . $file['stored_name'];

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

        clearstatcache(true, $filePath);
        $fileSize = @filesize($filePath);
        if ($fileSize === false || $fileSize < 0) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Could not stat file size']);
            exit;
        }

        $fileMtime = @filemtime($filePath) ?: time();

        // ETag / 304 check BEFORE incrementing — a cached hit is not a real download
        $etag = '"' . $file['sha256_hash'] . '"';
        $ifNone = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($ifNone === $etag) {
            header('ETag: ' . $etag);
            http_response_code(304);
            exit;
        }

        // Sanitize MIME: remap any executable/renderable type to safe binary
        $safeMime = sanitizeMime($file['mime_type'], $file['extension']);

        // Disposition: always 'attachment' for executable/renderable types,
        // 'inline' only for truly safe media types (images, audio, video, pdf)
        $disposition = isSafeInline($safeMime) ? 'inline' : 'attachment';

        $safeFilename = str_replace(['"', '\\', "\r", "\n"], '', $file['original_name']);

        $rangeInfo = resolveRequestedRange($fileSize);
        if ($rangeInfo === false) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid Range']);
            exit;
        }

        $rangeStart = (int) $rangeInfo['start'];
        $rangeEnd = (int) $rangeInfo['end'];
        $isPartial = (bool) $rangeInfo['partial'];
        $rangeMode = (string) ($rangeInfo['mode'] ?? 'full');
        $rangeLength = $rangeEnd - $rangeStart + 1;

        // Increment download count only for real GET downloads (not HEAD/304).
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            $upd = $db->prepare("UPDATE files SET download_count = download_count + 1 WHERE id = ?");
            $upd->execute([$file['id']]);
        }

        header('Content-Type: ' . $safeMime);
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
        header('Cache-Control: public, max-age=86400');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fileMtime) . ' GMT');
        header('Accept-Ranges: bytes');
        header('X-Resume-Supported: 1');
        header('X-Range-Mode: ' . $rangeMode);
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        if (isset($rangeInfo['chunk_size'])) {
            header('X-Chunk-Size: ' . (int) $rangeInfo['chunk_size']);
        }
        if (isset($rangeInfo['chunk_index'])) {
            header('X-Chunk-Index: ' . (int) $rangeInfo['chunk_index']);
        }
        if (isset($rangeInfo['chunk_total'])) {
            header('X-Chunk-Total: ' . (int) $rangeInfo['chunk_total']);
        }

        if ($isPartial) {
            http_response_code(206);
            header('Content-Range: bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $fileSize);
            header('Content-Length: ' . $rangeLength);
        } else {
            http_response_code(200);
            header('Content-Length: ' . $fileSize);
        }

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }

        $streamResult = streamFileRange($filePath, $rangeStart, $rangeLength);
        if ($streamResult !== true) {
            error_log('[cdn] streamFileRange failed for ' . $filePath . ': ' . $streamResult);
        }
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

/**
 * Parse a single HTTP bytes range.
 * Returns:
 * - null  => no range header
 * - false => invalid range
 * - ['start'=>int,'end'=>int] => valid range
 */
function parseHttpRange(string $rangeHeader, int $fileSize)
{
    $rangeHeader = trim($rangeHeader);
    if ($rangeHeader === '') {
        return null;
    }
    if (!preg_match('/^bytes=(\d*)-(\d*)$/i', $rangeHeader, $m)) {
        return false;
    }

    $startRaw = $m[1];
    $endRaw = $m[2];

    if ($startRaw === '' && $endRaw === '') {
        return false;
    }

    if ($startRaw === '') {
        // suffix range: bytes=-N
        $suffixLen = (int) $endRaw;
        if ($suffixLen <= 0) {
            return false;
        }
        $suffixLen = min($suffixLen, $fileSize);
        $start = $fileSize - $suffixLen;
        $end = $fileSize - 1;
        return ['start' => $start, 'end' => $end];
    }

    $start = (int) $startRaw;
    $end = ($endRaw === '') ? ($fileSize - 1) : (int) $endRaw;

    if ($start < 0 || $end < 0 || $start >= $fileSize || $start > $end) {
        return false;
    }

    if ($end >= $fileSize) {
        $end = $fileSize - 1;
    }

    return ['start' => $start, 'end' => $end];
}

/**
 * Read unsigned integer from query string.
 * Returns null when parameter is absent or invalid.
 */
function queryUInt(string $key): ?int
{
    if (!isset($_GET[$key])) {
        return null;
    }

    $raw = trim((string) $_GET[$key]);
    if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
        return null;
    }
    return (int) $raw;
}

/**
 * Resolve requested byte range with support for:
 * - Standard HTTP Range header
 * - Explicit chunk mode: ?part=N&part_size=1048576
 * - Explicit offset mode: ?offset=...&length=...
 *
 * Returns false for invalid request, or:
 * ['start'=>int,'end'=>int,'partial'=>bool,'mode'=>string,...]
 */
function resolveRequestedRange(int $fileSize)
{
    $httpRange = parseHttpRange($_SERVER['HTTP_RANGE'] ?? '', $fileSize);
    if ($httpRange === false) {
        return false;
    }
    if (is_array($httpRange)) {
        return [
            'start' => (int) $httpRange['start'],
            'end' => (int) $httpRange['end'],
            'partial' => true,
            'mode' => 'http-range',
        ];
    }

    $partSize = queryUInt('part_size');
    if ($partSize === null || $partSize <= 0) {
        $partSize = 1024 * 1024; // default 1 MB
    }
    // keep chunks reasonable
    $partSize = max(64 * 1024, min($partSize, 8 * 1024 * 1024));

    $part = queryUInt('part');
    if ($part !== null) {
        $start = $part * $partSize;
        if ($start >= $fileSize) {
            return false;
        }
        $end = min($fileSize - 1, $start + $partSize - 1);
        $chunkTotal = (int) ceil($fileSize / $partSize);
        return [
            'start' => $start,
            'end' => $end,
            'partial' => true,
            'mode' => 'query-part',
            'chunk_size' => $partSize,
            'chunk_index' => $part,
            'chunk_total' => $chunkTotal,
        ];
    }

    $offset = queryUInt('offset');
    $length = queryUInt('length');
    if ($offset !== null || $length !== null) {
        $start = $offset ?? 0;
        if ($start >= $fileSize) {
            return false;
        }
        if ($length === null || $length <= 0) {
            $end = $fileSize - 1;
        } else {
            $end = min($fileSize - 1, $start + $length - 1);
        }
        return [
            'start' => $start,
            'end' => $end,
            'partial' => ($start > 0 || $end < ($fileSize - 1)),
            'mode' => 'query-offset',
            'chunk_size' => $partSize,
        ];
    }

    return [
        'start' => 0,
        'end' => $fileSize - 1,
        'partial' => false,
        'mode' => 'full',
    ];
}

/**
 * Stream file as raw bytes with bounded 1 MB chunks.
 * Returns true on success, string error on failure.
 */
function streamFileRange(string $filePath, int $offset, int $length)
{
    if ($length <= 0) {
        return true;
    }

    @set_time_limit(0);
    ignore_user_abort(true);
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', 'off');

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $in = @fopen($filePath, 'rb');
    if ($in === false) {
        return 'fopen failed';
    }

    if ($offset > 0 && @fseek($in, $offset, SEEK_SET) !== 0) {
        fclose($in);
        return 'fseek failed';
    }

    $chunkSize = 1024 * 1024; // 1 MB
    $remaining = $length;

    while ($remaining > 0 && !feof($in)) {
        $readSize = ($remaining > $chunkSize) ? $chunkSize : $remaining;
        $data = @fread($in, $readSize);
        if ($data === false) {
            fclose($in);
            return 'fread failed';
        }
        if ($data === '') {
            break;
        }

        echo $data;
        $remaining -= strlen($data);

        flush();
        if (function_exists('fastcgi_finish_request')) {
            // noop for Apache mod_php, but harmless elsewhere.
        }

        if (connection_aborted()) {
            break;
        }
    }

    fclose($in);
    return true;
}
