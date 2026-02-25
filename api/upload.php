<?php
/**
 * OAuth Upload Endpoint – for external agents/AI
 * Authenticates via Bearer token only (no session needed)
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// Bearer-token endpoint – no session data needed at all.
// Release the session lock immediately so parallel browser requests aren't blocked
// for the entire duration of a potentially large file upload.
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
$storedName = Security::generateUUID() . ($ext ? '.' . $ext : '');
$hash = hash_file('sha256', $file['tmp_name']);

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

$dest = $uploadsDir . '/' . $storedName;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to store file']);
    exit;
}

$stmt = $db->prepare("INSERT INTO files (original_name, stored_name, mime_type, size, sha256_hash, extension, uploaded_by, token_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
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

// Return relative URLs (portable across any domain/path)
echo json_encode([
    'success' => true,
    'data' => [
        'id' => (int) $fileId,
        'original_name' => $file['name'],
        'sha256_hash' => $hash,
        'size' => $file['size'],
        'mime_type' => $mime,
        'metadata_url' => 'files/metadata/' . urlencode($file['name']),
        'download_url' => 'files/download/' . urlencode($file['name']),
    ]
]);
