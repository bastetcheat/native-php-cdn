<?php
/**
 * GET /api/docs
 * Full self-documenting API reference – JSON format, designed for AI agents.
 * Requires a valid Bearer token to access (any permissions).
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Require a valid Bearer token
$tokenUser = Security::validateBearerToken($db);
if (!$tokenUser) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized. Provide: Authorization: Bearer <token>',
    ]);
    exit;
}

// ── Derive base URL ───────────────────────────────────────────────────────────
$scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['SERVER_PORT'] ?? 80) == 443)) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$base = $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir : '');

// ── Dynamic config values ─────────────────────────────────────────────────────
$maxMb = (int) Database::getSetting('max_upload_mb', '700');
$maxLabel = $maxMb . ' MB';

// ── Build full endpoint catalogue ─────────────────────────────────────────────
$endpoints = [];

// 1. Upload a file (Bearer token, multipart)
$endpoints[] = [
    'id' => 'upload_file',
    'group' => 'Files',
    'title' => 'Upload a file',
    'method' => 'POST',
    'path' => '/api/upload',
    'url' => $base . '/api/upload',
    'auth' => 'Bearer token – requires "upload" permission',
    'content_type' => 'multipart/form-data',
    'body_fields' => [
        [
            'name' => 'file',
            'type' => 'file',
            'required' => true,
            'description' => 'File to upload. Max ' . $maxLabel . '. Must be a supported type.'
        ],
    ],
    'supported_types' => [
        'archives' => ['zip', 'rar', '7z', 'gz', 'tar', 'bz2'],
        'executables' => ['exe', 'dll', 'msi', 'sys'],
        'ml_models' => ['pt', 'onnx', 'pkl', 'safetensors', 'pth', 'ckpt', 'h5', 'pb', 'weights'],
        'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'],
        'video' => ['mp4', 'webm', 'avi', 'mov', 'mkv'],
        'audio' => ['mp3', 'ogg', 'wav', 'flac'],
        'documents' => ['pdf', 'txt', 'csv', 'json', 'xml'],
        'fonts' => ['ttf', 'otf', 'woff', 'woff2'],
        'other' => ['wasm', 'bin', 'dat', 'iso'],
        'max_size' => $maxLabel,
    ],
    'example_curl' => implode(" \\\n  ", [
        "curl -X POST '$base/api/upload'",
        "-H 'Authorization: Bearer YOUR_TOKEN'",
        "-F 'file=@/path/to/model.onnx'",
    ]),
    'example_python' => implode("\n", [
        "import requests",
        "r = requests.post(",
        "    '$base/api/upload',",
        "    headers={'Authorization': 'Bearer YOUR_TOKEN'},",
        "    files={'file': open('model.onnx', 'rb')}",
        ")",
        "data = r.json()['data']",
        "print(data['download_url'], data['sha256_hash'])",
    ]),
    'response_success' => [
        'success' => true,
        'data' => [
            'id' => 42,
            'original_name' => 'model.onnx',
            'sha256_hash' => 'a1b2c3d4...',
            'size' => 5242880,
            'mime_type' => 'application/octet-stream',
            'metadata_url' => 'files/metadata/model.onnx',
            'download_url' => 'files/download/model.onnx',
        ],
    ],
    'errors' => [
        ['code' => 400, 'reason' => 'No file / validation failed (size, type)'],
        ['code' => 401, 'reason' => 'Missing or invalid token'],
        ['code' => 403, 'reason' => 'Token lacks "upload" permission'],
        ['code' => 409, 'reason' => 'File already exists with same content'],
    ],
];

// 1b. Chunked Upload (for large files, Cloudflare bypass)
$endpoints[] = [
    'id' => 'chunked_upload',
    'group' => 'Files',
    'title' => 'Chunked upload (Cloudflare bypass)',
    'method' => 'POST',
    'path' => '/api/chunked',
    'url' => $base . '/api/chunked',
    'auth' => 'Bearer token – requires "upload" permission',
    'notes' => 'Break large files (>100MB) into pieces to bypass Cloudflare limits. Call start → upload pieces → finish.',
    'actions' => [
        'start' => [
            'url' => "$base/api/chunked?action=start",
            'description' => 'Initialize session. Returns upload_id (UUID).',
            'response' => ['success' => true, 'data' => ['upload_id' => 'uuid-123-456']]
        ],
        'upload' => [
            'url' => "$base/api/chunked?action=upload",
            'body_fields' => [
                ['name' => 'upload_id', 'required' => true],
                ['name' => 'chunk_index', 'required' => true],
                ['name' => 'chunk', 'type' => 'file', 'required' => true]
            ],
            'description' => 'Upload bit by bit. Index is 0-based.'
        ],
        'finish' => [
            'url' => "$base/api/chunked?action=finish",
            'body_fields' => [
                ['name' => 'upload_id', 'required' => true],
                ['name' => 'filename', 'required' => true],
                ['name' => 'total_chunks', 'required' => true],
                ['name' => 'sha256', 'description' => 'Expected hash for server-side verification']
            ],
            'description' => 'Reassemble everything. Server validates and moves to uploads.'
        ]
    ]
];


// 2. Get file metadata (public)
$endpoints[] = [
    'id' => 'get_metadata',
    'group' => 'Files',
    'title' => 'Get file metadata',
    'method' => 'GET',
    'path' => '/files/metadata/{original_name}',
    'url' => $base . '/files/metadata/{original_name}',
    'auth' => 'Public – no token required',
    'path_params' => [
        ['name' => 'original_name', 'description' => 'Original filename (URL-encode special chars)'],
    ],
    'example_curl' => "curl '$base/files/metadata/model.onnx'",
    'example_curl_encoded' => "curl '$base/files/metadata/My%20File.zip'",
    'example_python' => implode("\n", [
        "import requests",
        "r = requests.get('$base/files/metadata/model.onnx')",
        "meta = r.json()['data']",
        "print(meta['sha256_hash'], meta['size'])",
    ]),
    'response_success' => [
        'success' => true,
        'data' => [
            'id' => 42,
            'original_name' => 'model.onnx',
            'mime_type' => 'application/octet-stream',
            'size' => 5242880,
            'size_human' => '5.00 MB',
            'sha256_hash' => 'a1b2c3d4...',
            'extension' => 'onnx',
            'download_count' => 17,
            'version' => 1,
            'uploaded_by' => 'admin',
            'created_at' => '2026-02-25 01:00:00',
            'download_url' => 'files/download/model.onnx',
        ],
    ],
    'errors' => [['code' => 404, 'reason' => 'File not found']],
];

// 3. Download a file (public)
$endpoints[] = [
    'id' => 'download_file',
    'group' => 'Files',
    'title' => 'Download a file',
    'method' => 'GET',
    'path' => '/files/download/{original_name}',
    'url' => $base . '/files/download/{original_name}',
    'auth' => 'Public – no token required',
    'notes' => 'Supports ETag/If-None-Match for HTTP caching. Download count incremented on each non-cached hit.',
    'path_params' => [
        ['name' => 'original_name', 'description' => 'Original filename (URL-encode special chars)'],
    ],
    'example_curl' => "curl -O '$base/files/download/model.onnx'",
    'example_python' => implode("\n", [
        "import requests",
        "r = requests.get('$base/files/download/model.onnx')",
        "open('model.onnx', 'wb').write(r.content)",
    ]),
    'response_headers' => [
        'Content-Type' => 'MIME type of the file',
        'Content-Disposition' => 'attachment; filename="model.onnx"',
        'ETag' => 'SHA-256 hash – use with If-None-Match',
        'Content-Length' => 'File size in bytes',
    ],
    'errors' => [
        ['code' => 304, 'reason' => 'Not modified (ETag matched – use cached copy)'],
        ['code' => 404, 'reason' => 'File not found'],
    ],
];

// 4. Inventory – all uploads (paginated, bearer-auth)
$endpoints[] = [
    'id' => 'all_uploads',
    'group' => 'Files',
    'title' => 'List all uploaded files (paginated inventory)',
    'method' => 'GET',
    'path' => '/api/alluploads',
    'url' => $base . '/api/alluploads',
    'auth' => 'Bearer token – any valid token (any permissions)',
    'query_params' => [
        ['name' => 'page', 'default' => 1, 'description' => 'Page number (1-based)'],
        ['name' => 'per_page', 'default' => 50, 'description' => 'Results per page (max 200)'],
        ['name' => 'search', 'default' => '', 'description' => 'Filter by filename substring'],
    ],
    'notes' => 'Returns every file on the CDN regardless of who uploaded it. Also includes global usage stats.',
    'example_curl' => implode(" \\\n  ", [
        "curl '$base/api/alluploads?page=1&per_page=50'",
        "-H 'Authorization: Bearer YOUR_TOKEN'",
    ]),
    'example_python' => implode("\n", [
        "import requests",
        "r = requests.get(",
        "    '$base/api/alluploads',",
        "    headers={'Authorization': 'Bearer YOUR_TOKEN'},",
        "    params={'page': 1, 'per_page': 50}",
        ")",
        "inv = r.json()['data']",
        "print(inv['global_stats'])",
        "for f in inv['files']: print(f['original_name'], f['download_url'])",
    ]),
    'response_success' => [
        'success' => true,
        'data' => [
            'files' => [
                [
                    'id' => 1,
                    'original_name' => 'model.onnx',
                    'size' => 5242880,
                    'size_human' => '5.00 MB',
                    'sha256_hash' => 'a1b2...',
                    'download_count' => 3,
                    'uploaded_by' => 'admin',
                    'uploaded_via_token' => 'AI-Agent-Token',
                    'download_url' => $base . '/files/download/model.onnx',
                    'metadata_url' => $base . '/files/metadata/model.onnx',
                ],
            ],
            'pagination' => ['page' => 1, 'per_page' => 50, 'total_results' => 1, 'total_pages' => 1],
            'global_stats' => [
                'total_files' => 1,
                'total_size' => 5242880,
                'total_size_human' => '5.00 MB',
                'total_downloads' => 3,
            ],
            'authenticated_as' => $tokenUser['username'],
        ],
    ],
    'errors' => [
        ['code' => 401, 'reason' => 'Missing or invalid token'],
    ],
];

// 5. Token: list my tokens
$endpoints[] = [
    'id' => 'list_tokens',
    'group' => 'Tokens',
    'title' => 'List my OAuth tokens',
    'method' => 'GET',
    'path' => '/api/tokens',
    'url' => $base . '/api/tokens',
    'auth' => 'Admin session cookie (X-CSRF-Token header required for mutations)',
    'notes' => 'Session-only endpoint – not callable with a Bearer token. Use the Admin Panel.',
    'example_curl' => "# Requires browser session – use the Admin Panel at $base/admin/",
];

// 6. Token: create
$endpoints[] = [
    'id' => 'create_token',
    'group' => 'Tokens',
    'title' => 'Create a new token',
    'method' => 'POST',
    'path' => '/api/tokens',
    'url' => $base . '/api/tokens',
    'auth' => 'Admin session + CSRF',
    'content_type' => 'application/json',
    'body_fields' => [
        [
            'name' => 'name',
            'type' => 'string',
            'required' => true,
            'description' => 'Friendly label for the token (max 100 chars)'
        ],
        [
            'name' => 'permissions',
            'type' => 'string',
            'required' => false,
            'description' => 'Comma-separated: upload, download, metadata (default: all three)'
        ],
        [
            'name' => 'expires_at',
            'type' => 'datetime',
            'required' => false,
            'description' => 'ISO 8601 expiry or null for no expiry. Must be in the future.'
        ],
    ],
    'notes' => 'The full raw token is returned ONCE and never stored. Save it immediately.',
    'response_success' => [
        'success' => true,
        'data' => [
            'id' => 5,
            'name' => 'My AI Agent',
            'token' => 'cdn_a1b2c3d4e5f6... (SAVE THIS NOW)',
            'token_prefix' => 'cdn_a1b2c3d4...',
            'permissions' => 'upload,download,metadata',
            'expires_at' => null,
            'message' => 'Save this token now! It will not be shown again.',
        ],
    ],
    'errors' => [
        ['code' => 400, 'reason' => 'Name missing, name too long, invalid permission, or expiry in the past'],
        ['code' => 403, 'reason' => 'CSRF token missing/invalid'],
    ],
];

// 7. Token: revoke
$endpoints[] = [
    'id' => 'revoke_token',
    'group' => 'Tokens',
    'title' => 'Revoke a token',
    'method' => 'PUT',
    'path' => '/api/tokens/{id}/revoke',
    'url' => $base . '/api/tokens/{id}/revoke',
    'auth' => 'Admin session + CSRF',
    'path_params' => [['name' => 'id', 'description' => 'Token ID']],
    'example_curl' => "# Use the Admin Panel – session CSRF prevents direct curl calls",
];

// 8. Token: re-activate
$endpoints[] = [
    'id' => 'activate_token',
    'group' => 'Tokens',
    'title' => 'Re-activate a revoked token',
    'method' => 'PUT',
    'path' => '/api/tokens/{id}/activate',
    'url' => $base . '/api/tokens/{id}/activate',
    'auth' => 'Admin session + CSRF',
    'path_params' => [['name' => 'id', 'description' => 'Token ID']],
];

// 9. Token: delete
$endpoints[] = [
    'id' => 'delete_token',
    'group' => 'Tokens',
    'title' => 'Permanently delete a token',
    'method' => 'DELETE',
    'path' => '/api/tokens/{id}',
    'url' => $base . '/api/tokens/{id}',
    'auth' => 'Admin session + CSRF',
    'path_params' => [['name' => 'id', 'description' => 'Token ID']],
    'notes' => 'Deletes the token record from the DB. Cannot be undone.',
];

// 10. Docs (this endpoint)
$endpoints[] = [
    'id' => 'get_docs',
    'group' => 'Meta',
    'title' => 'API documentation (self-describing)',
    'method' => 'GET',
    'path' => '/api/docs',
    'url' => $base . '/api/docs',
    'auth' => 'Bearer token – any valid token',
    'notes' => 'Returns this JSON document. AI agents should call this first to self-discover all routes.',
    'example_curl' => "curl '$base/api/docs' -H 'Authorization: Bearer YOUR_TOKEN'",
];

// 11. Settings: read upload limit
$endpoints[] = [
    'id' => 'get_upload_limit',
    'group' => 'Admin / Settings',
    'title' => 'Get current upload size limit',
    'method' => 'GET',
    'path' => '/api/settings/upload',
    'url' => $base . '/api/settings/upload',
    'auth' => 'Admin session',
    'response_success' => ['success' => true, 'data' => ['max_upload_mb' => $maxMb]],
];

// 12. Settings: update upload limit
$endpoints[] = [
    'id' => 'set_upload_limit',
    'group' => 'Admin / Settings',
    'title' => 'Set upload size limit (also syncs .htaccess)',
    'method' => 'PUT',
    'path' => '/api/settings/upload',
    'url' => $base . '/api/settings/upload',
    'auth' => 'Admin session + CSRF',
    'content_type' => 'application/json',
    'body_fields' => [
        [
            'name' => 'max_upload_mb',
            'type' => 'integer',
            'required' => true,
            'description' => 'New max file size in MB. Range: 1–10000.'
        ],
    ],
    'response_success' => [
        'success' => true,
        'data' => [
            'max_upload_mb' => 700,
            'htaccess_updated' => true,
            'restart_required' => true,
            'message' => 'Upload limit updated to 700 MB and .htaccess synced.',
        ],
    ],
];

// 13. Settings: restart apache
$endpoints[] = [
    'id' => 'restart_apache',
    'group' => 'Admin / Settings',
    'title' => 'Restart Apache (XAMPP / Windows)',
    'method' => 'POST',
    'path' => '/api/settings/restart-apache',
    'url' => $base . '/api/settings/restart-apache',
    'auth' => 'Admin session + CSRF',
    'notes' => 'Attempts httpd.exe -k graceful then falls back to net stop/start. exec() must be enabled.',
];

// ── Agent patterns (step-by-step workflows) ────────────────────────────────
$agentPatterns = [
    'upload_and_share' => [
        'description' => 'Upload a file and get a permanent public download URL.',
        'steps' => [
            '1. POST /api/upload  → get data.original_name',
            '2. Construct URL: ' . $base . '/files/download/{original_name}',
        ],
        'full_example' => implode("\n", [
            "# Upload",
            "curl -X POST '$base/api/upload'",
            "     -H 'Authorization: Bearer YOUR_TOKEN'",
            "     -F 'file=@/path/to/payload.zip'",
            "",
            "# Response → data.original_name == \"payload.zip\"",
            "",
            "# Share this URL (public, no token needed)",
            "curl -O '$base/files/download/payload.zip'",
        ]),
    ],
    'verify_integrity' => [
        'description' => 'Verify a downloaded file has not been tampered with.',
        'steps' => [
            '1. GET /files/metadata/{name}  → read data.sha256_hash',
            '2. Download: GET /files/download/{name}',
            '3. sha256sum downloaded_file  → must match data.sha256_hash',
        ],
    ],
    'check_before_upload' => [
        'description' => 'Avoid duplicate uploads by checking if a file already exists.',
        'steps' => [
            '1. GET /files/metadata/{name}  → 200 = exists, read sha256_hash / version',
            '2. 404 → safe to upload via POST /api/upload',
        ],
    ],
    'inventory_all_files' => [
        'description' => 'Get a full inventory of everything stored on this CDN.',
        'steps' => [
            '1. GET /api/alluploads?per_page=200',
            '2. Iterate data.files[] — each entry contains download_url, metadata_url, size, sha256_hash, uploaded_by.',
            '3. Use data.pagination.total_pages to paginate further.',
        ],
        'full_example' => implode("\n", [
            "import requests",
            "BASE  = '$base'",
            "TOKEN = 'YOUR_TOKEN'",
            "page, all_files = 1, []",
            "while True:",
            "    r = requests.get(f'{BASE}/api/alluploads',",
            "                    headers={'Authorization': f'Bearer {TOKEN}'},",
            "                    params={'page': page, 'per_page': 200}).json()",
            "    all_files.extend(r['data']['files'])",
            "    if page >= r['data']['pagination']['total_pages']: break",
            "    page += 1",
            "print(f'Total: {len(all_files)} files')",
        ]),
    ],
    'large_file_upload' => [
        'description' => 'Bypass Cloudflare 100MB limit by uploading in chunks.',
        'steps' => [
            '1. GET /api/chunked?action=start → get upload_id',
            '2. For each 10MB chunk: POST /api/chunked?action=upload (send upload_id, chunk_index, chunk)',
            '3. POST /api/chunked?action=finish (send upload_id, filename, total_chunks, sha256)',
        ],
        'python_snippet' => 'Check api/docs python examples for chunking logic.'
    ],
    'self_discover_then_upload' => [

        'description' => 'The recommended first step for any AI agent connecting to this CDN.',
        'steps' => [
            '1. GET /api/docs  using your token → parse endpoint catalogue',
            '2. Read max_upload_mb from agent_context to know the file size limit',
            '3. POST /api/upload with your file',
            '4. Save the returned download_url to share results',
        ],
    ],
];

echo json_encode([
    'success' => true,
    'data' => [
        'service' => 'CDN Panel API',
        'version' => '1.0',
        'base_url' => $base,
        'authenticated_as' => $tokenUser['username'],

        'agent_context' => [
            'max_upload_mb' => $maxMb,
            'upload_endpoint' => $base . '/api/upload',
            'inventory_endpoint' => $base . '/api/alluploads',
            'metadata_pattern' => $base . '/files/metadata/{original_name}',
            'download_pattern' => $base . '/files/download/{original_name}',
            'note' => 'All original filenames are preserved. URL-encode special characters.',
        ],

        'authentication' => [
            'type' => 'OAuth2 Bearer Token',
            'header' => 'Authorization: Bearer <token>',
            'where_to_get' => 'Create tokens in the Admin Panel → OAuth Tokens',
            'permissions' => ['upload', 'download', 'metadata'],
            'note' => '/api/docs and /api/alluploads accept any valid token regardless of permissions set.',
        ],

        'response_envelope' => [
            'success_shape' => ['success' => true, 'data' => '(object or array)'],
            'error_shape' => ['success' => false, 'error' => 'Human-readable message'],
        ],

        'error_codes' => [
            ['code' => 400, 'meaning' => 'Bad request – missing field, validation failed, or malformed JSON'],
            ['code' => 401, 'meaning' => 'Unauthorized – no token, invalid token, expired or revoked'],
            ['code' => 403, 'meaning' => 'Forbidden – token lacks required permission OR bad CSRF token'],
            ['code' => 404, 'meaning' => 'Not found – file or endpoint does not exist'],
            ['code' => 405, 'meaning' => 'Method not allowed – wrong HTTP verb'],
            ['code' => 409, 'meaning' => 'Conflict – file is identical to current version (same SHA-256)'],
            ['code' => 429, 'meaning' => 'Rate limited – too many failed login attempts'],
            ['code' => 500, 'meaning' => 'Internal server error – check server error log'],
        ],

        'endpoints' => $endpoints,
        'agent_patterns' => $agentPatterns,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
