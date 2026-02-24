<?php
/**
 * GET /api/docs
 * Returns comprehensive API documentation as JSON.
 * Requires a valid OAuth Bearer token to access.
 */
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Require a valid Bearer token – docs are not public
$tokenUser = Security::validateBearerToken($db);
if (!$tokenUser) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized. Provide a valid Bearer token: Authorization: Bearer <token>',
    ]);
    exit;
}

// ─── Derive base URL dynamically (works in any subdirectory) ─────────────
$scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['SERVER_PORT'] ?? 80) == 443)) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$baseUrl = $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir : '');

echo json_encode([
    'success' => true,
    'data' => [

        // ── Identity ────────────────────────────────────────────────────────
        'service' => 'CDN Panel API',
        'version' => '1.0',
        'base_url' => $baseUrl,
        'authenticated_as' => $tokenUser['username'],

        // ── Quick-start guide ───────────────────────────────────────────────
        'quickstart' => [
            'step_1' => 'Every request must include your Bearer token in the Authorization header.',
            'step_2' => 'Upload a file with POST /api/upload (multipart/form-data, field name: "file").',
            'step_3' => 'Use the returned original_name to build metadata and download URLs.',
            'step_4' => 'GET /files/metadata/{original_name}  → rich JSON info about the file.',
            'step_5' => 'GET /files/download/{original_name}  → stream/download the actual file.',
            'note' => 'original_name is the filename you uploaded (URL-encode special chars).',
        ],

        // ── Authentication ───────────────────────────────────────────────────
        'authentication' => [
            'type' => 'OAuth2 Bearer Token',
            'header' => 'Authorization: Bearer <token>',
            'description' => 'Tokens are created in the Admin Panel under "OAuth Tokens". Each token has a set of permissions: upload, download, metadata. A token only grants operations its permissions allow.',
            'example_header' => "Authorization: Bearer cdn_a1b2c3d4e5f6...",
        ],

        // ── Endpoints ────────────────────────────────────────────────────────
        'endpoints' => [

            [
                'id' => 'upload_file',
                'title' => 'Upload a File',
                'method' => 'POST',
                'path' => '/api/upload',
                'url' => $baseUrl . '/api/upload',
                'auth' => 'Bearer token with "upload" permission',
                'content_type' => 'multipart/form-data',
                'fields' => [
                    ['name' => 'file', 'type' => 'file', 'required' => true, 'description' => 'The file to upload. Max 100 MB.'],
                ],
                'description' => 'Upload a single file to the CDN. The file is stored with a UUID-based name internally for security. Returns the original filename and SHA-256 hash for integrity verification.',
                'example_curl' => implode(" \\\n  ", [
                    "curl -X POST '{$baseUrl}/api/upload'",
                    "-H 'Authorization: Bearer YOUR_TOKEN'",
                    "-F 'file=@/path/to/SDK.rar'",
                ]),
                'response_success' => [
                    'success' => true,
                    'data' => [
                        'id' => 42,
                        'original_name' => 'SDK.rar',
                        'sha256_hash' => 'a1b2c3d4e5f6...',
                        'size' => 5242880,
                        'mime_type' => 'application/x-rar-compressed',
                        'metadata_url' => 'files/metadata/SDK.rar',
                        'download_url' => 'files/download/SDK.rar',
                    ],
                ],
                'response_errors' => [
                    ['code' => 400, 'reason' => 'No file provided or validation failed (size/type)'],
                    ['code' => 401, 'reason' => 'Missing or invalid token'],
                    ['code' => 403, 'reason' => 'Token lacks "upload" permission'],
                ],
                'supported_types' => [
                    'archives' => ['zip', 'rar', '7z', 'gz', 'tar', 'bz2'],
                    'executables' => ['exe', 'dll', 'msi', 'sys'],
                    'ml_models' => ['pt', 'onnx', 'pkl', 'ckpt', 'h5', 'safetensors', 'pth', 'pb', 'weights'],
                    'images' => ['jpg', 'png', 'gif', 'webp', 'svg', 'bmp'],
                    'video' => ['mp4', 'webm', 'avi', 'mov', 'mkv'],
                    'audio' => ['mp3', 'ogg', 'wav', 'flac'],
                    'documents' => ['pdf', 'txt', 'csv', 'json', 'xml'],
                    'fonts' => ['ttf', 'otf', 'woff', 'woff2'],
                    'other' => ['wasm', 'bin', 'dat', 'iso'],
                    'max_size' => '100 MB per file',
                ],
            ],

            [
                'id' => 'get_metadata',
                'title' => 'Get File Metadata',
                'method' => 'GET',
                'path' => '/files/metadata/{original_name}',
                'url' => $baseUrl . '/files/metadata/{original_name}',
                'auth' => 'None required (public endpoint)',
                'description' => 'Returns rich JSON metadata for a file by its original filename. Use this to verify a file before downloading, check its hash, version, or download count.',
                'path_params' => [
                    ['name' => 'original_name', 'description' => 'The original filename used when uploading. URL-encode spaces and special characters.'],
                ],
                'example_curl' => "curl '{$baseUrl}/files/metadata/SDK.rar'",
                'example_curl_encoded' => "curl '{$baseUrl}/files/metadata/My%20File.zip'",
                'response_success' => [
                    'success' => true,
                    'data' => [
                        'id' => 42,
                        'original_name' => 'SDK.rar',
                        'mime_type' => 'application/x-rar-compressed',
                        'size' => 5242880,
                        'size_human' => '5.00 MB',
                        'sha256_hash' => 'a1b2c3d4e5f6...',
                        'extension' => 'rar',
                        'download_count' => 17,
                        'version' => 1,
                        'uploaded_by' => 'admin',
                        'created_at' => '2026-02-24 19:00:00',
                        'updated_at' => '2026-02-24 19:00:00',
                        'download_url' => 'files/download/SDK.rar',
                    ],
                ],
                'response_errors' => [
                    ['code' => 404, 'reason' => 'File not found by that name'],
                ],
            ],

            [
                'id' => 'download_file',
                'title' => 'Download a File',
                'method' => 'GET',
                'path' => '/files/download/{original_name}',
                'url' => $baseUrl . '/files/download/{original_name}',
                'auth' => 'None required (public endpoint)',
                'description' => 'Streams the actual file to the client. Supports ETag/If-None-Match for caching. Download count is incremented on each non-cached download.',
                'path_params' => [
                    ['name' => 'original_name', 'description' => 'The original filename used when uploading.'],
                ],
                'example_curl' => "curl -O '{$baseUrl}/files/download/SDK.rar'",
                'example_curl_saveAs' => "curl -o 'output.rar' '{$baseUrl}/files/download/SDK.rar'",
                'example_python' => implode("\n", [
                    "import requests",
                    "r = requests.get('{$baseUrl}/files/download/SDK.rar')",
                    "open('SDK.rar', 'wb').write(r.content)",
                ]),
                'response_headers' => [
                    'Content-Type' => 'Detected MIME type of the file',
                    'Content-Disposition' => 'attachment; filename="SDK.rar"',
                    'ETag' => 'SHA-256 hash for caching',
                    'Content-Length' => 'File size in bytes',
                ],
                'response_errors' => [
                    ['code' => 404, 'reason' => 'File not found'],
                    ['code' => 304, 'reason' => 'Not modified (ETag matched – use cached copy)'],
                ],
            ],

            [
                'id' => 'get_docs',
                'title' => 'API Documentation (this endpoint)',
                'method' => 'GET',
                'path' => '/api/docs',
                'url' => $baseUrl . '/api/docs',
                'auth' => 'Bearer token (any valid token)',
                'description' => 'Returns this full documentation as JSON. Useful for AI agents to self-discover all endpoints, parameters, examples, and error codes.',
                'example_curl' => "curl '{$baseUrl}/api/docs' -H 'Authorization: Bearer YOUR_TOKEN'",
            ],
        ],

        // ── Error codes reference ────────────────────────────────────────────
        'error_codes' => [
            ['code' => 400, 'meaning' => 'Bad request – missing field, validation failed, or malformed input'],
            ['code' => 401, 'meaning' => 'Unauthorized – no token, invalid token, expired token, or revoked token'],
            ['code' => 403, 'meaning' => 'Forbidden – token lacks the required permission (upload/download/metadata)'],
            ['code' => 404, 'meaning' => 'Not found – file or endpoint does not exist'],
            ['code' => 405, 'meaning' => 'Method not allowed – wrong HTTP verb for this endpoint'],
            ['code' => 429, 'meaning' => 'Rate limited – too many failed login attempts (admin panel only)'],
            ['code' => 500, 'meaning' => 'Internal server error – check server logs'],
        ],

        // ── Response envelope ───────────────────────────────────────────────
        'response_format' => [
            'description' => 'All API responses use a consistent JSON envelope.',
            'success_shape' => ['success' => true, 'data' => '(object or array)'],
            'error_shape' => ['success' => false, 'error' => 'Human-readable error message'],
        ],

        // ── SDK-style patterns for AI agents ────────────────────────────────
        'agent_patterns' => [
            'upload_and_get_url' => [
                'description' => 'Upload a file and immediately get its public download URL.',
                'steps' => [
                    '1. POST /api/upload with Authorization header and the file.',
                    '2. From the response, read data.original_name.',
                    '3. Construct download URL: ' . $baseUrl . '/files/download/{original_name}',
                    '4. Construct metadata URL: ' . $baseUrl . '/files/metadata/{original_name}',
                ],
                'curl_full_example' => implode("\n", [
                    "# Step 1 – upload",
                    "curl -X POST '{$baseUrl}/api/upload' \\",
                    "  -H 'Authorization: Bearer YOUR_TOKEN' \\",
                    "  -F 'file=@model.onnx'",
                    "",
                    "# Step 2 – fetch metadata to verify",
                    "curl '{$baseUrl}/files/metadata/model.onnx'",
                    "",
                    "# Step 3 – download",
                    "curl -O '{$baseUrl}/files/download/model.onnx'",
                ]),
            ],
            'verify_integrity' => [
                'description' => 'Verify a downloaded file has not been tampered with.',
                'steps' => [
                    '1. GET /files/metadata/{name} → read data.sha256_hash',
                    '2. Download the file from /files/download/{name}',
                    '3. Compute SHA-256 of the downloaded bytes and compare to data.sha256_hash',
                ],
            ],
            'check_if_exists' => [
                'description' => 'Check if a file exists before uploading a duplicate.',
                'steps' => [
                    '1. GET /files/metadata/{name}',
                    '2. If response is 200 → file exists, read its sha256_hash/version.',
                    '3. If 404 → file does not exist, safe to upload.',
                ],
            ],
        ],

    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
