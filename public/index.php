<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use AdminMcp\FileSessionStore;
use AdminMcp\McpServerFactory;
use AdminMcp\OpenApiParser;
use GuzzleHttp\Client as GuzzleClient;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

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

// Configuration from environment
$openApiSpecUrl = $_ENV['OPENAPI_SPEC_URL'] ?? 'https://my.interserver.net/admin/spec/openapi-admin.yaml';
$apiBaseUrl = $_ENV['API_BASE_URL'] ?? 'https://my.interserver.net/apiv2/admin';
$sessionDir = $_ENV['SESSION_DIR'] ?? '/tmp/mcp_admin_sessions';
$cacheDir = $_ENV['CACHE_DIR'] ?? '/tmp/mcp_admin_cache';
$serverName = $_ENV['SERVER_NAME'] ?? 'myadmin-admin-mcp';
$serverVersion = $_ENV['SERVER_VERSION'] ?? '1.0.0';

// Handle OAuth protected resource metadata endpoint
$request = Request::createFromGlobals();
$path = $request->getPathInfo();

// OAuth 2.1 protected resource metadata endpoint
if ($path === '/.well-known/oauth-protected-resource') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, max-age=0');

    $metadata = [
        'resource' => $request->getSchemeAndHttpHost(),
        'authorization_servers' => [
            $request->getSchemeAndHttpHost() . '/oauth/bshaffer',
        ],
        'scopes_supported' => [
            'admin',
            'admin_login',
            'read',
            'write',
        ],
        'bearer_methods_supported' => ['header'],
        'resource_signing_alg_values_supported' => ['RS256'],
        'resource_documentation' => $request->getSchemeAndHttpHost() . '/api-docs/',
    ];

    echo json_encode($metadata, JSON_PRETTY_PRINT);
    exit;
}

// Handle OAuth authorization server metadata endpoint
if ($path === '/.well-known/oauth-authorization-server') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, max-age=0');

    $metadata = [
        'issuer' => $request->getSchemeAndHttpHost() . '/oauth/bshaffer',
        'authorization_endpoint' => $request->getSchemeAndHttpHost() . '/oauth/bshaffer/authorize.php',
        'token_endpoint' => $request->getSchemeAndHttpHost() . '/oauth/bshaffer/token.php',
        'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
        'scopes_supported' => ['admin', 'admin_login', 'read', 'write'],
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
    ];

    echo json_encode($metadata, JSON_PRETTY_PRINT);
    exit;
}

// Initialize components
$sessionStore = new FileSessionStore($sessionDir, new NullLogger());
$httpClient = new GuzzleClient([
    'timeout' => 30,
    'connect_timeout' => 10,
]);
$parser = new OpenApiParser($cacheDir, $httpClient);

// Parse OpenAPI spec and build MCP server
try {
    $toolDefs = $parser->parse($openApiSpecUrl);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to parse OpenAPI spec: ' . $e->getMessage()]);
    exit;
}

$factory = new McpServerFactory($apiBaseUrl);
$mcpServer = $factory->build($serverName, $serverVersion, $toolDefs, $sessionStore);

// Create PSR-7 request from Symfony request
$psr17Factory = new Psr17Factory();
$psrRequest = (new ServerRequest(
    $request->getMethod(),
    $request->getRequestUri(),
    $request->headers->all(),
    $request->getContent(),
    '1.1',
    $_SERVER
))->withQueryParams($request->query->all());

// Create and configure transport
$transport = new StreamableHttpTransport(
    $psrRequest,
    $psr17Factory,
    $psr17Factory,
    [],
    new NullLogger()
);

// Connect server to transport and handle the request
$mcpServer->connect($transport);

try {
    $response = $transport->listen();

    // Send response
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("$name: $value");
        }
    }
    echo $response->getBody();
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
