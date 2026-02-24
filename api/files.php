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

            // Generate secure stored name
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $storedName = Security::generateUUID() . ($ext ? '.' . $ext : '');

            // Compute hash
            $hash = hash_file('sha256', $file['tmp_name']);

            // Detect real MIME
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            // Move file
            $dest = $uploadsDir . '/' . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to store file']);
                exit;
            }

            // Insert into DB
            $stmt = $db->prepare("INSERT INTO files (original_name, stored_name, mime_type, size, sha256_hash, extension, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $file['name'],
                $storedName,
                $mime,
                $file['size'],
                $hash,
                $ext,
                $user['id'],
            ]);

            $fileId = $db->lastInsertId();
            $uploaded[] = [
                'id' => (int) $fileId,
                'original_name' => $file['name'],
                'stored_name' => $storedName,
                'mime_type' => $mime,
                'size' => $file['size'],
                'sha256_hash' => $hash,
                'extension' => $ext,
                'version' => 1,
                'metadata_url' => '/files/metadata/' . $file['name'],
                'download_url' => '/files/download/' . $file['name'],
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
        $listSql = "SELECT f.*, u.username as uploader FROM files f LEFT JOIN users u ON f.uploaded_by = u.id";

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

        echo json_encode([
            'success' => true,
            'data' => [
                'files' => $files,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int) ceil($total / $perPage),
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
            $stmt = $db->prepare("SELECT f.*, u.username as uploader FROM files f LEFT JOIN users u ON f.uploaded_by = u.id WHERE f.id = ?");
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
                unlink($path);
            }

            // Delete from DB
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
            $stmt = $db->prepare("SELECT f.*, u.username as uploader FROM files f LEFT JOIN users u ON f.uploaded_by = u.id WHERE f.id = ?");
            $stmt->execute([$fileId]);
            $updated = $stmt->fetch();

            echo json_encode(['success' => true, 'data' => $updated]);

        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        }
        break;
}
