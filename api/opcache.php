<?php
/**
 * OPcache Management API
 *
 * GET  /api/opcache         – status, php.ini path, permission flags, current settings
 * POST /api/opcache/reset   – clears the opcode cache (opcache_reset)
 * POST /api/opcache/config  – writes whitelisted settings to php.ini
 */

$method = $_SERVER['REQUEST_METHOD'];
$action = $segments[1] ?? '';

// Requires an active admin session
$user = Security::requireAuth();
if ($method !== 'GET') {
    Security::requireCsrf();
}

$phpIniPath = php_ini_loaded_file(); // e.g. C:\xampp\php\php.ini or false

// ── Helper: read current values from php.ini ─────────────────────────────────
function readOpcacheSettings(string $path): array
{
    $keys = [
        'opcache.enable',
        'opcache.memory_consumption',
        'opcache.max_accelerated_files',
        'opcache.validate_timestamps',
        'opcache.revalidate_freq',
        'opcache.fast_shutdown',
        'opcache.enable_cli',
    ];

    $result = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        // Skip blank lines and comments
        if ($trimmed === '' || $trimmed[0] === ';') {
            continue;
        }
        foreach ($keys as $k) {
            if (stripos($trimmed, $k . '=') === 0 || stripos($trimmed, $k . ' =') === 0) {
                $parts = explode('=', $trimmed, 2);
                $result[$k] = trim($parts[1] ?? '');
            }
        }
    }

    // Fill missing keys with null so the frontend always has a full object
    foreach ($keys as $k) {
        if (!array_key_exists($k, $result)) {
            $result[$k] = null;
        }
    }

    return $result;
}

// ── Helper: atomically write settings to php.ini ─────────────────────────────
function applyIniSettings(string $path, array $updates): ?string
{
    $content = @file_get_contents($path);
    if ($content === false) {
        return 'Could not read php.ini';
    }

    foreach ($updates as $key => $value) {
        // Match an existing line (may or may not be commented out)
        $pattern = '/^;?\s*' . preg_quote($key, '/') . '\s*=.*$/mi';
        $line = $key . ' = ' . $value;

        if (preg_match($pattern, $content)) {
            // Replace existing (comments it out → uncommented value)
            $content = preg_replace($pattern, $line, $content, 1);
        } else {
            // Key not present – append under [opcache] section if it exists
            if (preg_match('/^\[opcache\]/mi', $content)) {
                $content = preg_replace('/(\[opcache\][^\[]*)/si', '$1' . $line . "\n", $content, 1);
            } else {
                $content .= "\n[opcache]\n" . $line . "\n";
            }
        }
    }

    // Atomic write: write to a temp file then rename so we never corrupt php.ini
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return 'Could not write temporary file next to php.ini. Check directory permissions.';
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return 'Could not replace php.ini (rename failed). Try running Apache as Administrator, '
            . 'or manually grant write permission to: ' . $path;
    }

    return null; // success
}

// ── Routes ────────────────────────────────────────────────────────────────────
switch ($action) {

    // ── GET /api/opcache ─────────────────────────────────────────────────────
    case '':
    case 'status':
        if ($method !== 'GET') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $loaded = extension_loaded('Zend OPcache');
        $status = $loaded ? (@opcache_get_status(false) ?: null) : null;
        $config = $loaded ? (@opcache_get_configuration() ?: null) : null;

        $iniReadable = $phpIniPath && is_readable($phpIniPath);
        $iniWritable = $phpIniPath && is_writable($phpIniPath);

        $settings = $iniReadable ? readOpcacheSettings($phpIniPath) : [];

        echo json_encode([
            'success' => true,
            'data' => [
                'extension_loaded' => $loaded,
                'status' => $status,
                'config' => $config ? ($config['directives'] ?? null) : null,
                'php_ini_path' => $phpIniPath ?: null,
                'php_ini_readable' => $iniReadable,
                'php_ini_writable' => $iniWritable,
                'settings' => $settings,
            ],
        ]);
        break;

    // ── POST /api/opcache/reset ───────────────────────────────────────────────
    case 'reset':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }
        if (!extension_loaded('Zend OPcache')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'OPcache extension is not loaded']);
            exit;
        }
        $result = opcache_reset();
        echo json_encode([
            'success' => true,
            'data' => ['reset' => $result, 'message' => 'OPcache cleared successfully'],
        ]);
        break;

    // ── POST /api/opcache/config ──────────────────────────────────────────────
    case 'config':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        if (!$phpIniPath) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Could not locate the active php.ini file']);
            exit;
        }

        if (!is_readable($phpIniPath)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Cannot read php.ini – no read permission. Path: ' . $phpIniPath,
            ]);
            exit;
        }

        if (!is_writable($phpIniPath)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Cannot write to php.ini – permission denied.',
                'tip' => 'Right-click php.ini → Properties → Security → add write permission for the Apache service user. Or run XAMPP as Administrator. File path: ' . $phpIniPath,
                'ini_path' => $phpIniPath,
            ]);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
            exit;
        }

        // ── Whitelist – only these settings may be changed via the UI ─────────
        $allowed = [
            'opcache.enable' => ['type' => 'bool'],
            'opcache.memory_consumption' => ['type' => 'int', 'min' => 8, 'max' => 2048],
            'opcache.max_accelerated_files' => ['type' => 'int', 'min' => 200, 'max' => 1000000],
            'opcache.validate_timestamps' => ['type' => 'bool'],
            'opcache.revalidate_freq' => ['type' => 'int', 'min' => 0, 'max' => 3600],
            'opcache.fast_shutdown' => ['type' => 'bool'],
            'opcache.enable_cli' => ['type' => 'bool'],
        ];

        $updates = [];
        foreach ($input as $key => $value) {
            if (!isset($allowed[$key])) {
                continue; // silently ignore unknown / non-whitelisted keys
            }
            $rule = $allowed[$key];
            if ($rule['type'] === 'bool') {
                $updates[$key] = $value ? '1' : '0';
            } elseif ($rule['type'] === 'int') {
                $intVal = (int) $value;
                if ($intVal < $rule['min'] || $intVal > $rule['max']) {
                    continue;
                }
                $updates[$key] = (string) $intVal;
            }
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No valid settings provided']);
            exit;
        }

        $err = applyIniSettings($phpIniPath, $updates);
        if ($err) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $err]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'updated' => array_keys($updates),
                'message' => 'php.ini updated. Restart Apache for changes to take full effect.',
                'restart_required' => true,
            ],
        ]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Unknown opcache action']);
}
