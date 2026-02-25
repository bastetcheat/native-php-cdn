<?php
/**
 * OAuth Upload Endpoint – for external agents/AI
 * Authenticates via Bearer token only (no session needed)
 *
 * Supports two modes:
 * 1) Standard multipart upload (file)
 * 2) Chunked multipart upload (chunked=1 + upload_id + chunk_index/chunk_count)
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// Release session lock – large uploads must not block the browser
session_write_close();

if ($method !== 'POST') {
    respondError(405, 'Method not allowed');
}

// Authenticate via Bearer token
$tokenUser = Security::validateBearerToken($db);
if (!$tokenUser) {
    respondError(401, 'Invalid or expired token');
}

// Check upload permission
$perms = explode(',', $tokenUser['permissions']);
if (!in_array('upload', $perms, true)) {
    respondError(403, 'Token does not have upload permission');
}

$uploadsDir = __DIR__ . '/../uploads';
if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0700, true) && !is_dir($uploadsDir)) {
    respondError(500, 'Failed to initialize uploads directory');
}

$isChunked = isset($_POST['chunked']) && (string) $_POST['chunked'] === '1';
if ($isChunked) {
    handleChunkedUpload($db, $tokenUser, $uploadsDir);
    exit;
}

if (empty($_FILES['file'])) {
    respondError(400, 'No file provided');
}

$file = $_FILES['file'];
$validationError = Security::validateFile($file);
if ($validationError) {
    respondError(400, $validationError);
}

$originalName = basename((string) ($file['name'] ?? ''));
if ($originalName === '') {
    respondError(400, 'Invalid filename');
}

$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$hash = hash_file('sha256', $file['tmp_name']);
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
$size = (int) ($file['size'] ?? 0);

$result = upsertFileRecord(
    $db,
    $tokenUser,
    $uploadsDir,
    $originalName,
    $file['tmp_name'],
    true,
    $mime,
    $hash,
    $size,
    $ext
);

http_response_code($result['status']);
echo json_encode($result['body']);
exit;

function handleChunkedUpload(PDO $db, array $tokenUser, string $uploadsDir): void
{
    $uploadId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['upload_id'] ?? ''));
    $originalName = basename((string) ($_POST['file_name'] ?? ''));
    $chunkIndex = (int) ($_POST['chunk_index'] ?? -1);
    $chunkCount = (int) ($_POST['chunk_count'] ?? 0);
    $totalSize = (int) ($_POST['total_size'] ?? 0);
    $expectedChunkHash = strtolower(trim((string) ($_POST['chunk_sha256'] ?? '')));

    if ($uploadId === '' || strlen($uploadId) < 8) {
        respondError(400, 'Invalid chunk upload_id');
    }
    if ($originalName === '') {
        respondError(400, 'Invalid chunk file_name');
    }
    if ($chunkCount < 1 || $chunkCount > 100000) {
        respondError(400, 'Invalid chunk_count');
    }
    if ($chunkIndex < 0 || $chunkIndex >= $chunkCount) {
        respondError(400, 'Invalid chunk_index');
    }
    if ($totalSize < 1) {
        respondError(400, 'Invalid total_size');
    }
    if (empty($_FILES['file'])) {
        respondError(400, 'Missing chunk payload');
    }

    $chunkFile = $_FILES['file'];
    if (($chunkFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respondError(400, 'Chunk upload failed with error code: ' . (string) $chunkFile['error']);
    }

    $chunkTmp = (string) ($chunkFile['tmp_name'] ?? '');
    if ($chunkTmp === '' || !is_file($chunkTmp)) {
        respondError(400, 'Invalid chunk temporary file');
    }

    if ($expectedChunkHash !== '') {
        $actualChunkHash = strtolower((string) hash_file('sha256', $chunkTmp));
        if ($actualChunkHash !== $expectedChunkHash) {
            respondError(400, 'Chunk hash mismatch', [
                'code' => 'chunk_hash_mismatch',
                'chunk_index' => $chunkIndex,
            ]);
        }
    }

    $chunkRoot = $uploadsDir . '/.chunks';
    if (!is_dir($chunkRoot) && !mkdir($chunkRoot, 0700, true) && !is_dir($chunkRoot)) {
        respondError(500, 'Failed to initialize chunk temp directory');
    }

    $uploadDir = $chunkRoot . '/' . $uploadId;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0700, true) && !is_dir($uploadDir)) {
        respondError(500, 'Failed to initialize chunk upload folder');
    }

    $metaPath = $uploadDir . '/meta.json';
    if (is_file($metaPath)) {
        $metaRaw = file_get_contents($metaPath);
        $meta = json_decode((string) $metaRaw, true);
        if (!is_array($meta)) {
            rrmdir($uploadDir);
            respondError(400, 'Corrupted chunk metadata. Restart upload.');
        }
        if (
            ($meta['file_name'] ?? '') !== $originalName ||
            (int) ($meta['chunk_count'] ?? 0) !== $chunkCount ||
            (int) ($meta['total_size'] ?? 0) !== $totalSize
        ) {
            rrmdir($uploadDir);
            respondError(400, 'Chunk metadata mismatch. Restart upload.');
        }
    } else {
        $meta = [
            'file_name' => $originalName,
            'chunk_count' => $chunkCount,
            'total_size' => $totalSize,
            'created_at' => date('c'),
        ];
        file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES));
    }

    $chunkDest = sprintf('%s/chunk_%06d.part', $uploadDir, $chunkIndex);
    if (!move_uploaded_file($chunkTmp, $chunkDest)) {
        respondError(500, 'Failed to store chunk ' . $chunkIndex);
    }

    $receivedCount = count(glob($uploadDir . '/chunk_*.part') ?: []);
    if ($receivedCount < $chunkCount) {
        echo json_encode([
            'success' => true,
            'code' => 'chunk_received',
            'data' => [
                'upload_id' => $uploadId,
                'file_name' => $originalName,
                'chunk_index' => $chunkIndex,
                'chunk_count' => $chunkCount,
                'received_chunks' => $receivedCount,
                'complete' => false,
            ],
        ]);
        return;
    }

    $assembledTmp = tempnam(sys_get_temp_dir(), 'cdn_chunked_');
    if ($assembledTmp === false) {
        rrmdir($uploadDir);
        respondError(500, 'Failed to allocate assembly temp file');
    }

    $assembledOk = false;
    $out = fopen($assembledTmp, 'wb');
    if ($out === false) {
        @unlink($assembledTmp);
        rrmdir($uploadDir);
        respondError(500, 'Failed to open assembly temp file');
    }

    for ($i = 0; $i < $chunkCount; $i++) {
        $partPath = sprintf('%s/chunk_%06d.part', $uploadDir, $i);
        if (!is_file($partPath)) {
            fclose($out);
            @unlink($assembledTmp);
            rrmdir($uploadDir);
            respondError(400, 'Missing chunk during finalize: ' . $i);
        }

        $in = fopen($partPath, 'rb');
        if ($in === false) {
            fclose($out);
            @unlink($assembledTmp);
            rrmdir($uploadDir);
            respondError(500, 'Failed to read chunk during finalize: ' . $i);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
    }
    fclose($out);

    $assembledSize = (int) filesize($assembledTmp);
    if ($assembledSize !== $totalSize) {
        @unlink($assembledTmp);
        rrmdir($uploadDir);
        respondError(400, 'Finalized file size mismatch', [
            'code' => 'final_size_mismatch',
            'expected' => $totalSize,
            'actual' => $assembledSize,
        ]);
    }

    $fakeFile = [
        'error' => UPLOAD_ERR_OK,
        'size' => $assembledSize,
        'tmp_name' => $assembledTmp,
        'name' => $originalName,
    ];
    $validationError = Security::validateFile($fakeFile);
    if ($validationError) {
        @unlink($assembledTmp);
        rrmdir($uploadDir);
        respondError(400, $validationError);
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $hash = (string) hash_file('sha256', $assembledTmp);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($assembledTmp) ?: 'application/octet-stream';

    $result = upsertFileRecord(
        $db,
        $tokenUser,
        $uploadsDir,
        $originalName,
        $assembledTmp,
        false,
        $mime,
        $hash,
        $assembledSize,
        $ext
    );

    @unlink($assembledTmp);
    rrmdir($uploadDir);

    $result['body']['chunked'] = true;
    $result['body']['chunks'] = $chunkCount;

    http_response_code($result['status']);
    echo json_encode($result['body']);
}

/**
 * Create/update file record and storage object.
 * Returns ['status' => int, 'body' => array]
 */
