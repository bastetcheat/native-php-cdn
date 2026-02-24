<?php
/**
 * OAuth Tokens API – list, create, revoke, activate, delete
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// All token endpoints require session auth
$user = Security::requireAuth();
if ($method !== 'GET') {
    Security::requireCsrf();
}

$tokenId = isset($segments[1]) && is_numeric($segments[1]) ? (int) $segments[1] : 0;
$subAction = $segments[2] ?? '';

switch (true) {
    // ─── List all tokens ───
    case ($tokenId === 0 && $method === 'GET'):
        $stmt = $db->prepare("SELECT id, name, token_prefix, permissions, expires_at, revoked, last_used_at, created_at FROM oauth_tokens WHERE created_by = ? ORDER BY created_at DESC");
        $stmt->execute([$user['id']]);
        $tokens = $stmt->fetchAll();

        // Add computed status
        foreach ($tokens as &$t) {
            if ($t['revoked']) {
                $t['status'] = 'revoked';
            } elseif ($t['expires_at'] && strtotime($t['expires_at']) < time()) {
                $t['status'] = 'expired';
            } else {
                $t['status'] = 'active';
            }
        }

        echo json_encode(['success' => true, 'data' => $tokens]);
        break;

    // ─── Create token ───
    case ($tokenId === 0 && $method === 'POST'):
        $input = json_decode(file_get_contents('php://input'), true);
        $name = Security::sanitize($input['name'] ?? '');
        $permissions = Security::sanitize($input['permissions'] ?? 'upload,download,metadata');
        $expiresAt = $input['expires_at'] ?? null;

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Token name is required']);
            exit;
        }

        if (strlen($name) > 100) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Token name too long (max 100 chars)']);
            exit;
        }

        // Validate permissions
        $validPerms = ['upload', 'download', 'metadata'];
        $permArray = array_map('trim', explode(',', $permissions));
        foreach ($permArray as $p) {
            if (!in_array($p, $validPerms, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Invalid permission: $p. Allowed: " . implode(', ', $validPerms)]);
                exit;
            }
        }
        $permissions = implode(',', $permArray);

        // Validate expiry date if provided
        if ($expiresAt) {
            $expTime = strtotime($expiresAt);
            if (!$expTime || $expTime < time()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Expiration date must be in the future']);
                exit;
            }
            $expiresAt = date('Y-m-d H:i:s', $expTime);
        }

        // Generate token
        $rawToken = 'cdn_' . bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $tokenPrefix = substr($rawToken, 0, 12) . '...';

        $stmt = $db->prepare("INSERT INTO oauth_tokens (name, token_hash, token_prefix, permissions, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $tokenHash, $tokenPrefix, $permissions, $expiresAt, $user['id']]);

        $newId = $db->lastInsertId();

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => (int) $newId,
                'name' => $name,
                'token' => $rawToken,  // Shown ONCE only!
                'token_prefix' => $tokenPrefix,
                'permissions' => $permissions,
                'expires_at' => $expiresAt,
                'message' => 'Save this token now! It will not be shown again.',
            ]
        ]);
        break;

    // ─── Token-specific actions ───
    default:
        if ($tokenId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid token ID']);
            exit;
        }

        // Verify ownership
        $stmt = $db->prepare("SELECT * FROM oauth_tokens WHERE id = ? AND created_by = ?");
        $stmt->execute([$tokenId, $user['id']]);
        $token = $stmt->fetch();

        if (!$token) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Token not found']);
            exit;
        }

        if ($method === 'DELETE') {
            $del = $db->prepare("DELETE FROM oauth_tokens WHERE id = ? AND created_by = ?");
            $del->execute([$tokenId, $user['id']]);
            echo json_encode(['success' => true, 'data' => ['message' => 'Token deleted permanently']]);

        } elseif ($method === 'PUT' && $subAction === 'revoke') {
            $upd = $db->prepare("UPDATE oauth_tokens SET revoked = 1 WHERE id = ? AND created_by = ?");
            $upd->execute([$tokenId, $user['id']]);
            echo json_encode(['success' => true, 'data' => ['message' => 'Token revoked']]);

        } elseif ($method === 'PUT' && $subAction === 'activate') {
            $upd = $db->prepare("UPDATE oauth_tokens SET revoked = 0 WHERE id = ? AND created_by = ?");
            $upd->execute([$tokenId, $user['id']]);
            echo json_encode(['success' => true, 'data' => ['message' => 'Token re-activated']]);

        } elseif ($method === 'GET') {
            // Add computed status
            if ($token['revoked']) {
                $token['status'] = 'revoked';
            } elseif ($token['expires_at'] && strtotime($token['expires_at']) < time()) {
                $token['status'] = 'expired';
            } else {
                $token['status'] = 'active';
            }
            // Don't expose the hash
            unset($token['token_hash']);
            echo json_encode(['success' => true, 'data' => $token]);

        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        }
        break;
}
