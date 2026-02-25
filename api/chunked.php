<?php
/**
 * Chunked Upload API
 * Allows bypassing Cloudflare 100MB limit by uploading in small pieces.
 *
 * Accepts either:
 *   - Bearer token (external agents)
 *   - Session auth + CSRF (Admin Panel)
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// ── Auth: try Bearer first, then session ──────────────────────────────────────
$tokenUser = Security::validateBearerToken($db);
$sessionUser = null;

if (!$tokenUser) {
    // Try session auth (admin panel) – requireAuth() exits with 401 if not logged in
    if ($method !== 'GET') {
        Security::requireCsrf();
    }
    $sessionUser = Security::requireAuth(); // exits on failure
    $userId = $sessionUser['id'];
    $tokenId = null;
} else {
    session_write_close();
    // Check upload permission for token users
    $perms = explode(',', $tokenUser['permissions']);
    if (!in_array('upload', $perms, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token does not have upload permission']);
        exit;
    }
    $userId = $tokenUser['id'];
    $tokenId = $tokenUser['token_id'];
}


$action = $_GET['action'] ?? '';
$tempBase = __DIR__ . '/../db/temp_chunks';
if (!is_dir($tempBase)) {
    mkdir($tempBase, 0777, true);
}

switch ($action) {

    // ── 1. Start a new upload session ────────────────────────────────────────
    case 'start':
        if ($method !== 'POST') {
            http_response_code(405);
            exit;
        }

        $uploadId = Security::generateUUID();
        $sessionPath = $tempBase . '/' . $uploadId;
        mkdir($sessionPath, 0777, true);

        // Store metadata so finish can verify ownership
        file_put_contents($sessionPath . '/.meta', json_encode([
            'user_id' => $userId,
            'token_id' => $tokenId,
            'created' => time(),
        ]));

        echo json_encode(['success' => true, 'data' => ['upload_id' => $uploadId]]);
        break;

    // ── 2. Upload a single chunk ──────────────────────────────────────────────
    case 'upload':
        if ($method !== 'POST') {
            http_response_code(405);
            exit;
        }

        $uploadId = $_POST['upload_id'] ?? '';
        $chunkIndex = isset($_POST['chunk_index']) ? (int) $_POST['chunk_index'] : -1;

        if (!$uploadId || $chunkIndex < 0 || empty($_FILES['chunk'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing chunk data (upload_id, chunk_index, chunk)']);
            exit;
        }

        $sessionPath = $tempBase . '/' . preg_replace('/[^a-f0-9\-]/i', '', $uploadId);
        if (!is_dir($sessionPath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Invalid upload_id']);
            exit;
        }

        if ($_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Chunk upload error: ' . $_FILES['chunk']['error']]);
            exit;
        }

        move_uploaded_file($_FILES['chunk']['tmp_name'], $sessionPath . '/' . $chunkIndex);
        echo json_encode(['success' => true]);
        break;

    // ── 3. Finish: merge chunks, validate, register in DB ────────────────────
    case 'finish':
        if ($method !== 'POST') {
            http_response_code(405);
            exit;
        }

        $uploadId = $_POST['upload_id'] ?? '';
        $filename = Security::sanitize($_POST['filename'] ?? '');
        $expectedHash = $_POST['sha256'] ?? '';
        $totalChunks = (int) ($_POST['total_chunks'] ?? 0);

        if (!$uploadId || !$filename || $totalChunks <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing finalization data (upload_id, filename, total_chunks)']);
            exit;
        }

        $safeId = preg_replace('/[^a-f0-9\-]/i', '', $uploadId);
        $sessionPath = $tempBase . '/' . $safeId;

        if (!is_dir($sessionPath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Invalid or expired upload_id']);
            exit;
        }

        $uploadsDir = Database::getUploadsDir();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $storeName = Security::generateUUID() . ($ext ? '.' . $ext : '');
        $finalPath = $uploadsDir . DIRECTORY_SEPARATOR . $storeName;

        // Merge all chunks in order
        $out = fopen($finalPath, 'wb');
        if (!$out) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Cannot create destination file']);
            exit;
        }
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $sessionPath . '/' . $i;
            if (!file_exists($chunkFile)) {
                fclose($out);
                @unlink($finalPath);
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing chunk $i – upload incomplete"]);
                exit;
            }
            $in = fopen($chunkFile, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        // Verify integrity
        $actualHash = hash_file('sha256', $finalPath);
        if ($expectedHash && $actualHash !== $expectedHash) {
            @unlink($finalPath);
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Hash mismatch – file may be corrupted',
                'expected' => $expectedHash,
                'actual' => $actualHash,
            ]);
            exit;
        }

        $size = filesize($finalPath);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($finalPath);

        // ── Duplicate / version detection (same logic as upload.php) ─────────
        $exStmt = $db->prepare(
            "SELECT id, stored_name, sha256_hash, version FROM files WHERE original_name = ? LIMIT 1"
        );
        $exStmt->execute([$filename]);
        $exRow = $exStmt->fetch();

        if ($exRow) {
            if ($exRow['sha256_hash'] === $actualHash) {
                // Exact duplicate – throw away the merged file
                @unlink($finalPath);
                _cleanChunks($sessionPath);
                echo json_encode([
                    'success' => true,
                    'code' => 'already_exists',
                    'message' => 'File already exists with identical content. No action taken.',
                    'data' => [
                        'id' => (int) $exRow['id'],
                        'original_name' => $filename,
                        'version' => (int) $exRow['version'],
                        'download_url' => 'files/download/' . urlencode($filename),
                        'metadata_url' => 'files/metadata/' . urlencode($filename),
                    ],
                ]);
                exit;
            }

            // Same name, different hash → version update
            $oldPath = $uploadsDir . DIRECTORY_SEPARATOR . $exRow['stored_name'];
            if (is_file($oldPath))
                @unlink($oldPath);

            $newVersion = (int) $exRow['version'] + 1;
            $db->prepare(
                "UPDATE files
                 SET stored_name = ?, mime_type = ?, size = ?, sha256_hash = ?,
                     extension = ?, uploaded_by = ?, token_id = ?,
                     version = ?, updated_at = datetime('now')
                 WHERE id = ?"
            )->execute([$storeName, $mime, $size, $actualHash, $ext, $userId, $tokenId, $newVersion, (int) $exRow['id']]);

            $fileId = (int) $exRow['id'];
            $code = 'updated';
        } else {
            // Brand-new file
            $db->prepare(
                "INSERT INTO files
                    (original_name, stored_name, mime_type, size, sha256_hash, extension, uploaded_by, token_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$filename, $storeName, $mime, $size, $actualHash, $ext, $userId, $tokenId]);

            $fileId = (int) $db->lastInsertId();
            $newVersion = 1;
            $code = 'created';
        }

        _cleanChunks($sessionPath);

        echo json_encode([
            'success' => true,
            'code' => $code,
            'data' => [
                'id' => $fileId,
                'original_name' => $filename,
                'sha256_hash' => $actualHash,
                'size' => $size,
                'mime_type' => $mime,
                'version' => $newVersion,
                'download_url' => 'files/download/' . urlencode($filename),
                'metadata_url' => 'files/metadata/' . urlencode($filename),
            ],
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action. Use: start, upload, finish']);
}

// ── Helper ────────────────────────────────────────────────────────────────────
function _cleanChunks(string $dir): void
{
    foreach (glob($dir . '/*') as $f)
        @unlink($f);
    @rmdir($dir);
}
