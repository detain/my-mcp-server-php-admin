<?php

declare(strict_types=1);

namespace AdminMcp\Tests\Unit;

use AdminMcp\McpServerFactory;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AdminMcp\McpServerFactory
 */
final class McpServerFactoryTest extends TestCase
{
    /** @var \ArrayObject<int, \Psr\Http\Message\RequestInterface> */
    private \ArrayObject $recorder;
    private MockHandler $mock;
    private McpServerFactory $factory;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $this->recorder = new \ArrayObject();
        $stack = HandlerStack::create($this->mock);
        $stack->push(function (callable $next) {
            return function (Psr7Request $req, array $options) use ($next) {
                $this->recorder[] = $req;
                return $next($req, $options);
            };
        });
        $client = new GuzzleClient(['handler' => $stack, 'http_errors' => false]);

        $this->factory = new McpServerFactory('https://api.example/v1');
        $this->factory->setHttpClientFactory(fn () => $client);
    }

    /**
     * Build a synthetic context stub. The SDK uses a final RequestContext
     * with a typed Request — too heavy for unit tests. The closure only
     * calls $ctx->getRequest()->arguments and getHeaderLine() via
     * method_exists(), so a structural stub suffices.
     */
    private function makeContext(array $arguments = [], array $headers = []): object
    {
        $request = new class($arguments, $headers) {
            /** @var array<string, mixed> */
            public array $arguments;
            /** @var array<string, string> */
            private array $headers;
            public function __construct(array $arguments, array $headers)
            {
                $this->arguments = $arguments;
                $this->headers = array_change_key_case($headers, CASE_LOWER);
            }
            public function getHeaderLine(string $name): string
            {
                return $this->headers[strtolower($name)] ?? '';
            }
        };

        return new class($request) {
            private object $request;
            public function __construct(object $request) { $this->request = $request; }
            public function getRequest(): object { return $this->request; }
        };
    }

    private function invokeHandler(array $toolDef, object $ctx): mixed
    {
        $m = (new \ReflectionClass($this->factory))->getMethod('createHandler');
        $m->setAccessible(true);
        $handler = $m->invoke($this->factory, $toolDef);
        return $handler($ctx);
    }

    private function basicToolDef(array $overrides = []): array
    {
        return array_merge([
            'name'        => 'echo',
            'description' => 'echo',
            'httpMethod'  => 'GET',
            'path'        => '/echo',
            'inputSchema' => ['type' => 'object'],
            'pathParams'  => [],
            'queryParams' => [],
            'hasBody'     => false,
            'annotations' => [],
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // Setters / constructor
    // ---------------------------------------------------------------------

    public function testConstructorTrimsTrailingSlashFromBaseUrl(): void
    {
        $f = new McpServerFactory('https://api.example/v1/');
        $ref = new \ReflectionProperty($f, 'apiBaseUrl');
        $this->assertSame('https://api.example/v1', $ref->getValue($f));
    }

    public function testSetStdioAuthStoresCredentials(): void
    {
        $f = new McpServerFactory('https://api.example');
        $f->setStdioAuth(['api_key' => 'k1']);
        $ref = new \ReflectionProperty($f, 'stdioAuth');
        $this->assertSame(['api_key' => 'k1'], $ref->getValue($f));
    }

    public function testSetSslVerifyAcceptsBoolean(): void
    {
        $f = new McpServerFactory('https://api.example');
        $f->setSslVerify(false);
        $ref = new \ReflectionProperty($f, 'sslVerify');
        $this->assertFalse($ref->getValue($f));
    }

    public function testSetSslVerifyAcceptsCaBundlePath(): void
    {
        $f = new McpServerFactory('https://api.example');
        $f->setSslVerify('/etc/ssl/ca.pem');
        $ref = new \ReflectionProperty($f, 'sslVerify');
        $this->assertSame('/etc/ssl/ca.pem', $ref->getValue($f));
    }

    public function testSetHttpClientFactoryStoresCallable(): void
    {
        $f = new McpServerFactory('https://api.example');
        $f->setHttpClientFactory(fn () => new GuzzleClient());
        $ref = new \ReflectionProperty($f, 'httpClientFactory');
        $this->assertIsCallable($ref->getValue($f));
    }

    public function testHttpClientFactoryReceivesSslVerify(): void
    {
        $received = null;
        $this->factory->setSslVerify('/path/to/ca.pem');
        $this->factory->setHttpClientFactory(function ($verify) use (&$received) {
            $received = $verify;
            $mock = new MockHandler([new Response(200, [], '{"ok":true}')]);
            return new GuzzleClient(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        });

        $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertSame('/path/to/ca.pem', $received);
    }

    public function testHttpClientFactoryReceivesDefaultSslVerifyTrue(): void
    {
        $received = 'unset';
        $this->factory->setHttpClientFactory(function ($verify) use (&$received) {
            $received = $verify;
            $mock = new MockHandler([new Response(200, [], '{}')]);
            return new GuzzleClient(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        });

        $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertTrue($received);
    }

    // ---------------------------------------------------------------------
    // build() — server construction
    // ---------------------------------------------------------------------

    public function testBuildReturnsServerInstance(): void
    {
        $server = $this->factory->build('test', '1.0.0', []);
        $this->assertInstanceOf(\Mcp\Server::class, $server);
    }

    public function testBuildRegistersTools(): void
    {
        $server = $this->factory->build('test', '1.0.0', [
            $this->basicToolDef(['name' => 'tool1']),
            $this->basicToolDef(['name' => 'tool2', 'annotations' => ['readOnlyHint' => true]]),
        ]);
        $this->assertInstanceOf(\Mcp\Server::class, $server);
    }

    public function testBuildAcceptsToolDefsWithoutAnnotations(): void
    {
        $td = $this->basicToolDef();
        unset($td['annotations']);
        $server = $this->factory->build('test', '1.0.0', [$td]);
        $this->assertInstanceOf(\Mcp\Server::class, $server);
    }

    public function testBuildRegistersSessionStoreWhenProvided(): void
    {
        $sessionStore = $this->createMock(\Mcp\Server\Session\SessionStoreInterface::class);
        $server = $this->factory->build('test', '1.0.0', [], $sessionStore);
        $this->assertInstanceOf(\Mcp\Server::class, $server);
    }

    public function testHandlerFallsBackToDefaultGuzzleClientWhenFactoryUnset(): void
    {
        // Default GuzzleClient construction path — make a request to a non-routable
        // address so it fails fast, then assert the closure caught it.
        $f = new McpServerFactory('http://127.0.0.1:1');  // port 1 is privileged + unused
        $m = (new \ReflectionClass($f))->getMethod('createHandler');
        $m->setAccessible(true);
        $handler = $m->invoke($f, $this->basicToolDef(['path' => '/x']));
        $result = $handler($this->makeContext());
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('API request failed', $result['error']);
    }

    // ---------------------------------------------------------------------
    // Handler closure — request shaping
    // ---------------------------------------------------------------------

    public function testHandlerSubstitutesPathParameters(): void
    {
        $this->mock->append(new Response(200, [], '{"ok":true}'));

        $this->invokeHandler($this->basicToolDef([
            'path'       => '/users/{id}',
            'pathParams' => ['id'],
        ]), $this->makeContext(['id' => 42]));

        $this->assertStringEndsWith('/users/42', (string) $this->recorder[0]->getUri());
    }

    public function testHandlerUrlEncodesPathParameters(): void
    {
        $this->mock->append(new Response(200, [], '{}'));

        $this->invokeHandler($this->basicToolDef([
            'path'       => '/items/{key}',
            'pathParams' => ['key'],
        ]), $this->makeContext(['key' => 'a/b c']));

        $this->assertStringEndsWith('/items/a%2Fb%20c', (string) $this->recorder[0]->getUri());
    }

    public function testHandlerLeavesUnresolvedPathParamsUntouched(): void
    {
        $this->mock->append(new Response(200, [], '{}'));

        $this->invokeHandler($this->basicToolDef([
            'path'       => '/x/{id}',
            'pathParams' => ['id'],
        ]), $this->makeContext([])); // no id provided

        $this->assertStringEndsWith('/x/%7Bid%7D', (string) $this->recorder[0]->getUri());
    }

    public function testHandlerBuildsQueryStringFromArguments(): void
    {
        $this->mock->append(new Response(200, [], '{}'));

        $this->invokeHandler($this->basicToolDef([
            'queryParams' => ['limit', 'page'],
        ]), $this->makeContext(['limit' => 10, 'page' => 2]));

        parse_str($this->recorder[0]->getUri()->getQuery(), $q);
        $this->assertSame('10', $q['limit']);
        $this->assertSame('2',  $q['page']);
    }

    public function testHandlerSkipsQueryParamsWhenArgumentMissing(): void
    {
        $this->mock->append(new Response(200, [], '{}'));

        $this->invokeHandler($this->basicToolDef([
            'queryParams' => ['limit', 'page'],
        ]), $this->makeContext(['limit' => 5])); // no page

        parse_str($this->recorder[0]->getUri()->getQuery(), $q);
        $this->assertSame('5', $q['limit']);
        $this->assertArrayNotHasKey('page', $q);
    }

    public function testHandlerSendsJsonBodyExcludingPathAndQueryParams(): void
    {
        $this->mock->append(new Response(200, [], '{}'));

        $this->invokeHandler($this->basicToolDef([
            'httpMethod'  => 'POST',
            'path'        => '/users/{id}',
            'pathParams'  => ['id'],
            'queryParams' => ['ts'],
            'hasBody'     => true,
        ]), $this->makeContext(['id' => 1, 'ts' => 'now', 'email' => 'a@b.com', 'name' => 'A']));

        $body = json_decode((string) $this->recorder[0]->getBody(), true);
        $this->assertSame(['email' => 'a@b.com', 'name' => 'A'], $body);
    }

    public function testHandlerOmitsEmptyBody(): void
    {
        $this->mock->append(new Response(200, [], '{}'));

        $this->invokeHandler($this->basicToolDef([
            'httpMethod' => 'POST',
            'path'       => '/x',
            'hasBody'    => true,
        ]), $this->makeContext([]));

        $this->assertSame('', (string) $this->recorder[0]->getBody());
    }

    public function testHandlerSetsAcceptAndXApiAppAndRequestId(): void
    {
        $this->mock->append(new Response(200, [], '{}'));

        $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertSame('application/json', $this->recorder[0]->getHeaderLine('Accept'));
        $this->assertSame('1', $this->recorder[0]->getHeaderLine('X-API-APP'));
        $this->assertMatchesRegularExpression('/^mcp-[0-9a-f]{8}-\d{4}$/', $this->recorder[0]->getHeaderLine('X-Request-Id'));
    }

    // ---------------------------------------------------------------------
    // Handler closure — auth header forwarding
    // ---------------------------------------------------------------------

    public function testHandlerForwardsBearerTokenFromContext(): void
    {
        $this->mock->append(new Response(200, [], '{}'));

        $this->invokeHandler($this->basicToolDef(), $this->makeContext([], [
            'Authorization' => 'Bearer xyz',
        ]));

        $this->assertSame('Bearer xyz', $this->recorder[0]->getHeaderLine('Authorization'));
    }

    public function testHandlerIgnoresNonBearerAuthorizationHeader(): void
    {
        $this->mock->append(new Response(200, [], '{}'));

        $this->invokeHandler($this->basicToolDef(), $this->makeContext([], [
            'Authorization' => 'Basic dXNlcjpwYXNz',
        ]));

        $this->assertSame('', $this->recorder[0]->getHeaderLine('Authorization'));
    }

    public function testHandlerForwardsXApiKeyFromContext(): void
    {
        $this->mock->append(new Response(200, [], '{}'));

        $this->invokeHandler($this->basicToolDef(), $this->makeContext([], [
            'X-API-KEY' => 'k123',
        ]));

        $this->assertSame('k123', $this->recorder[0]->getHeaderLine('X-API-KEY'));
    }

    public function testHandlerForwardsSessionidFromContext(): void
    {
        $this->mock->append(new Response(200, [], '{}'));

        $this->invokeHandler($this->basicToolDef(), $this->makeContext([], [
            'sessionid' => 'sess-abc',
        ]));

        $this->assertSame('sess-abc', $this->recorder[0]->getHeaderLine('sessionid'));
    }

    public function testHandlerFallsBackToStdioBearer(): void
    {
        $this->mock->append(new Response(200, [], '{}'));
        $this->factory->setStdioAuth(['bearer_token' => 'stdio-token']);

        $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertSame('Bearer stdio-token', $this->recorder[0]->getHeaderLine('Authorization'));
    }

    public function testHandlerFallsBackToStdioApiKey(): void
    {
        $this->mock->append(new Response(200, [], '{}'));
        $this->factory->setStdioAuth(['api_key' => 'stdio-key']);

        $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertSame('stdio-key', $this->recorder[0]->getHeaderLine('X-API-KEY'));
    }

    public function testHandlerFallsBackToStdioSessionId(): void
    {
        $this->mock->append(new Response(200, [], '{}'));
        $this->factory->setStdioAuth(['session_id' => 'stdio-sess']);

        $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertSame('stdio-sess', $this->recorder[0]->getHeaderLine('sessionid'));
    }

    public function testHandlerSkipsStdioFallbackWhenContextProvidesAuth(): void
    {
        $this->mock->append(new Response(200, [], '{}'));
        $this->factory->setStdioAuth(['bearer_token' => 'fallback']);

        $this->invokeHandler($this->basicToolDef(), $this->makeContext([], [
            'X-API-KEY' => 'real-key',
        ]));

        $this->assertSame('real-key', $this->recorder[0]->getHeaderLine('X-API-KEY'));
        $this->assertSame('', $this->recorder[0]->getHeaderLine('Authorization'));
    }

    // ---------------------------------------------------------------------
    // Handler closure — response handling
    // ---------------------------------------------------------------------

    public function testHandlerReturnsDecodedJsonObject(): void
    {
        $this->mock->append(new Response(200, [], '{"id":1,"name":"x"}'));

        $result = $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertSame(['id' => 1, 'name' => 'x'], $result);
    }

    public function testHandlerWrapsListResponseAsItems(): void
    {
        $this->mock->append(new Response(200, [], '[{"id":1},{"id":2}]'));

        $result = $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertSame(['items' => [['id' => 1], ['id' => 2]]], $result);
    }

    public function testHandlerReturnsRawWhenResponseIsNotJson(): void
    {
        $this->mock->append(new Response(200, [], 'plain text body'));

        $result = $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertSame(['raw' => 'plain text body'], $result);
    }

    public function testHandlerReturnsErrorOn4xxWithErrorField(): void
    {
        $this->mock->append(new Response(404, [], '{"error":"not found"}'));

        $result = $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertSame(['error' => 'API returned HTTP 404: not found', 'status' => 404], $result);
    }

    public function testHandlerReturnsErrorOn5xxWithMessageField(): void
    {
        $this->mock->append(new Response(503, [], '{"message":"service unavailable"}'));

        $result = $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertSame(['error' => 'API returned HTTP 503: service unavailable', 'status' => 503], $result);
    }

    public function testHandlerReturnsErrorOn4xxWithoutErrorOrMessageField(): void
    {
        $this->mock->append(new Response(400, [], '{"foo":"bar"}'));

        $result = $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertSame(['error' => 'API returned HTTP 400', 'status' => 400], $result);
    }

    public function testHandlerCatchesGuzzleException(): void
    {
        $this->mock->append(new \GuzzleHttp\Exception\ConnectException(
            'boom',
            new Psr7Request('GET', 'http://x')
        ));

        $result = $this->invokeHandler($this->basicToolDef(), $this->makeContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('API request failed', $result['error']);
    }
}
