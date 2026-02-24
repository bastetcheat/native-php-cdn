<?php
/**
 * Settings API – change password, change username
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $segments[1] ?? '';

// All settings endpoints require auth + CSRF
$user = Security::requireAuth();
if ($method !== 'GET') {
    Security::requireCsrf();
}

switch ($action) {
    case 'password':
        if ($method !== 'PUT') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $newPassword = $input['new_password'] ?? '';

        if (empty($newPassword)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'New password is required']);
            exit;
        }

        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_pw = 0, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$hash, $user['id']]);

        echo json_encode(['success' => true, 'data' => ['message' => 'Password updated successfully']]);
        break;

    case 'username':
        if ($method !== 'PUT') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $newUsername = Security::sanitize($input['new_username'] ?? '');

        if (empty($newUsername)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'New username is required']);
            exit;
        }

        if (strlen($newUsername) < 3 || strlen($newUsername) > 50) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Username must be between 3 and 50 characters']);
            exit;
        }

        // Check uniqueness
        $check = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->execute([$newUsername, $user['id']]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Username already taken']);
            exit;
        }

        $stmt = $db->prepare("UPDATE users SET username = ?, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$newUsername, $user['id']]);

        // Update session
        $_SESSION['username'] = $newUsername;

        echo json_encode(['success' => true, 'data' => ['message' => 'Username updated successfully', 'username' => $newUsername]]);
        break;

    case 'upload':
        if ($method === 'GET') {
            $mb = (int) Database::getSetting('max_upload_mb', '700');
            echo json_encode(['success' => true, 'data' => ['max_upload_mb' => $mb]]);
            break;
        }

        if ($method !== 'PUT') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $mb = isset($input['max_upload_mb']) ? (int) $input['max_upload_mb'] : 0;

        if ($mb < 1 || $mb > 10000) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'max_upload_mb must be between 1 and 10000']);
            exit;
        }

        Database::setSetting('max_upload_mb', (string) $mb);

        echo json_encode([
            'success' => true,
            'data' => [
                'max_upload_mb' => $mb,
                'message' => 'Upload limit updated to ' . $mb . ' MB',
            ]
        ]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Settings endpoint not found']);
        break;
}
