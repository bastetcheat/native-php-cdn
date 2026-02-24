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

        // 1. Persist to DB (used by Security::validateFile)
        Database::setSetting('max_upload_mb', (string) $mb);

        // 2. Sync .htaccess php_value directives so Apache honours the new limit immediately
        $htPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.htaccess';
        $htUpdated = false;
        $htError = null;

        if (is_readable($htPath)) {
            $htContent = @file_get_contents($htPath) ?: '';
            $postMax = $mb + 50; // post_max_size must be > upload_max_filesize

            $hasUploadLimit = preg_match('/^php_value\s+upload_max_filesize/mi', $htContent);
            $hasPostLimit = preg_match('/^php_value\s+post_max_size/mi', $htContent);

            if ($hasUploadLimit) {
                $htContent = preg_replace(
                    '/^(php_value\s+upload_max_filesize\s+)\S+/mi',
                    '${1}' . $mb . 'M',
                    $htContent
                );
            }
            if ($hasPostLimit) {
                $htContent = preg_replace(
                    '/^(php_value\s+post_max_size\s+)\S+/mi',
                    '${1}' . $postMax . 'M',
                    $htContent
                );
            }

            // If missing, append a clean block
            if (!$hasUploadLimit && !$hasPostLimit) {
                $htContent .= "\n\n# ─── PHP upload limits (synced from Admin Panel) ───\n";
                $htContent .= "php_value upload_max_filesize " . $mb . "M\n";
                $htContent .= "php_value post_max_size        " . $postMax . "M\n";
                $htContent .= "php_value max_execution_time   600\n";
                $htContent .= "php_value max_input_time       600\n";
            }

            if (is_writable($htPath)) {
                $tmp = $htPath . '.tmp.' . getmypid();
                if (@file_put_contents($tmp, $htContent, LOCK_EX) !== false) {
                    if (@rename($tmp, $htPath)) {
                        $htUpdated = true;
                    } else {
                        @unlink($tmp);
                        $htError = 'Could not rename temp file to .htaccess';
                    }
                } else {
                    $htError = 'Could not write temp file next to .htaccess';
                }
            } else {
                $htError = '.htaccess is not writable – run Apache as Administrator or grant write permission';
            }
        } else {
            $htError = '.htaccess not found at expected path: ' . $htPath;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'max_upload_mb' => $mb,
                'htaccess_updated' => $htUpdated,
                'htaccess_error' => $htError,
                'restart_required' => true,
                'message' => 'Upload limit updated to ' . $mb . ' MB'
                    . ($htUpdated ? ' and .htaccess synced.' : '. WARNING: .htaccess was NOT updated – ' . $htError),
            ]
        ]);
        break;

    // ── POST /api/settings/restart-apache ────────────────────────────────────
    case 'restart-apache':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        if (!function_exists('exec')) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'exec() is disabled on this server (PHP disable_functions). Restart Apache manually via the XAMPP Control Panel.',
            ]);
            exit;
        }

        $output = [];
        $code = -1;

        // Strategy 1: httpd.exe -k graceful (found relative to PHP binary → XAMPP)
        // PHP_BINARY = C:\xampp\php\php.exe → xamppDir = C:\xampp
        $phpDir = dirname(PHP_BINARY);
        $xamppDir = dirname($phpDir);
        $httpd = $xamppDir . DIRECTORY_SEPARATOR . 'apache' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'httpd.exe';

        if (file_exists($httpd)) {
            // -k graceful finishes in-flight requests then reloads config
            exec('"' . $httpd . '" -k graceful 2>&1', $output, $code);
        } else {
            // Strategy 2: Windows service via net stop / net start
            exec('net stop "Apache2.4" 2>&1', $o1);
            sleep(1);
            exec('net start "Apache2.4" 2>&1', $o2, $code);
            $output = array_merge($o1, $o2);

            if ($code !== 0) {
                // Last attempt: plain "Apache"
                exec('net stop "Apache" 2>&1', $o3);
                sleep(1);
                exec('net start "Apache" 2>&1', $o4, $code);
                $output = array_merge($output, $o3, $o4);
            }
        }

        if ($code === 0) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => 'Apache restart signal sent successfully.',
                    'output' => implode("\n", $output),
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Apache restart command failed (exit code ' . $code . ').',
                'output' => implode("\n", $output),
                'hint' => 'Run XAMPP Control Panel as Administrator and click Restart on Apache, or: open an Admin CMD and run: net stop Apache2.4 && net start Apache2.4',
            ]);
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Settings endpoint not found']);
        break;
}
