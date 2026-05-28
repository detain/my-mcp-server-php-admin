<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AdminMcp\McpServerFactory;
use AdminMcp\OpenApiParser;
use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Mcp\Server\Transport\StreamableHttpTransport;

// Load environment variables
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key && !isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Configuration from environment (with admin-specific defaults)
$openapiSpecUrl = $_ENV['OPENAPI_SPEC_URL'] ?? 'https://my.interserver.net/admin/spec/openapi-admin.yaml';
$apiBaseUrl = $_ENV['API_BASE_URL'] ?? 'https://my.interserver.net/apiv2/admin';
$sessionDir = $_ENV['SESSION_DIR'] ?? '/tmp/mcp_admin_sessions';
$cacheDir = $_ENV['CACHE_DIR'] ?? '/tmp/mcp_admin_cache';
$serverName = $_ENV['SERVER_NAME'] ?? 'myadmin-admin-mcp';
$serverVersion = $_ENV['SERVER_VERSION'] ?? '1.0.0';

// SSL / TLS configuration
$caCertFile = $_ENV['CA_CERT_FILE'] ?? '';
$sslVerify = filter_var($_ENV['SSL_VERIFY'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

// Apply CA bundle globally for curl + openssl if a file was provided
if ($caCertFile !== '' && is_file($caCertFile)) {
    ini_set('curl.cainfo', $caCertFile);
    ini_set('openssl.cafile', $caCertFile);
}

// Combine into the single value Guzzle expects for its `verify` option,
// which maps to CURLOPT_SSL_VERIFYPEER / CURLOPT_SSL_VERIFYHOST / CURLOPT_CAINFO.
$verifyOption = $sslVerify ? ($caCertFile !== '' ? $caCertFile : true) : false;

// Validate that critical config is non-empty (safety net for explicit empty env vars)
if (empty($openapiSpecUrl) || empty($apiBaseUrl)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Missing required configuration: OPENAPI_SPEC_URL and API_BASE_URL must be set',
    ]);
    exit(1);
}

// Create required directories
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0775, true);
}
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

// Routing
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Strip query string for path matching
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?? '/';

// Build scheme + host (used by OAuth metadata URLs)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$schemeAndHost = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// OAuth 2.1 Protected Resource Metadata endpoint (RFC 9700)
if ($requestMethod === 'GET' && $requestPath === '/.well-known/oauth-protected-resource') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, max-age=0');

    echo json_encode([
        'resource' => $schemeAndHost,
        'authorization_servers' => [
            $schemeAndHost . '/oauth/bshaffer',
        ],
        'scopes_supported' => ['admin', 'admin_login', 'read', 'write'],
        'bearer_methods_supported' => ['header'],
        'resource_signing_alg_values_supported' => ['RS256'],
        'resource_documentation' => $schemeAndHost . '/api-docs/',
    ], JSON_PRETTY_PRINT);
    exit(0);
}

// OAuth 2.1 Authorization Server Metadata endpoint (RFC 8414)
if ($requestMethod === 'GET' && $requestPath === '/.well-known/oauth-authorization-server') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, max-age=0');

    echo json_encode([
        'issuer' => $schemeAndHost . '/oauth/bshaffer',
        'authorization_endpoint' => $schemeAndHost . '/oauth/bshaffer/authorize.php',
        'token_endpoint' => $schemeAndHost . '/oauth/bshaffer/token.php',
        'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
        'scopes_supported' => ['admin', 'admin_login', 'read', 'write'],
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
    ], JSON_PRETTY_PRINT);
    exit(0);
}

// Handle CORS preflight
if ($requestMethod === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, Mcp-Session-Id, X-API-KEY, sessionid');
    header('Access-Control-Max-Age: 86400');
    exit(0);
}

// Parse OpenAPI spec and build MCP server
try {
    $parser = new OpenApiParser($cacheDir, new GuzzleClient([
        'timeout' => 30,
        'connect_timeout' => 10,
        'verify' => $verifyOption,
    ]));
    $toolDefs = $parser->parse($openapiSpecUrl);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to parse OpenAPI spec: ' . $e->getMessage()]);
    exit(1);
}

// Build the MCP server
try {
    $factory = new McpServerFactory($apiBaseUrl);
    $factory->setSslVerify($verifyOption);
    $sessionStore = new \Mcp\Server\Session\FileSessionStore($sessionDir, 3600);
    $server = $factory->build($serverName, $serverVersion, $toolDefs, $sessionStore);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to build MCP server: ' . $e->getMessage()]);
    exit(1);
}

// Create PSR-7 request/response factories via discovery
$serverRequestFactory = Psr17FactoryDiscovery::findServerRequestFactory();
$responseFactory = Psr17FactoryDiscovery::findResponseFactory();
$streamFactory = Psr17FactoryDiscovery::findStreamFactory();

$content = file_get_contents('php://input');
$psrRequest = $serverRequestFactory->createServerRequest($requestMethod, $requestUri, $_SERVER)
    ->withHeader('Accept', $_SERVER['HTTP_ACCEPT'] ?? 'application/json')
    ->withHeader('Content-Type', $_SERVER['CONTENT_TYPE'] ?? 'application/json');

if ($content) {
    $psrRequest = $psrRequest->withBody($streamFactory->createStream($content));
}

// Forward MCP-relevant headers explicitly
$headersToForward = [
    'Authorization',
    'X-API-KEY',
    'sessionid',
    'Mcp-Session-Id',
    'Mcp-Protocol-Version',
    'Last-Event-ID',
];

foreach ($headersToForward as $headerName) {
    $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
    if (isset($_SERVER[$headerKey])) {
        $psrRequest = $psrRequest->withHeader($headerName, $_SERVER[$headerKey]);
    }
}

// Create and run the transport
$transport = new StreamableHttpTransport($psrRequest, $responseFactory, $streamFactory);

try {
    $server->run($transport);
    $response = $transport->listen();

    // Send the response. Add CORS headers here since the SDK transport doesn't
    // expose a public hook for arbitrary response headers.
    http_response_code($response->getStatusCode());
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, Mcp-Session-Id, X-API-KEY, sessionid');
    header('Access-Control-Expose-Headers: Mcp-Session-Id');
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("$name: $value", false);
        }
    }
    echo $response->getBody();
} catch (\Throwable $e) {
    error_log('MCP server error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
    exit(1);
}
