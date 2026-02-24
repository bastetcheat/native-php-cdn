<?php
/**
 * Auth API – login, logout, me
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $segments[1] ?? '';

switch ($action) {
    case 'login':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $username = Security::sanitize($input['username'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($username) || empty($password)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Username and password are required']);
            exit;
        }

        $ip = Security::getClientIp();

        // Rate limit check
        if (!Security::checkRateLimit($db, $ip)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many login attempts. Please try again later.']);
            exit;
        }

        $stmt = $db->prepare("SELECT id, username, password_hash, must_change_pw FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Security::recordFailedLogin($db, $ip);
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
            exit;
        }

        // Clear failed attempts on success
        Security::clearLoginAttempts($db, $ip);

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        // Generate CSRF token
        $csrf = Security::generateCsrfToken();

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'must_change_pw' => (bool) $user['must_change_pw'],
                'csrf_token' => $csrf,
            ]
        ]);
        break;

    case 'logout':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        session_destroy();
        echo json_encode(['success' => true, 'data' => null]);
        break;

    case 'me':
        if ($method !== 'GET') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Not authenticated']);
            exit;
        }
        $stmt = $db->prepare("SELECT id, username, must_change_pw, created_at, updated_at FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'must_change_pw' => (bool) $user['must_change_pw'],
                'csrf_token' => Security::generateCsrfToken(),
                'created_at' => $user['created_at'],
                'updated_at' => $user['updated_at'],
            ]
        ]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Auth endpoint not found']);
        break;
}
