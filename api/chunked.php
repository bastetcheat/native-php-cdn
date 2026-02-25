<?php
/**
 * Chunked Upload API
 * Allows bypassing Cloudflare 100MB limit by uploading in small pieces.
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// Auth check
$tokenUser = Security::validateBearerToken($db);
if (!$tokenUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$tempBase = __DIR__ . '/../db/temp_chunks';
if (!is_dir($tempBase))
    mkdir($tempBase, 0777, true);

switch ($action) {
    // 1. Initialize a new upload session
    case 'start':
        $uploadId = Security::generateUUID();
        $sessionPath = $tempBase . '/' . $uploadId;
        mkdir($sessionPath, 0777, true);

        echo json_encode([
            'success' => true,
            'data' => ['upload_id' => $uploadId]
        ]);
        break;

    // 2. Accept a single chunk
    case 'upload':
        $uploadId = $_POST['upload_id'] ?? '';
        $chunkIndex = isset($_POST['chunk_index']) ? (int) $_POST['chunk_index'] : -1;

        if (!$uploadId || $chunkIndex < 0 || empty($_FILES['chunk'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing chunk data']);
            exit;
        }

        $sessionPath = $tempBase . '/' . $uploadId;
        if (!is_dir($sessionPath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Invalid upload_id']);
            exit;
        }

        move_uploaded_file($_FILES['chunk']['tmp_name'], $sessionPath . '/' . $chunkIndex);
        echo json_encode(['success' => true]);
        break;

    // 3. Finalize and merge chunks
    case 'finish':
        $uploadId = $_POST['upload_id'] ?? '';
        $filename = Security::sanitize($_POST['filename'] ?? '');
        $expectedHash = $_POST['sha256'] ?? '';
        $totalChunks = (int) ($_POST['total_chunks'] ?? 0);

        if (!$uploadId || !$filename || $totalChunks <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing finalization data']);
            exit;
        }

        $sessionPath = $tempBase . '/' . $uploadId;
        $finalPath = __DIR__ . '/../uploads/' . Security::generateUUID() . '.' . pathinfo($filename, PATHINFO_EXTENSION);

        // Merge chunks
        $out = fopen($finalPath, 'wb');
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $sessionPath . '/' . $i;
            if (!file_exists($chunkFile)) {
                fclose($out);
                @unlink($finalPath);
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing chunk $i"]);
                exit;
            }
            $in = fopen($chunkFile, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        // Verify Hash
        $actualHash = hash_file('sha256', $finalPath);
        if ($expectedHash && $actualHash !== $expectedHash) {
            @unlink($finalPath);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Hash mismatch', 'expected' => $expectedHash, 'actual' => $actualHash]);
            exit;
        }

        // Logic check: Duplicate or Version update (Reuse logic from upload.php)
        $existing = $db->prepare("SELECT id, stored_name, sha256_hash, version FROM files WHERE original_name = ? LIMIT 1");
        $existing->execute([$filename]);
        $exRow = $existing->fetch();

        $storedName = basename($finalPath);
        $size = filesize($finalPath);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($finalPath);

        if ($exRow) {
            // Check if exact same content
            if ($exRow['sha256_hash'] === $actualHash) {
                @unlink($finalPath);
                echo json_encode(['success' => true, 'code' => 'already_exists', 'data' => ['id' => $exRow['id']]]);
                exit;
            }

            // Delete old physical file
            @unlink(__DIR__ . '/../uploads/' . $exRow['stored_name']);

            $newVersion = (int) $exRow['version'] + 1;
            $db->prepare("UPDATE files SET stored_name=?, mime_type=?, size=?, sha256_hash=?, extension=?, uploaded_by=?, token_id=?, version=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$storedName, $mime, $size, $actualHash, $ext, $tokenUser['id'], $tokenUser['token_id'], $newVersion, $exRow['id']]);
            $fileId = $exRow['id'];
        } else {
            $db->prepare("INSERT INTO files (original_name, stored_name, mime_type, size, sha256_hash, extension, uploaded_by, token_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$filename, $storedName, $mime, $size, $actualHash, $ext, $tokenUser['id'], $tokenUser['token_id']]);
            $fileId = $db->lastInsertId();
            $newVersion = 1;
        }

        // Cleanup chunks
        array_map('unlink', glob("$sessionPath/*"));
        rmdir($sessionPath);

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => (int) $fileId,
                'version' => $newVersion,
                'download_url' => 'files/download/' . urlencode($filename)
            ]
        ]);
        break;
}
