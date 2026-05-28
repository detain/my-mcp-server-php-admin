<?php

declare(strict_types=1);

namespace AdminMcp\Tests\Unit;

use AdminMcp\OpenApiParser;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AdminMcp\OpenApiParser
 */
final class OpenApiParserTest extends TestCase
{
    private string $cacheDir = '';

    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $recordedRequests = [];

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/oapi-parser-test-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir);
        $this->recordedRequests = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    private function clientFromResponses(Response ...$responses): GuzzleClient
    {
        $handler = new MockHandler($responses);
        $stack = HandlerStack::create($handler);
        $stack->push(function (callable $next) {
            return function (Psr7Request $req, array $options) use ($next) {
                $this->recordedRequests[] = $req;
                return $next($req, $options);
            };
        });
        return new GuzzleClient(['handler' => $stack]);
    }

    private function specJson(array $paths, array $components = []): string
    {
        $spec = ['openapi' => '3.0.0', 'info' => ['title' => 't', 'version' => '1'], 'paths' => $paths];
        if ($components !== []) {
            $spec['components'] = $components;
        }
        return (string) json_encode($spec);
    }

    // ---------------------------------------------------------------------
    // Construction / defaults
    // ---------------------------------------------------------------------

    public function testConstructorUsesSysTempDirByDefault(): void
    {
        $parser = new OpenApiParser();
        $ref = new \ReflectionProperty($parser, 'cacheDir');
        $this->assertSame(sys_get_temp_dir() . '/mcp_admin_cache', $ref->getValue($parser));
    }

    public function testConstructorUsesProvidedCacheDir(): void
    {
        $parser = new OpenApiParser('/tmp/explicit');
        $ref = new \ReflectionProperty($parser, 'cacheDir');
        $this->assertSame('/tmp/explicit', $ref->getValue($parser));
    }

    public function testConstructorBuildsDefaultHttpClientIfNoneProvided(): void
    {
        $parser = new OpenApiParser($this->cacheDir);
        $ref = new \ReflectionProperty($parser, 'httpClient');
        $this->assertInstanceOf(GuzzleClient::class, $ref->getValue($parser));
    }

    // ---------------------------------------------------------------------
    // parse() — JSON / YAML detection
    // ---------------------------------------------------------------------

    public function testParsesJsonSpec(): void
    {
        $spec = $this->specJson(['/ping' => ['get' => ['operationId' => 'ping', 'summary' => 'Health']]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));

        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/openapi.json');

        $this->assertCount(1, $tools);
        $this->assertSame('ping', $tools[0]['name']);
        $this->assertSame('GET', $tools[0]['httpMethod']);
        $this->assertSame('/ping', $tools[0]['path']);
    }

    public function testParsesYamlSpec(): void
    {
        $yaml = file_get_contents(__DIR__ . '/../Fixtures/openapi-minimal.yaml');
        $client = $this->clientFromResponses(new Response(200, [], $yaml));

        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/openapi.yaml');

        $this->assertCount(1, $tools);
        $this->assertSame('ping', $tools[0]['name']);
    }

    public function testThrowsOnEmptyResponse(): void
    {
        $client = $this->clientFromResponses(new Response(200, [], ''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Empty response');

        (new OpenApiParser($this->cacheDir, $client))->parse('http://x/empty');
    }

    public function testThrowsOnMalformedSpec(): void
    {
        $client = $this->clientFromResponses(new Response(200, [], 'not json: [also: not: yaml'));

        $this->expectException(\Symfony\Component\Yaml\Exception\ParseException::class);

        (new OpenApiParser($this->cacheDir, $client))->parse('http://x/garbage');
    }

    public function testThrowsOnNonArrayJsonScalar(): void
    {
        // JSON scalar (a string) is valid JSON but not a valid spec — falls through to YAML
        // which parses it as a string scalar; then the array-check fires.
        $client = $this->clientFromResponses(new Response(200, [], '"just-a-string"'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to parse OpenAPI spec');

        (new OpenApiParser($this->cacheDir, $client))->parse('http://x/scalar');
    }

    public function testHttpFetchFailureWrapsAsRuntimeException(): void
    {
        $client = $this->clientFromResponses(
            new Response(500, [], 'server explode')
        );
        // Force the GET to actually throw by using a 5xx + http_errors default
        // Actually Guzzle won't throw on 200/500 unless http_errors=true; we pass a real network failure
        $handler = new MockHandler([new \GuzzleHttp\Exception\ConnectException(
            'no route',
            new Psr7Request('GET', 'http://x')
        )]);
        $client = new GuzzleClient(['handler' => HandlerStack::create($handler)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch OpenAPI spec');

        (new OpenApiParser($this->cacheDir, $client))->parse('http://x/explode');
    }

    // ---------------------------------------------------------------------
    // Caching: HEAD-based conditional refresh + stale fallback
    // ---------------------------------------------------------------------

    public function testFirstCallFetchesAndCaches(): void
    {
        $spec = $this->specJson(['/a' => ['get' => ['operationId' => 'a']]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));

        (new OpenApiParser($this->cacheDir, $client))->parse('http://x/spec');

        $this->assertNotEmpty(glob($this->cacheDir . '/mcp_tools_*.php'));
        $this->assertCount(1, $this->recordedRequests);
        $this->assertSame('GET', $this->recordedRequests[0]->getMethod());
    }

    public function testSecondCallUsesCacheWhenRemoteUnchanged(): void
    {
        $spec = $this->specJson(['/a' => ['get' => ['operationId' => 'a']]]);
        // 1st GET (cache miss → fetch), then HEAD with old Last-Modified (cache hit, no second GET)
        $client = $this->clientFromResponses(
            new Response(200, [], $spec),
            new Response(200, ['Last-Modified' => gmdate('D, d M Y H:i:s', time() - 3600) . ' GMT'])
        );

        $p = new OpenApiParser($this->cacheDir, $client);
        $p->parse('http://x/spec');
        $tools = $p->parse('http://x/spec');

        $this->assertCount(1, $tools);
        $this->assertCount(2, $this->recordedRequests);
        $this->assertSame('GET',  $this->recordedRequests[0]->getMethod());
        $this->assertSame('HEAD', $this->recordedRequests[1]->getMethod());
    }

    public function testSecondCallRefetchesWhenRemoteIsNewer(): void
    {
        $spec = $this->specJson(['/a' => ['get' => ['operationId' => 'a']]]);
        $client = $this->clientFromResponses(
            new Response(200, [], $spec),
            new Response(200, ['Last-Modified' => gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT']),
            new Response(200, [], $spec),
        );

        $p = new OpenApiParser($this->cacheDir, $client);
        $p->parse('http://x/spec');
        $p->parse('http://x/spec');

        $this->assertCount(3, $this->recordedRequests);
        $this->assertSame('GET',  $this->recordedRequests[0]->getMethod());
        $this->assertSame('HEAD', $this->recordedRequests[1]->getMethod());
        $this->assertSame('GET',  $this->recordedRequests[2]->getMethod());
    }

    public function testSecondCallUsesCacheWhenLastModifiedMissing(): void
    {
        $spec = $this->specJson(['/a' => ['get' => ['operationId' => 'a']]]);
        $client = $this->clientFromResponses(
            new Response(200, [], $spec),
            new Response(200), // no Last-Modified header
        );

        $p = new OpenApiParser($this->cacheDir, $client);
        $p->parse('http://x/spec');
        $tools = $p->parse('http://x/spec');

        $this->assertCount(1, $tools);
        $this->assertCount(2, $this->recordedRequests);
    }

    public function testSecondCallUsesCacheWhenHeadFails(): void
    {
        $spec = $this->specJson(['/a' => ['get' => ['operationId' => 'a']]]);
        $handler = new MockHandler([
            new Response(200, [], $spec),
            new \GuzzleHttp\Exception\ConnectException('boom', new Psr7Request('HEAD', 'http://x')),
        ]);
        $client = new GuzzleClient(['handler' => HandlerStack::create($handler)]);

        $p = new OpenApiParser($this->cacheDir, $client);
        $p->parse('http://x/spec');
        $tools = $p->parse('http://x/spec'); // HEAD throws → returns null → use cache

        $this->assertCount(1, $tools);
    }

    public function testSecondCallUsesCacheWhenLastModifiedUnparseable(): void
    {
        $spec = $this->specJson(['/a' => ['get' => ['operationId' => 'a']]]);
        $client = $this->clientFromResponses(
            new Response(200, [], $spec),
            new Response(200, ['Last-Modified' => 'not a date']),
        );

        $p = new OpenApiParser($this->cacheDir, $client);
        $p->parse('http://x/spec');
        $tools = $p->parse('http://x/spec');

        $this->assertCount(1, $tools);
    }

    public function testStaleCacheFallbackOnFetchFailure(): void
    {
        $spec = $this->specJson(['/a' => ['get' => ['operationId' => 'a']]]);
        $handler = new MockHandler([
            new Response(200, [], $spec),
            // Next request is the refresh GET (we'll force fresh by deleting then re-call without HEAD)
        ]);
        $client = new GuzzleClient(['handler' => HandlerStack::create($handler)]);

        // First call populates cache
        $p = new OpenApiParser($this->cacheDir, $client);
        $p->parse('http://x/spec');

        // Now make the next GET fail. Cache file still exists.
        $handler->reset();
        $handler->append(new \GuzzleHttp\Exception\ConnectException('down', new Psr7Request('GET', 'http://x')));

        // Touch the cache file to be NEWER than now-future to trigger refresh... actually simpler:
        // delete cache so first branch (no cache) goes straight to GET, which fails — but then we
        // need stale cache to exist. So instead, force the cache to look stale by mocking HEAD to
        // return newer Last-Modified, then GET fails.
        $handler->reset();
        $handler->append(new Response(200, ['Last-Modified' => gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT']));
        $handler->append(new \GuzzleHttp\Exception\ConnectException('down', new Psr7Request('GET', 'http://x')));

        $tools = $p->parse('http://x/spec');

        $this->assertCount(1, $tools); // stale cache returned
        $this->assertSame('a', $tools[0]['name']);
    }

    public function testThrowsWhenFetchFailsAndNoCache(): void
    {
        $handler = new MockHandler([
            new \GuzzleHttp\Exception\ConnectException('down', new Psr7Request('GET', 'http://x')),
        ]);
        $client = new GuzzleClient(['handler' => HandlerStack::create($handler)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch OpenAPI spec');

        (new OpenApiParser($this->cacheDir, $client))->parse('http://x/spec');
    }

    public function testClearCacheRemovesFile(): void
    {
        $spec = $this->specJson(['/a' => ['get' => ['operationId' => 'a']]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));

        $p = new OpenApiParser($this->cacheDir, $client);
        $p->parse('http://x/spec');
        $this->assertNotEmpty(glob($this->cacheDir . '/mcp_tools_*.php'));

        $p->clearCache('http://x/spec');
        $this->assertEmpty(glob($this->cacheDir . '/mcp_tools_*.php'));
    }

    public function testClearCacheIsNoOpWhenFileMissing(): void
    {
        $p = new OpenApiParser($this->cacheDir);
        $p->clearCache('http://nothing.example/spec');
        $this->assertEmpty(glob($this->cacheDir . '/mcp_tools_*.php'));
    }

    // ---------------------------------------------------------------------
    // Tool extraction — uses the kitchen-sink fixture
    // ---------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function parseFixture(): array
    {
        $client = $this->clientFromResponses(new Response(200, [], file_get_contents(__DIR__ . '/../Fixtures/openapi-full.json')));
        return (new OpenApiParser($this->cacheDir, $client))->parse('http://x/full.json');
    }

    /** @return array<string, mixed>|null */
    private function findTool(array $tools, string $name): ?array
    {
        foreach ($tools as $t) {
            if ($t['name'] === $name) {
                return $t;
            }
        }
        return null;
    }

    public function testExtractsAllOperationsFromMultiplePaths(): void
    {
        $tools = $this->parseFixture();
        $names = array_column($tools, 'name');
        $this->assertContains('listUsers', $names);
        $this->assertContains('createUser', $names);
        $this->assertContains('getUser', $names);
        $this->assertContains('deleteUser', $names);
        $this->assertContains('adminCancelOrder', $names);
    }

    public function testGeneratesOperationIdWhenMissing(): void
    {
        $tools = $this->parseFixture();
        $names = array_column($tools, 'name');
        $this->assertContains('get_no_op_id', $names);
    }

    public function testMergesSummaryAndDescription(): void
    {
        $t = $this->findTool($this->parseFixture(), 'createUser');
        $this->assertNotNull($t);
        $this->assertStringContainsString('Create user', $t['description']);
        $this->assertStringContainsString('Adds a new user', $t['description']);
        $this->assertStringContainsString('—', $t['description']);
    }

    public function testFallsBackToMethodPathWhenNoSummaryOrDescription(): void
    {
        $t = $this->findTool($this->parseFixture(), 'get_no_op_id');
        $this->assertNotNull($t);
        $this->assertStringContainsString('GET /no-op-id', $t['description']);
    }

    public function testTagPrefixInDescription(): void
    {
        $t = $this->findTool($this->parseFixture(), 'listUsers');
        $this->assertNotNull($t);
        $this->assertStringStartsWith('[users]', $t['description']);
    }

    public function testDestructivePrefixInDescription(): void
    {
        $t = $this->findTool($this->parseFixture(), 'deleteUser');
        $this->assertNotNull($t);
        $this->assertStringContainsString('[DESTRUCTIVE]', $t['description']);
    }

    public function testLongDescriptionsTruncated(): void
    {
        $longDesc = str_repeat('Lorem ipsum dolor sit amet. ', 200);
        $client = $this->clientFromResponses(new Response(200, [], $this->specJson([
            '/long' => ['get' => ['operationId' => 'long', 'description' => $longDesc]],
        ])));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/long');
        $this->assertLessThanOrEqual(910, mb_strlen($tools[0]['description']));
    }

    public function testHardTruncationFallbackWhenNoSentenceBoundary(): void
    {
        // 1000 chars with no '. ' / '? ' / '! ' / '\n\n' after pos 700
        $longDesc = str_repeat('x', 1000);
        $client = $this->clientFromResponses(new Response(200, [], $this->specJson([
            '/long' => ['get' => ['operationId' => 'long', 'description' => $longDesc]],
        ])));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/long');
        $this->assertStringEndsWith('...', $tools[0]['description']);
    }

    public function testPathAndQueryParameterExtraction(): void
    {
        $t = $this->findTool($this->parseFixture(), 'listUsers');
        $this->assertNotNull($t);
        $this->assertSame([], $t['pathParams']);
        // listUsers has `page` (path-level) + `limit` (op-level); header param is filtered out.
        $this->assertContains('limit', $t['queryParams']);
        $this->assertContains('page', $t['queryParams']);
        $this->assertArrayHasKey('properties', $t['inputSchema']);
        $this->assertArrayNotHasKey('X-Trace-Id', $t['inputSchema']['properties']);
    }

    public function testPathParameterIsRequired(): void
    {
        $t = $this->findTool($this->parseFixture(), 'getUser');
        $this->assertNotNull($t);
        $this->assertSame(['id'], $t['pathParams']);
        $this->assertContains('id', $t['inputSchema']['required']);
    }

    public function testRequestBodyMergedIntoInputSchema(): void
    {
        $t = $this->findTool($this->parseFixture(), 'createUser');
        $this->assertNotNull($t);
        $this->assertTrue($t['hasBody']);
        $props = $t['inputSchema']['properties'];
        $this->assertArrayHasKey('email', $props);
        $this->assertArrayHasKey('name', $props);
        $this->assertArrayHasKey('tags', $props);
        $this->assertArrayHasKey('role', $props);
        $this->assertSame(['admin', 'user'], $props['role']['enum']);
        $this->assertContains('email', $t['inputSchema']['required']);
    }

    public function testNestedObjectAndArraySchemas(): void
    {
        $t = $this->findTool($this->parseFixture(), 'createUser');
        $this->assertNotNull($t);
        $props = $t['inputSchema']['properties'];
        $this->assertSame('array', $props['tags']['type']);
        $this->assertSame('string', $props['tags']['items']['type']);
        $this->assertSame('object', $props['address']['type']);
        $this->assertArrayHasKey('street', $props['address']['properties']);
    }

    public function testMultipartFormBodyExtracted(): void
    {
        $t = $this->findTool($this->parseFixture(), 'multipartUpload');
        $this->assertNotNull($t);
        $this->assertTrue($t['hasBody']);
        $this->assertArrayHasKey('file', $t['inputSchema']['properties']);
    }

    public function testAnnotationsForGet(): void
    {
        $t = $this->findTool($this->parseFixture(), 'getUser');
        $this->assertNotNull($t);
        $this->assertTrue($t['annotations']['readOnlyHint']);
        $this->assertFalse($t['annotations']['destructiveHint']);
        $this->assertTrue($t['annotations']['idempotentHint']);
        $this->assertTrue($t['annotations']['openWorldHint']);
    }

    public function testAnnotationsForDelete(): void
    {
        $t = $this->findTool($this->parseFixture(), 'deleteUser');
        $this->assertNotNull($t);
        $this->assertFalse($t['annotations']['readOnlyHint']);
        $this->assertTrue($t['annotations']['destructiveHint']);
        $this->assertTrue($t['annotations']['idempotentHint']);
    }

    public function testAdminOrdersPathIsDestructive(): void
    {
        $t = $this->findTool($this->parseFixture(), 'adminCancelOrder');
        $this->assertNotNull($t);
        $this->assertTrue($t['annotations']['destructiveHint']);
        $this->assertStringContainsString('[DESTRUCTIVE]', $t['description']);
    }

    public function testResetPasswordIsDestructiveEvenAsGet(): void
    {
        // The fixture has a POST /vps/{id}/reset_password — covers the POST branch.
        // Also verify the GET-with-destructive-term branch via inline spec:
        $client = $this->clientFromResponses(new Response(200, [], $this->specJson([
            '/foo/reset_password' => ['get' => ['operationId' => 'foo']],
        ])));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/destructive-get');
        $this->assertTrue($tools[0]['annotations']['destructiveHint']);
    }

    public function testNonDestructiveOperationHasNoDestructiveHint(): void
    {
        $t = $this->findTool($this->parseFixture(), 'getVpsDetails');
        $this->assertNotNull($t);
        $this->assertFalse($t['annotations']['destructiveHint']);
    }

    public function testDestructiveOperationIdPatternMatches(): void
    {
        // /benign path + destructive operationId
        $client = $this->clientFromResponses(new Response(200, [], $this->specJson([
            '/benign' => ['post' => ['operationId' => 'adminWipeAccount']],
        ])));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/idpattern');
        $this->assertTrue($tools[0]['annotations']['destructiveHint']);
    }

    public function testRefResolutionFallsBackOnMissingPointer(): void
    {
        $spec = $this->specJson(
            ['/x' => ['get' => ['operationId' => 'x', 'parameters' => [
                ['name' => 'id', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/Missing']],
            ]]]],
            ['schemas' => ['Other' => ['type' => 'string']]]
        );
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/badref');
        $this->assertSame('x', $tools[0]['name']);
        $this->assertArrayHasKey('id', $tools[0]['inputSchema']['properties']);
    }

    public function testRefResolutionRejectsNonInternalRefs(): void
    {
        $spec = $this->specJson(['/x' => ['get' => ['operationId' => 'x', 'parameters' => [
            ['name' => 'p', 'in' => 'query', 'schema' => ['$ref' => 'https://example.com/external.yaml#/x']],
        ]]]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/extref');
        $this->assertSame('x', $tools[0]['name']);
    }

    public function testRefResolutionDecodesJsonPointerEscapes(): void
    {
        // Pointer with ~1 → '/' escape
        $spec = $this->specJson(
            ['/x' => ['get' => ['operationId' => 'x', 'parameters' => [
                ['name' => 'p', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/with~1slash']],
            ]]]],
            ['schemas' => ['with/slash' => ['type' => 'integer', 'description' => 'escaped']]]
        );
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/ptrescape');
        $this->assertSame('integer', $tools[0]['inputSchema']['properties']['p']['type']);
    }

    public function testParameterWithoutNameIsSkipped(): void
    {
        $spec = $this->specJson(['/x' => ['get' => ['operationId' => 'x', 'parameters' => [
            ['in' => 'query', 'schema' => ['type' => 'string']],          // no name
            ['name' => 'ok', 'in' => 'query', 'schema' => ['type' => 'string']],
        ]]]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/noname');
        $this->assertArrayHasKey('ok', $tools[0]['inputSchema']['properties']);
        $this->assertCount(1, $tools[0]['inputSchema']['properties']);
    }

    public function testParameterDescriptionPropagates(): void
    {
        $spec = $this->specJson(['/x' => ['get' => ['operationId' => 'x', 'parameters' => [
            ['name' => 'q', 'in' => 'query', 'description' => 'a search term', 'schema' => ['type' => 'string']],
        ]]]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/paramdesc');
        $this->assertSame('a search term', $tools[0]['inputSchema']['properties']['q']['description']);
    }

    public function testSimplifySchemaCarriesNumericAndFormatFields(): void
    {
        $spec = $this->specJson(['/x' => ['get' => ['operationId' => 'x', 'parameters' => [
            ['name' => 'p', 'in' => 'query', 'schema' => [
                'type' => 'integer', 'format' => 'int32',
                'minimum' => 1, 'maximum' => 100, 'default' => 10,
            ]],
            ['name' => 's', 'in' => 'query', 'schema' => [
                'type' => 'string', 'minLength' => 3, 'maxLength' => 20, 'pattern' => '^x',
                'nullable' => true, 'example' => 'x1', 'examples' => ['x1', 'x2'],
            ]],
        ]]]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/simplify');
        $p = $tools[0]['inputSchema']['properties']['p'];
        $s = $tools[0]['inputSchema']['properties']['s'];
        $this->assertSame('int32', $p['format']);
        $this->assertSame(1, $p['minimum']);
        $this->assertSame(100, $p['maximum']);
        $this->assertSame(10, $p['default']);
        $this->assertSame(3, $s['minLength']);
        $this->assertSame(20, $s['maxLength']);
        $this->assertSame('^x', $s['pattern']);
        $this->assertTrue($s['nullable']);
        $this->assertSame('x1', $s['example']);
        $this->assertSame(['x1', 'x2'], $s['examples']);
    }

    public function testEmptySimplifySchemaDefaultsToStringType(): void
    {
        $spec = $this->specJson(['/x' => ['get' => ['operationId' => 'x', 'parameters' => [
            ['name' => 'p', 'in' => 'query', 'schema' => []],
        ]]]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/emptyschema');
        $this->assertSame(['type' => 'string'], $tools[0]['inputSchema']['properties']['p']);
    }

    public function testRequestBodyMissingReturnsNoBody(): void
    {
        $spec = $this->specJson(['/x' => ['get' => ['operationId' => 'x']]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/nobody');
        $this->assertFalse($tools[0]['hasBody']);
    }

    public function testInputSchemaWithoutPropertiesOrRequired(): void
    {
        $spec = $this->specJson(['/x' => ['get' => ['operationId' => 'x']]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/noprops');
        $this->assertSame(['type' => 'object'], $tools[0]['inputSchema']);
    }

    public function testRequiredQueryParameterIsMarkedRequired(): void
    {
        $spec = $this->specJson(['/x' => ['get' => ['operationId' => 'x', 'parameters' => [
            ['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
        ]]]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/requiredq');
        $this->assertContains('q', $tools[0]['inputSchema']['required']);
    }

    public function testNestedObjectPreservesRequiredArray(): void
    {
        $spec = $this->specJson(
            ['/x' => ['post' => ['operationId' => 'x', 'requestBody' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'addr' => [
                        'type' => 'object',
                        'required' => ['street'],
                        'properties' => [
                            'street' => ['type' => 'string'],
                            'city'   => ['type' => 'string'],
                        ],
                    ],
                ],
            ]]]]]]]
        );
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/nestreq');
        $this->assertSame(['street'], $tools[0]['inputSchema']['properties']['addr']['required']);
    }

    public function testGeneratedOperationIdSkipsPathParameters(): void
    {
        $spec = $this->specJson(['/users/{id}/posts/{postId}' => ['get' => []]]);
        $client = $this->clientFromResponses(new Response(200, [], $spec));
        $tools = (new OpenApiParser($this->cacheDir, $client))->parse('http://x/skipparams');
        // Should be "get_users_posts" — path params skipped
        $this->assertSame('get_users_posts', $tools[0]['name']);
    }
}
