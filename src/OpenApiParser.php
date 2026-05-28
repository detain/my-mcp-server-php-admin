<?php

declare(strict_types=1);

namespace AdminMcp;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses an OpenAPI 3.x YAML/JSON spec fetched from a URL and extracts tool definitions
 * suitable for registering as MCP tools.
 *
 * @phpstan-type ToolDef array{
 *     name: string,
 *     description: string,
 *     httpMethod: string,
 *     path: string,
 *     inputSchema: array<string, mixed>,
 *     pathParams: list<string>,
 *     queryParams: list<string>,
 *     hasBody: bool,
 *     annotations: array<string, mixed>
 * }
 */
class OpenApiParser
{
    /** @var array<string, mixed> */
    private array $spec = [];
    private string $cacheDir;
    private GuzzleClient $httpClient;

    public function __construct(string $cacheDir = '', ?GuzzleClient $httpClient = null)
    {
        $this->cacheDir = $cacheDir ?: sys_get_temp_dir() . '/mcp_admin_cache';
        $this->httpClient = $httpClient ?? new GuzzleClient([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Fetch OpenAPI spec from URL, parse it, and return tool definitions.
     * Cache is refreshed only when the remote spec's Last-Modified header
     * is newer than the cached file. Falls back to stale cache on fetch failure.
     *
     * @return list<ToolDef>
     */
    public function parse(string $specUrl): array
    {
        $cacheFile = $this->cacheDir . '/mcp_tools_' . md5($specUrl) . '.php';

        // Check cache - skip HTTP fetch if cache is at least as new as remote spec
        if (file_exists($cacheFile)) {
            $cacheAge = filemtime($cacheFile);
            $specAge = $this->getRemoteSpecAge($specUrl);

            if ($specAge === null || $cacheAge >= $specAge) {
                return require $cacheFile;
            }
        }

        try {
            $specContent = $this->fetchSpec($specUrl);
        } catch (\RuntimeException $e) {
            // If fetch fails but cache exists, use stale cache
            if (file_exists($cacheFile)) {
                return require $cacheFile;
            }
            throw $e;
        }

        $this->spec = $this->parseSpecContent($specContent);

        $tools = $this->extractTools();

        $export = var_export($tools, true);
        file_put_contents($cacheFile, "<?php\nreturn {$export};\n", LOCK_EX);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($cacheFile, true);
        }

        return $tools;
    }

    /**
     * Clear the cache for a specific spec URL.
     */
    public function clearCache(string $specUrl): void
    {
        $cacheFile = $this->cacheDir . '/mcp_tools_' . md5($specUrl) . '.php';

        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * Fetch the remote spec to get its last-modified time.
     * Returns null if unable to determine.
     */
    private function getRemoteSpecAge(string $specUrl): ?int
    {
        try {
            $response = $this->httpClient->head($specUrl);
            $lastModified = $response->getHeaderLine('Last-Modified');

            if ($lastModified) {
                $timestamp = strtotime($lastModified);
                return $timestamp !== false ? $timestamp : null;
            }
        } catch (GuzzleException) {
            // Ignore - will refresh cache
        }

        return null;
    }

    /**
     * Fetch the OpenAPI spec from URL.
     *
     * @throws \RuntimeException If fetching fails
     */
    private function fetchSpec(string $specUrl): string
    {
        try {
            $response = $this->httpClient->get($specUrl, [
                'headers' => [
                    'Accept' => 'application/json, application/x-yaml, text/yaml, text/x-yaml',
                ],
            ]);

            $content = (string) $response->getBody();

            if (empty($content)) {
                throw new \RuntimeException('Empty response from OpenAPI spec URL');
            }

            return $content;
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to fetch OpenAPI spec: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Parse the spec content (JSON or YAML).
     *
     * @return array<string, mixed>
     */
    private function parseSpecContent(string $content): array
    {
        // Try JSON first
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Fall back to YAML
        $parsed = Yaml::parse($content);
        if (is_array($parsed)) {
            return $parsed;
        }

        throw new \RuntimeException('Unable to parse OpenAPI spec as JSON or YAML');
    }

    /**
     * Walk all paths and operations, producing one tool definition per operation.
     *
     * @return list<ToolDef>
     */
    private function extractTools(): array
    {
        $tools = [];
        $paths = $this->spec['paths'] ?? [];

        foreach ($paths as $path => $pathItem) {
            $sharedParams = $pathItem['parameters'] ?? [];

            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (!isset($pathItem[$method])) {
                    continue;
                }
                $operation = $pathItem[$method];
                $tool = $this->buildToolDefinition($path, $method, $operation, $sharedParams);
                if ($tool !== null) {
                    $tools[] = $tool;
                }
            }
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $operation
     * @param list<array<string, mixed>> $sharedParams
     * @return ToolDef
     */
    private function buildToolDefinition(string $path, string $httpMethod, array $operation, array $sharedParams): array
    {
        $operationId = $operation['operationId'] ?? $this->generateOperationId($path, $httpMethod);
        $method = strtoupper($httpMethod);

        $summary = $operation['summary'] ?? '';
        $description = $operation['description'] ?? '';
        $toolDescription = $summary;
        if ($description && $description !== $summary) {
            $toolDescription .= $toolDescription ? ' — ' : '';
            $toolDescription .= $description;
        }
        if (!$toolDescription) {
            $toolDescription = strtoupper($httpMethod) . ' ' . $path;
        }

        // Inject tag and destructive markers so AI clients can route/gate calls
        $tag = !empty($operation['tags'][0]) ? (string) $operation['tags'][0] : '';
        $isDestructive = $this->isDestructiveOperation($httpMethod, $path, $operationId);
        $prefix = '';
        if ($tag !== '') {
            $prefix .= '[' . $tag . ']';
        }
        if ($isDestructive) {
            $prefix .= ($prefix ? ' ' : '') . '[DESTRUCTIVE]';
        }
        if ($prefix !== '') {
            $toolDescription = $prefix . ' ' . $toolDescription;
        }

        // Truncate descriptions to ~900 chars
        if (mb_strlen($toolDescription) > 900) {
            $hard = mb_substr($toolDescription, 0, 900);
            $cut = max(
                mb_strrpos($hard, '. '),
                mb_strrpos($hard, '? '),
                mb_strrpos($hard, '! '),
                mb_strrpos($hard, "\n\n")
            );
            $toolDescription = ($cut !== false && $cut > 700)
                ? mb_substr($toolDescription, 0, $cut + 1)
                : (mb_substr($toolDescription, 0, 897) . '...');
        }

        // Merge path-level and operation-level parameters
        $allParams = array_merge($sharedParams, $operation['parameters'] ?? []);

        $pathParams = [];
        $queryParams = [];
        $properties = [];
        $required = [];

        foreach ($allParams as $param) {
            $param = $this->resolveRef($param);
            $paramName = $param['name'] ?? '';
            if (!$paramName) {
                continue;
            }

            $paramSchema = $param['schema'] ?? ['type' => 'string'];
            $paramSchema = $this->resolveRef($paramSchema);
            $propDef = $this->simplifySchema($paramSchema);

            if (!empty($param['description'])) {
                $propDef['description'] = $param['description'];
            }

            $in = $param['in'] ?? 'query';
            if ($in === 'path') {
                $pathParams[] = $paramName;
                $required[] = $paramName;
            } elseif ($in === 'query') {
                $queryParams[] = $paramName;
                if (!empty($param['required'])) {
                    $required[] = $paramName;
                }
            } else {
                continue;
            }

            $properties[$paramName] = $propDef;
        }

        // Extract request body schema
        $hasBody = false;
        $bodySchema = $this->extractRequestBodySchema($operation);
        if ($bodySchema !== null) {
            $hasBody = true;
            $bodyProps = $bodySchema['properties'] ?? [];
            $bodyRequired = $bodySchema['required'] ?? [];

            foreach ($bodyProps as $propName => $propDef) {
                $propDef = $this->simplifySchema($propDef);
                $properties[$propName] = $propDef;
            }
            $required = array_merge($required, $bodyRequired);
        }

        $inputSchema = ['type' => 'object'];
        if (!empty($properties)) {
            $inputSchema['properties'] = $properties;
        }
        if (!empty($required)) {
            $inputSchema['required'] = array_values(array_unique($required));
        }

        // MCP 2025-03-26 tool annotations
        $isMutatingGet = ($method === 'GET' && $isDestructive);
        $annotations = [
            'title' => trim((string) ($operation['summary'] ?? $operationId)),
            'readOnlyHint' => ($method === 'GET' && !$isMutatingGet),
            'destructiveHint' => $isDestructive,
            'idempotentHint' => in_array($method, ['GET', 'PUT', 'DELETE'], true) && !$isMutatingGet,
            'openWorldHint' => true,
        ];

        return [
            'name' => $operationId,
            'description' => $toolDescription,
            'httpMethod' => strtoupper($httpMethod),
            'path' => $path,
            'inputSchema' => $inputSchema,
            'pathParams' => $pathParams,
            'queryParams' => $queryParams,
            'hasBody' => $hasBody,
            'annotations' => $annotations,
        ];
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>|null
     */
    private function extractRequestBodySchema(array $operation): ?array
    {
        if (!isset($operation['requestBody'])) {
            return null;
        }

        $body = $this->resolveRef($operation['requestBody']);
        $content = $body['content'] ?? [];

        $schema = null;
        foreach (['application/json', 'multipart/form-data'] as $mediaType) {
            if (isset($content[$mediaType]['schema'])) {
                $schema = $this->resolveRef($content[$mediaType]['schema']);
                break;
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function resolveRef(array $item): array
    {
        if (!isset($item['$ref'])) {
            return $item;
        }

        $ref = $item['$ref'];
        if (!str_starts_with($ref, '#/')) {
            return $item;
        }

        $parts = explode('/', ltrim($ref, '#/'));
        $resolved = $this->spec;
        foreach ($parts as $part) {
            $part = str_replace('~1', '/', str_replace('~0', '~', $part));
            if (!is_array($resolved) || !isset($resolved[$part])) {
                return $item;
            }
            $resolved = $resolved[$part];
        }

        return is_array($resolved) ? $this->resolveRef($resolved) : $item;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function simplifySchema(array $schema): array
    {
        $schema = $this->resolveRef($schema);

        $result = [];

        if (isset($schema['type'])) {
            $result['type'] = $schema['type'];
        }
        if (isset($schema['description'])) {
            $result['description'] = $schema['description'];
        }
        if (isset($schema['enum'])) {
            $result['enum'] = $schema['enum'];
        }
        if (isset($schema['format'])) {
            $result['format'] = $schema['format'];
        }
        if (isset($schema['minimum'])) {
            $result['minimum'] = $schema['minimum'];
        }
        if (isset($schema['maximum'])) {
            $result['maximum'] = $schema['maximum'];
        }
        if (isset($schema['minLength'])) {
            $result['minLength'] = $schema['minLength'];
        }
        if (isset($schema['maxLength'])) {
            $result['maxLength'] = $schema['maxLength'];
        }
        if (isset($schema['pattern'])) {
            $result['pattern'] = $schema['pattern'];
        }
        if (isset($schema['default'])) {
            $result['default'] = $schema['default'];
        }
        if (isset($schema['nullable']) && $schema['nullable']) {
            $result['nullable'] = true;
        }
        if (isset($schema['example'])) {
            $result['example'] = $schema['example'];
        }
        if (isset($schema['examples']) && is_array($schema['examples'])) {
            $result['examples'] = $schema['examples'];
        }

        if (($schema['type'] ?? '') === 'object' && isset($schema['properties'])) {
            $result['properties'] = [];
            foreach ($schema['properties'] as $key => $propSchema) {
                $result['properties'][$key] = $this->simplifySchema($propSchema);
            }
            if (isset($schema['required'])) {
                $result['required'] = $schema['required'];
            }
        }

        if (($schema['type'] ?? '') === 'array' && isset($schema['items'])) {
            $result['items'] = $this->simplifySchema($schema['items']);
        }

        return $result ?: ['type' => 'string'];
    }

    private function isDestructiveOperation(string $httpMethod, string $path, string $operationId): bool
    {
        $method = strtoupper($httpMethod);
        if ($method === 'DELETE') {
            return true;
        }

        $lowerPath = strtolower($path);

        if (str_contains($lowerPath, '/admin/orders/')) {
            return true;
        }

        if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH'], true)) {
            $destructivePathTerms = [
                'cancel', 'delete', 'refund', 'purge', 'wipe', 'remove',
                'destroy', 'reinstall', 'reset_password', 'change_root_password',
                'change_password', 'mark_fraud', 'disable', 'suspend',
                'restore', 'change_ip', 'migration', 'ipmi_power', 'powerstrip',
                'null_routes', 'clean_login_logs', 'switch_port', 'switchport_config',
                'mass_email', 'buy_hd_space', 'buy_ip',
            ];
            foreach ($destructivePathTerms as $term) {
                if (str_contains($lowerPath, $term)) {
                    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                        return true;
                    }
                    if (in_array($term, ['reset_password', 'change_password', 'change_root_password', 'reinstall', 'restore', 'ipmi_power', 'powerstrip'], true)) {
                        return true;
                    }
                }
            }
        }

        if ($operationId !== '') {
            $destructiveIdPatterns = '/^admin('
                . 'Cancel|Delete|Refund|Reassign|Suspend|Wipe|Purge|Remove|'
                . 'ResetPassword|ResetMailPassword|ReinstallOs|MarkFraud|Destroy|'
                . 'Restore|Migrate|MassEmail|ApcPower|Apc(Setup|Powerstrip)|'
                . 'IpmiPower|ChangeIp|ChangePassword|ChangeRootPassword|'
                . 'CleanLoginLogs|Order|ManageSwitchPort|AddNullRoute|'
                . 'ServerIpmiPower|BuyHdSpace|BuyIp|ForceDelete'
                . ')/i';
            if (preg_match($destructiveIdPatterns, $operationId)) {
                return true;
            }
        }

        return false;
    }

    private function generateOperationId(string $path, string $method): string
    {
        $parts = array_filter(explode('/', $path));
        $name = $method;
        foreach ($parts as $part) {
            if (str_starts_with($part, '{')) {
                continue;
            }
            $name .= '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $part);
        }
        return $name;
    }
}
