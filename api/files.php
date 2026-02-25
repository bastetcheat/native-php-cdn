<?php
/**
 * Files API – list, upload, update, delete, get single
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $segments[1] ?? '';

// All file management requires session auth
$user = Security::requireAuth();
if ($method !== 'GET') {
    Security::requireCsrf();
}

$uploadsDir = __DIR__ . '/../uploads';

switch (true) {
    // ─── Upload file(s) ───
    case ($action === 'upload' && $method === 'POST'):
        if (empty($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No file provided']);
            exit;
        }

        $files = $_FILES['file'];
        $uploaded = [];

        // Normalize single file to array format
        if (!is_array($files['name'])) {
            $files = [
                'name' => [$files['name']],
                'tmp_name' => [$files['tmp_name']],
                'error' => [$files['error']],
                'size' => [$files['size']],
                'type' => [$files['type']],
            ];
        }

        for ($i = 0; $i < count($files['name']); $i++) {
            $file = [
                'name' => $files['name'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i],
                'type' => $files['type'][$i],
            ];

            $validationError = Security::validateFile($file);
            if ($validationError) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $validationError . ' (' . Security::e($file['name']) . ')']);
                exit;
            }

            // Generate secure stored name (may be overridden for version update)
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $hash = hash_file('sha256', $file['tmp_name']);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            // ── Duplicate / version detection ────────────────────────────
            $exStmt = $db->prepare(
                "SELECT id, stored_name, sha256_hash, version FROM files WHERE original_name = ? LIMIT 1"
            );
            $exStmt->execute([$file['name']]);
            $exRow = $exStmt->fetch();

            if ($exRow && $exRow['sha256_hash'] === $hash) {
                // Exact duplicate – discard temp file, return existing info
                $uploaded[] = [
                    'id' => (int) $exRow['id'],
                    'original_name' => $file['name'],
                    'sha256_hash' => $hash,
                    'size' => $file['size'],
                    'mime_type' => $mime,
                    'extension' => $ext,
                    'version' => (int) $exRow['version'],
                    'status' => 'already_exists',
                    'metadata_url' => '/files/metadata/' . urlencode($file['name']),
                    'download_url' => '/files/download/' . urlencode($file['name']),
                ];
                continue; // skip move + insert
            }

            // Move to uploads
            $storedName = Security::generateUUID() . ($ext ? '.' . $ext : '');
            $dest = $uploadsDir . '/' . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to store file']);
                exit;
            }

            if ($exRow) {
                // Same name, different hash → version update
                $oldPath = $uploadsDir . '/' . $exRow['stored_name'];
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
                $newVersion = (int) $exRow['version'] + 1;
                $db->prepare(
                    "UPDATE files
                     SET stored_name = ?, mime_type = ?, size = ?, sha256_hash = ?,
                         extension = ?, uploaded_by = ?, version = ?, updated_at = datetime('now')
                     WHERE id = ?"
                )->execute([$storedName, $mime, $file['size'], $hash, $ext, $user['id'], $newVersion, (int) $exRow['id']]);

                $fileId = (int) $exRow['id'];
                $status = 'updated';
            } else {
                // New file
                $newVersion = 1;
                $db->prepare(
                    "INSERT INTO files (original_name, stored_name, mime_type, size, sha256_hash, extension, uploaded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                )->execute([$file['name'], $storedName, $mime, $file['size'], $hash, $ext, $user['id']]);

                $fileId = (int) $db->lastInsertId();
                $status = 'created';
            }

            $uploaded[] = [
                'id' => $fileId,
                'original_name' => $file['name'],
                'stored_name' => $storedName,
                'mime_type' => $mime,
                'size' => $file['size'],
                'sha256_hash' => $hash,
                'extension' => $ext,
                'version' => $newVersion,
                'status' => $status,
                'metadata_url' => '/files/metadata/' . urlencode($file['name']),
                'download_url' => '/files/download/' . urlencode($file['name']),
            ];
        }

        echo json_encode(['success' => true, 'data' => $uploaded]);
        break;

    // ─── List all files ───
    case ($action === '' && $method === 'GET'):
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
        $search = Security::sanitize($_GET['search'] ?? '');
        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) as total FROM files";
        $listSql = "SELECT f.*, u.username as uploader, t.name as token_name 
                    FROM files f 
                    LEFT JOIN users u ON f.uploaded_by = u.id 
                    LEFT JOIN oauth_tokens t ON f.token_id = t.id";

        if ($search) {
            $where = " WHERE f.original_name LIKE ?";
            $countSql .= str_replace('f.', '', $where);
            $listSql .= $where;
            $listSql .= " ORDER BY f.created_at DESC LIMIT ? OFFSET ?";

            $countStmt = $db->prepare($countSql);
            $countStmt->execute(["%$search%"]);
            $total = (int) $countStmt->fetch()['total'];

            $listStmt = $db->prepare($listSql);
            $listStmt->execute(["%$search%", $perPage, $offset]);
        } else {
            $countStmt = $db->query($countSql);
            $total = (int) $countStmt->fetch()['total'];

            $listSql .= " ORDER BY f.created_at DESC LIMIT ? OFFSET ?";
            $listStmt = $db->prepare($listSql);
            $listStmt->execute([$perPage, $offset]);
        }

        $files = $listStmt->fetchAll();

        // Global stats for dashboard (always the absolute totals)
        $globalStats = $db->query("SELECT COUNT(*) as total, COALESCE(SUM(size), 0) as total_size, COALESCE(SUM(download_count), 0) as total_downloads FROM files")->fetch();

        echo json_encode([
            'success' => true,
            'data' => [
                'files' => $files,
                'total' => $total, // Total matching the search filter
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int) ceil($total / $perPage),
                'stats' => [ // Global stats for dashboard cards
                    'total_files' => (int) $globalStats['total'],
                    'total_size' => (int) $globalStats['total_size'],
                    'total_downloads' => (int) $globalStats['total_downloads'],
                ]
            ],
        ]);
        break;

    // ─── Single file / Delete / Update by ID ───
    default:
        $fileId = (int) ($segments[1] ?? 0);
        if ($fileId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid file ID']);
            exit;
        }

        if ($method === 'GET') {
            $stmt = $db->prepare("SELECT f.*, u.username as uploader, t.name as token_name 
                                 FROM files f 
                                 LEFT JOIN users u ON f.uploaded_by = u.id 
                                 LEFT JOIN oauth_tokens t ON f.token_id = t.id 
                                 WHERE f.id = ?");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch();

            if (!$file) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'File not found']);
                exit;
            }

            echo json_encode(['success' => true, 'data' => $file]);

        } elseif ($method === 'DELETE') {
            // Find file first
            $stmt = $db->prepare("SELECT stored_name FROM files WHERE id = ?");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch();

            if (!$file) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'File not found']);
                exit;
            }

            // Delete from disk
            $path = $uploadsDir . '/' . $file['stored_name'];
            if (file_exists($path)) {
                if (!@unlink($path)) {
                    // On Windows, a file being downloaded can't be deleted.
                    // Report the error clearly rather than silently leaving an orphan.
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Could not delete file from disk (it may be in use or permissions are missing). '
                            . 'The database record was NOT removed – try again in a moment.',
                    ]);
                    exit;
                }
            }

            // Delete from DB only after disk deletion succeeds
            $del = $db->prepare("DELETE FROM files WHERE id = ?");
            $del->execute([$fileId]);

            echo json_encode(['success' => true, 'data' => ['message' => 'File deleted']]);

        } elseif ($method === 'POST' || $method === 'PUT') {
            // Re-upload / update file
            $stmt = $db->prepare("SELECT * FROM files WHERE id = ?");
            $stmt->execute([$fileId]);
            $existing = $stmt->fetch();

            if (!$existing) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'File not found']);
                exit;
            }

            if (empty($_FILES['file'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No file provided for update']);
                exit;
            }

            $file = $_FILES['file'];
            $validationError = Security::validateFile($file);
            if ($validationError) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $validationError]);
                exit;
            }

            // Remove old file from disk
            $oldPath = $uploadsDir . '/' . $existing['stored_name'];
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }

            // Generate new stored name
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $newStoredName = Security::generateUUID() . ($ext ? '.' . $ext : '');
            $hash = hash_file('sha256', $file['tmp_name']);

            // ── Smart versioning: reject identical files ──────────────────────
            if (hash_equals($existing['sha256_hash'], $hash)) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'same_version' => true,
                    'error' => 'This file is identical to the current version (same SHA-256 hash). No update needed.',
                    'current_version' => (int) $existing['version'],
                    'sha256_hash' => $existing['sha256_hash'],
                ]);
                exit;
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            $dest = $uploadsDir . '/' . $newStoredName;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to store updated file']);
                exit;
            }

            $newVersion = (int) $existing['version'] + 1;
            $update = $db->prepare("UPDATE files SET original_name = ?, stored_name = ?, mime_type = ?, size = ?, sha256_hash = ?, extension = ?, version = ?, updated_at = datetime('now') WHERE id = ?");
            $update->execute([
                $file['name'],
                $newStoredName,
                $mime,
                $file['size'],
                $hash,
                $ext,
                $newVersion,
                $fileId,
            ]);

            // Fetch updated record
            $stmt = $db->prepare("SELECT f.*, u.username as uploader, t.name as token_name 
                                 FROM files f 
                                 LEFT JOIN users u ON f.uploaded_by = u.id 
                                 LEFT JOIN oauth_tokens t ON f.token_id = t.id 
                                 WHERE f.id = ?");
            $stmt->execute([$fileId]);
            $updated = $stmt->fetch();

            echo json_encode(['success' => true, 'data' => $updated]);

        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        }
        break;
}
