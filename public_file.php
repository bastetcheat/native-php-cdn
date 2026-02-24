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
$stmt = $db->prepare("SELECT f.*, u.username as uploader FROM files f LEFT JOIN users u ON f.uploaded_by = u.id WHERE f.original_name = ? ORDER BY f.version DESC LIMIT 1");
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
                'uploaded_by' => $file['uploader'],
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

        // Increment download count
        $upd = $db->prepare("UPDATE files SET download_count = download_count + 1 WHERE id = ?");
        $upd->execute([$file['id']]);

        // Stream file
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . $file['size']);
        header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', $file['original_name']) . '"');
        header('Cache-Control: public, max-age=86400');
        header('ETag: "' . $file['sha256_hash'] . '"');
        header("X-Content-Type-Options: nosniff");

        // Check ETag for 304
        $ifNone = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNone === '"' . $file['sha256_hash'] . '"') {
            http_response_code(304);
            exit;
        }

        readfile($filePath);
        break;

    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}

/** Convert bytes to human-readable format */
function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}
