<?php

declare(strict_types=1);

namespace AdminMcp;

use GuzzleHttp\Client as GuzzleClient;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionStoreInterface;

/**
 * Builds an MCP Server from parsed OpenAPI tool definitions.
 *
 * Each tool proxies HTTP requests to the actual admin API, forwarding
 * the auth credentials from the original MCP HTTP request.
 */
class McpServerFactory
{
    private string $apiBaseUrl;

    /** @var array<string, string>|null Stdio auth credentials (api_key, session_id, bearer_token) */
    private ?array $stdioAuth = null;

    /** @var bool|string Guzzle verify option: true (system CA), false (no verify), or path to CA bundle. */
    private bool|string $sslVerify = true;

    public function __construct(string $apiBaseUrl)
    {
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
    }

    /**
     * Set stdio authentication credentials from environment variables.
     * These are used when the request context doesn't contain auth headers.
     *
     * @param array<string, string> $auth Auth array with keys: api_key, session_id, bearer_token
     */
    public function setStdioAuth(array $auth): void
    {
        $this->stdioAuth = $auth;
    }

    /**
     * Set the SSL verification setting for outbound API calls.
     * Maps directly to Guzzle's `verify` option, which controls
     * CURLOPT_SSL_VERIFYPEER / CURLOPT_SSL_VERIFYHOST / CURLOPT_CAINFO.
     *
     * @param bool|string $verify true (system CA), false (no verify), or path to a CA bundle file.
     */
    public function setSslVerify(bool|string $verify): void
    {
        $this->sslVerify = $verify;
    }

    /**
     * Build an MCP Server with tools generated from the OpenAPI spec.
     *
     * @param array $toolDefs Tool definitions from OpenApiParser::parse()
     */
    public function build(
        string $serverName,
        string $version,
        array $toolDefs,
        ?SessionStoreInterface $sessionStore = null
    ): Server {
        $builder = Server::builder()
            ->setServerInfo($serverName, $version)
            ->setPaginationLimit(1000);

        if ($sessionStore !== null) {
            $builder->setSession($sessionStore);
        }

        foreach ($toolDefs as $toolDef) {
            $handler = $this->createHandler($toolDef);
            try {
                $builder->addTool(
                    handler: $handler,
                    name: $toolDef['name'],
                    description: $toolDef['description'],
                    inputSchema: $toolDef['inputSchema'],
                    annotations: $toolDef['annotations'] ?? null,
                );
            } catch (\Throwable $e) {
                $builder->addTool(
                    handler: $handler,
                    name: $toolDef['name'],
                    description: $toolDef['description'],
                    inputSchema: $toolDef['inputSchema'],
                );
            }
        }

        return $builder->build();
    }

