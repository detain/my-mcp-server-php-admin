<?php

declare(strict_types=1);

namespace AdminMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Process-based smoke tests for the bin/mcp launcher.
 *
 * These tests don't load the script via include; they spawn `php bin/mcp`
 * and assert on stdout / stderr / exit code. They cover branches that are
 * impractical to unit-test (CLI parsing, --help, --http spawn).
 */
final class BinMcpCliTest extends TestCase
{
    private const BIN = __DIR__ . '/../../bin/mcp';

    /** @return array{stdout: string, stderr: string, exit: int} */
    private function exec(array $args, int $timeoutMs = 5000): array
    {
        $cmd = array_merge([PHP_BINARY, self::BIN], $args);
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            $this->fail('Failed to spawn bin/mcp');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exit = -1;
        $deadline = microtime(true) + ($timeoutMs / 1000);
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            if (!$status['running']) {
                // Capture exit code now — proc_close after proc_get_status has
                // reaped the child returns -1 on some Linux/PHP combinations.
                $exit = $status['exitcode'];
                break;
            }
            usleep(20_000);
        }
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        proc_close($proc);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }

    public function testHelpFlagPrintsUsageAndExitsZero(): void
    {
        $r = $this->exec(['--help']);
        $this->assertSame(0, $r['exit']);
        $this->assertStringContainsString('Usage:', $r['stdout']);
        $this->assertStringContainsString('--stdio', $r['stdout']);
        $this->assertStringContainsString('--http', $r['stdout']);
        $this->assertStringContainsString('--host=HOST', $r['stdout']);
        $this->assertStringContainsString('--port=PORT', $r['stdout']);
        $this->assertStringContainsString('--openapi-spec=URL', $r['stdout']);
        $this->assertStringContainsString('--base-url=URL', $r['stdout']);
        $this->assertStringContainsString('--api-key=KEY', $r['stdout']);
        $this->assertStringContainsString('--verify=true|false', $r['stdout']);
        $this->assertStringContainsString('--ca-cert=PATH', $r['stdout']);
    }

    public function testShortHelpFlagAlsoWorks(): void
    {
        $r = $this->exec(['-h']);
        $this->assertSame(0, $r['exit']);
        $this->assertStringContainsString('Usage:', $r['stdout']);
    }

    public function testUnknownOptionExitsTwoAndWritesToStderr(): void
    {
        $r = $this->exec(['--bogus']);
        $this->assertSame(2, $r['exit']);
        $this->assertStringContainsString('Unknown option', $r['stderr']);
    }

    public function testStdioAndHttpTogetherExitsTwo(): void
    {
        $r = $this->exec(['--stdio', '--http']);
        $this->assertSame(2, $r['exit']);
        $this->assertStringContainsString('cannot combine', $r['stderr']);
    }

    public function testHttpModeSpawnsServerAndExitsZero(): void
    {
        // Find a free port by binding a socket
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($sock, "Failed to find free port: $errstr");
        $name = stream_socket_get_name($sock, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($sock);

        $r = $this->exec(['--http', '--host=127.0.0.1', "--port={$port}"], 8000);

        try {
            $this->assertSame(0, $r['exit'], "stderr: {$r['stderr']}");
            $this->assertMatchesRegularExpression(
                '/Server started at http:\/\/127\.0\.0\.1:' . $port . ' \(pid \d+\)/',
                $r['stdout']
            );
        } finally {
            // Best-effort cleanup of the spawned server
            @shell_exec('pkill -f "php -S 127.0.0.1:' . $port . '" 2>/dev/null');
            // Clean up the log files the launcher wrote to project root
            @unlink(__DIR__ . '/../../mcp-server-out.log');
            @unlink(__DIR__ . '/../../mcp-server-err.log');
        }
    }
}
