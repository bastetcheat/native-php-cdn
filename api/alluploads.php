<?php
/**
 * GET /api/alluploads
 * Returns a paginated inventory of all files stored on this CDN.
 *
 * Auth:   Bearer token (any valid, non-revoked token)
 * Method: GET
 * Query:  page=1, per_page=50 (max 200), search=filename_substring
 *
 * Designed for AI agents and external tools to discover what is hosted.
 * Example:
 *   curl 'https://cdn.example.com/api/alluploads?page=1&per_page=50' \
 *        -H 'Authorization: Bearer cdn_YOUR_TOKEN'
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// Only GET is supported
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit;
}

// Release session lock – this is a read-only bearer-token endpoint
session_write_close();

// Authenticate via Bearer token (any valid token qualifies)
$tokenUser = Security::validateBearerToken($db);
if (!$tokenUser) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized. Include a valid Bearer token: Authorization: Bearer <token>',
    ]);
    exit;
}

// ── Query parameters ────────────────────────────────────────────────────────
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 50)));
$search = Security::sanitize($_GET['search'] ?? '');
$offset = ($page - 1) * $perPage;

// ── Base URL (for constructing absolute download / metadata URLs) ─────────
$scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['SERVER_PORT'] ?? 80) == 443)) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$baseUrl = $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir : '');

// ── SQL ──────────────────────────────────────────────────────────────────────
$countSql = "SELECT COUNT(*) as total FROM files";
$listSql = "SELECT
                f.id,
                f.original_name,
                f.mime_type,
                f.size,
                f.sha256_hash,
                f.extension,
                f.download_count,
                f.version,
                f.created_at,
                f.updated_at,
                u.username  AS uploaded_by,
                t.name      AS uploaded_via_token
             FROM files      f
             LEFT JOIN users        u ON f.uploaded_by  = u.id
             LEFT JOIN oauth_tokens t ON f.token_id     = t.id";

if ($search !== '') {
    $where = " WHERE f.original_name LIKE ?";
    $countSql .= str_replace('f.', '', $where);
    $listSql .= $where . " ORDER BY f.created_at DESC LIMIT ? OFFSET ?";

    $countStmt = $db->prepare($countSql);
    $countStmt->execute(["%$search%"]);
    $total = (int) $countStmt->fetch()['total'];

    $listStmt = $db->prepare($listSql);
    $listStmt->execute(["%$search%", $perPage, $offset]);
} else {
    $total = (int) $db->query($countSql)->fetch()['total'];
    $listSql .= " ORDER BY f.created_at DESC LIMIT ? OFFSET ?";
    $listStmt = $db->prepare($listSql);
    $listStmt->execute([$perPage, $offset]);
}

$files = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Append absolute URLs to each file entry
foreach ($files as &$f) {
    $name = $f['original_name'];
    $f['download_url'] = $baseUrl . '/files/download/' . rawurlencode($name);
    $f['metadata_url'] = $baseUrl . '/files/metadata/' . rawurlencode($name);
    $f['size_human'] = formatBytes((int) $f['size']);
}
unset($f);

// Global stats
$globalRow = $db->query(
    "SELECT COUNT(*) as total_files,
            COALESCE(SUM(size), 0)           as total_size,
            COALESCE(SUM(download_count), 0) as total_downloads
     FROM files"
)->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'data' => [
        'files' => $files,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_results' => $total,  // Matches the current search filter
            'total_pages' => (int) ceil($total / $perPage),
        ],
        'global_stats' => [
            'total_files' => (int) $globalRow['total_files'],
            'total_size' => (int) $globalRow['total_size'],
            'total_size_human' => formatBytes((int) $globalRow['total_size']),
            'total_downloads' => (int) $globalRow['total_downloads'],
        ],
        'authenticated_as' => $tokenUser['username'],
        'base_url' => $baseUrl,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// ── Helper ───────────────────────────────────────────────────────────────────
function formatBytes(int $bytes): string
{
    if ($bytes <= 0)
        return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log($bytes, 1024));
    return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
}