    /**
     * Create a handler closure for a single API tool.
     * The closure proxies the MCP tool call to the actual REST API endpoint.
     */
    private function createHandler(array $toolDef): \Closure
    {
        $httpMethod = $toolDef['httpMethod'];
        $pathTemplate = $toolDef['path'];
        $pathParams = $toolDef['pathParams'];
        $queryParams = $toolDef['queryParams'];
        $hasBody = $toolDef['hasBody'];
        $baseUrl = $this->apiBaseUrl;
        $sslVerify = $this->sslVerify;

        return function (RequestContext $ctx) use ($httpMethod, $pathTemplate, $pathParams, $queryParams, $hasBody, $baseUrl, $sslVerify) {
            /** @var \Mcp\Schema\Request\CallToolRequest $request */
            $request = $ctx->getRequest();
            $arguments = $request->arguments ?? [];

            // Extract auth headers from the MCP request context
            $authHeaders = $this->extractAuthHeaders($ctx);

            // Build the URL path, substituting path parameters
            $path = $pathTemplate;
            foreach ($pathParams as $param) {
                if (isset($arguments[$param])) {
                    $path = str_replace('{' . $param . '}', rawurlencode((string) $arguments[$param]), $path);
                }
            }

            // Build query string for applicable parameters
            $query = [];
            foreach ($queryParams as $param) {
                if (isset($arguments[$param])) {
                    $query[$param] = $arguments[$param];
                }
            }

            // Build request body from remaining arguments (not path/query params)
            $body = null;
            if ($hasBody) {
                $reserved = array_merge($pathParams, $queryParams);
                $body = array_diff_key($arguments, array_flip($reserved));
            }

            // Set up headers
            $headers = ['Accept' => 'application/json'];
            $headers = array_merge($headers, $authHeaders);
            // X-API-APP=1 short-circuits api_check_auth_limits() for MCP callers
            $headers['X-API-APP'] = '1';
            // Forward an MCP request id for tracing
            $headers['X-Request-Id'] = sprintf('mcp-%s-%s', bin2hex(random_bytes(4)), date('Hi'));

            $client = new GuzzleClient([
                'timeout' => 60,
                'connect_timeout' => 10,
                'http_errors' => false,
                'verify' => $sslVerify,
            ]);

            $options = ['headers' => $headers];
            if (!empty($query)) {
                $options['query'] = $query;
            }
            if ($body !== null && !empty($body)) {
                $options['json'] = $body;
            }

            // Build absolute URL
            $url = $baseUrl . '/' . ltrim($path, '/');

            try {
                $response = $client->request($httpMethod, $url, $options);
                $statusCode = $response->getStatusCode();
                $responseBody = (string) $response->getBody();
                $decoded = json_decode($responseBody, true);

                if ($statusCode >= 400) {
                    $errorMsg = 'API returned HTTP ' . $statusCode;
                    if (is_array($decoded) && isset($decoded['error'])) {
                        $errorMsg .= ': ' . $decoded['error'];
                    } elseif (is_array($decoded) && isset($decoded['message'])) {
                        $errorMsg .= ': ' . $decoded['message'];
                    }
                    return ['error' => $errorMsg, 'status' => $statusCode];
                }

                if ($decoded === null) {
                    return ['raw' => $responseBody];
                }

                // MCP requires structuredContent to be a JSON object, not a list.
                // ~89 OpenAPI endpoints (getVpsList, getDomainsList, admin list*, etc.)
                // return top-level arrays; wrap them so the SDK emits a valid object.
                if (is_array($decoded) && array_is_list($decoded)) {
                    return ['items' => $decoded];
                }

                return $decoded;
            } catch (\Throwable $e) {
                return ['error' => 'API request failed: ' . $e->getMessage()];
            }
        };
    }

    /**
     * Extract authentication headers from the MCP request context.
     * Supports X-API-KEY, sessionid, and Bearer token from incoming request headers.
     * For stdio mode, falls back to credentials set via setStdioAuth().
     *
     * @return array<string, string>
     */
    private function extractAuthHeaders(RequestContext $ctx): array
    {
        $headers = [];

        // Get the underlying HTTP request to access headers
        $request = $ctx->getRequest();

        // Access headers via the PSR-7 request interface
        if (method_exists($request, 'getHeaderLine')) {
            // Try Authorization header for Bearer token
            $auth = $request->getHeaderLine('Authorization');
            if ($auth && str_starts_with($auth, 'Bearer ')) {
                $headers['Authorization'] = $auth;
            }

            // Try X-API-KEY header
            $apiKey = $request->getHeaderLine('X-API-KEY');
            if ($apiKey) {
                $headers['X-API-KEY'] = $apiKey;
            }

            // Try sessionid header
            $sessionId = $request->getHeaderLine('sessionid');
            if ($sessionId) {
                $headers['sessionid'] = $sessionId;
            }
        }

        // Fall back to stdio auth from environment if no headers found
        if (empty($headers) && $this->stdioAuth !== null) {
            if (!empty($this->stdioAuth['bearer_token'])) {
                $headers['Authorization'] = 'Bearer ' . $this->stdioAuth['bearer_token'];
            }
            if (!empty($this->stdioAuth['api_key'])) {
                $headers['X-API-KEY'] = $this->stdioAuth['api_key'];
            }
            if (!empty($this->stdioAuth['session_id'])) {
                $headers['sessionid'] = $this->stdioAuth['session_id'];
            }
        }

        return $headers;
    }
}
