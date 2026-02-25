<?php
/**
 * OAuth Upload Endpoint – for external agents/AI
 * Authenticates via Bearer token only (no session needed)
 *
 * Duplicate handling:
 *   same name + same hash  → 409 already_exists   (nothing changed, no new row)
 *   same name + diff  hash → 200 updated           (version bumped, old file deleted)
 *   new name               → 201 created
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// Release session lock – large uploads must not block the browser
session_write_close();

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Authenticate via Bearer token
$tokenUser = Security::validateBearerToken($db);
if (!$tokenUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired token']);
    exit;
}

// Check upload permission
$perms = explode(',', $tokenUser['permissions']);
if (!in_array('upload', $perms, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token does not have upload permission']);
    exit;
}

if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file provided']);
    exit;
}

$uploadsDir = __DIR__ . '/../uploads';
$file = $_FILES['file'];

$validationError = Security::validateFile($file);
if ($validationError) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $validationError]);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$hash = hash_file('sha256', $file['tmp_name']);

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

// ── Duplicate / version detection ─────────────────────────────────────────
$existingStmt = $db->prepare(
    "SELECT id, stored_name, sha256_hash, version FROM files WHERE original_name = ? LIMIT 1"
);
$existingStmt->execute([$file['name']]);
$existingRow = $existingStmt->fetch();

if ($existingRow) {

    // ── Case 1: Exact duplicate (same name + same hash) ───────────────────
    if ($existingRow['sha256_hash'] === $hash) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'code' => 'already_exists',
            'message' => 'File already exists on the CDN with identical content. No action taken.',
            'data' => [
                'id' => (int) $existingRow['id'],
                'original_name' => $file['name'],
                'sha256_hash' => $hash,
                'size' => $file['size'],
                'mime_type' => $mime,
                'version' => (int) $existingRow['version'],
                'metadata_url' => 'files/metadata/' . urlencode($file['name']),
                'download_url' => 'files/download/' . urlencode($file['name']),
            ],
        ]);
        exit;
    }

    // ── Case 2: Same name, different content → version update ─────────────
    $storedName = Security::generateUUID() . ($ext ? '.' . $ext : '');
    $dest = $uploadsDir . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to store file']);
        exit;
    }

    // Remove old physical file
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
                $file['size'],
                $hash,
                $ext,
                $tokenUser['id'],
                $tokenUser['token_id'],
                $newVersion,
                (int) $existingRow['id'],
            ]);

    echo json_encode([
        'success' => true,
        'code' => 'updated',
        'message' => 'Content changed – updated to version ' . $newVersion . '.',
        'data' => [
            'id' => (int) $existingRow['id'],
            'original_name' => $file['name'],
            'sha256_hash' => $hash,
            'size' => $file['size'],
            'mime_type' => $mime,
            'version' => $newVersion,
            'metadata_url' => 'files/metadata/' . urlencode($file['name']),
            'download_url' => 'files/download/' . urlencode($file['name']),
        ],
    ]);
    exit;
}

// ── Case 3: Brand-new file ────────────────────────────────────────────────
$storedName = Security::generateUUID() . ($ext ? '.' . $ext : '');
$dest = $uploadsDir . '/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to store file']);
    exit;
}

$db->prepare(
    "INSERT INTO files
        (original_name, stored_name, mime_type, size, sha256_hash, extension, uploaded_by, token_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
)->execute([
            $file['name'],
            $storedName,
            $mime,
            $file['size'],
            $hash,
            $ext,
            $tokenUser['id'],
            $tokenUser['token_id'],
        ]);

$fileId = $db->lastInsertId();

http_response_code(201);
echo json_encode([
    'success' => true,
    'code' => 'created',
    'data' => [
        'id' => (int) $fileId,
        'original_name' => $file['name'],
        'sha256_hash' => $hash,
        'size' => $file['size'],
        'mime_type' => $mime,
        'version' => 1,
        'metadata_url' => 'files/metadata/' . urlencode($file['name']),
        'download_url' => 'files/download/' . urlencode($file['name']),
    ],
]);