function upsertFileRecord(
    PDO $db,
    array $tokenUser,
    string $uploadsDir,
    string $originalName,
    string $sourceTmpPath,
    bool $isUploadedTmp,
    string $mime,
    string $hash,
    int $size,
    string $ext
): array {
    $existingStmt = $db->prepare(
        "SELECT id, stored_name, sha256_hash, version FROM files WHERE original_name = ? LIMIT 1"
    );
    $existingStmt->execute([$originalName]);
    $existingRow = $existingStmt->fetch();

    if ($existingRow && (string) $existingRow['sha256_hash'] === $hash) {
        return [
            'status' => 409,
            'body' => [
                'success' => false,
                'code' => 'already_exists',
                'message' => 'File already exists on the CDN with identical content. No action taken.',
                'data' => [
                    'id' => (int) $existingRow['id'],
                    'original_name' => $originalName,
                    'sha256_hash' => $hash,
                    'size' => $size,
                    'mime_type' => $mime,
                    'version' => (int) $existingRow['version'],
                    'metadata_url' => 'files/metadata/' . urlencode($originalName),
                    'download_url' => 'files/download/' . urlencode($originalName),
                ],
            ],
        ];
    }

    $storedName = Security::generateUUID() . ($ext ? '.' . $ext : '');
    $dest = $uploadsDir . '/' . $storedName;
    if (!storeArtifact($sourceTmpPath, $dest, $isUploadedTmp)) {
        return [
            'status' => 500,
            'body' => ['success' => false, 'error' => 'Failed to store file'],
        ];
    }

    if ($existingRow) {
        $oldPath = $uploadsDir . '/' . $existingRow['stored_name'];
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }

        $newVersion = (int) $existingRow['version'] + 1;
        $db->prepare(
            "UPDATE files
             SET stored_name  = ?,
                 mime_type    = ?,
                 size         = ?,
                 sha256_hash  = ?,
                 extension    = ?,
                 uploaded_by  = ?,
                 token_id     = ?,
                 version      = ?,
                 updated_at   = datetime('now')
             WHERE id = ?"
        )->execute([
                    $storedName,
                    $mime,
                    $size,
                    $hash,
                    $ext,
                    $tokenUser['id'],
                    $tokenUser['token_id'],
                    $newVersion,
                    (int) $existingRow['id'],
                ]);

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'code' => 'updated',
                'message' => 'Content changed – updated to version ' . $newVersion . '.',
                'data' => [
                    'id' => (int) $existingRow['id'],
                    'original_name' => $originalName,
                    'sha256_hash' => $hash,
                    'size' => $size,
                    'mime_type' => $mime,
                    'version' => $newVersion,
                    'metadata_url' => 'files/metadata/' . urlencode($originalName),
                    'download_url' => 'files/download/' . urlencode($originalName),
                ],
            ],
        ];
    }

    $db->prepare(
        "INSERT INTO files
            (original_name, stored_name, mime_type, size, sha256_hash, extension, uploaded_by, token_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
                $originalName,
                $storedName,
                $mime,
                $size,
                $hash,
                $ext,
                $tokenUser['id'],
                $tokenUser['token_id'],
            ]);

    $fileId = $db->lastInsertId();
    return [
        'status' => 201,
        'body' => [
            'success' => true,
            'code' => 'created',
            'data' => [
                'id' => (int) $fileId,
                'original_name' => $originalName,
                'sha256_hash' => $hash,
                'size' => $size,
                'mime_type' => $mime,
                'version' => 1,
                'metadata_url' => 'files/metadata/' . urlencode($originalName),
                'download_url' => 'files/download/' . urlencode($originalName),
            ],
        ],
    ];
}

function storeArtifact(string $sourceTmpPath, string $destPath, bool $isUploadedTmp): bool
{
    if ($isUploadedTmp) {
        return move_uploaded_file($sourceTmpPath, $destPath);
    }

    if (@rename($sourceTmpPath, $destPath)) {
        return true;
    }

    $in = @fopen($sourceTmpPath, 'rb');
    if ($in === false) {
        return false;
    }
    $out = @fopen($destPath, 'wb');
    if ($out === false) {
        fclose($in);
        return false;
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    @unlink($sourceTmpPath);
    return is_file($destPath);
}

function respondError(int $status, string $error, array $extra = []): void
{
    http_response_code($status);
    $payload = array_merge(['success' => false, 'error' => $error], $extra);
    echo json_encode($payload);
    exit;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
