<?php

declare(strict_types=1);

namespace AdminMcp;

use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * File-based session store for MCP server sessions.
 */
class FileSessionStore implements SessionStoreInterface
{
    private string $sessionDir;
    private LoggerInterface $logger;

    public function __construct(string $sessionDir, ?LoggerInterface $logger = null)
    {
        $this->sessionDir = $sessionDir;
        $this->logger = $logger ?? new NullLogger();

        if (!is_dir($this->sessionDir)) {
            mkdir($this->sessionDir, 0755, true);
        }
    }

    public function create(string $sessionId): void
    {
        $this->logger->debug('Creating session', ['sessionId' => $sessionId]);
        $sessionFile = $this->getSessionFile($sessionId);
        $data = [
            'sessionId' => $sessionId,
            'createdAt' => time(),
            'data' => [],
        ];
        file_put_contents($sessionFile, json_encode($data), LOCK_EX);
    }

    public function exists(string $sessionId): bool
    {
        return file_exists($this->getSessionFile($sessionId));
    }

    public function get(string $sessionId): array
    {
        $sessionFile = $this->getSessionFile($sessionId);
        if (!file_exists($sessionFile)) {
            return [];
        }

        $content = file_get_contents($sessionFile);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? ($data['data'] ?? []) : [];
    }

    public function set(string $sessionId, array $data): void
    {
        $sessionFile = $this->getSessionFile($sessionId);
        $existing = [];

        if (file_exists($sessionFile)) {
            $content = file_get_contents($sessionFile);
            if ($content !== false) {
                $existing = json_decode($content, true) ?? [];
            }
        }

        $existing['data'] = $data;
        $existing['updatedAt'] = time();

        file_put_contents($sessionFile, json_encode($existing), LOCK_EX);
    }

    public function delete(string $sessionId): void
    {
        $sessionFile = $this->getSessionFile($sessionId);
        if (file_exists($sessionFile)) {
            unlink($sessionFile);
        }
    }

    public function clear(): void
    {
        $files = glob($this->sessionDir . '/session_*.json');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }

    private function getSessionFile(string $sessionId): string
    {
        return $this->sessionDir . '/session_' . $sessionId . '.json';
    }

    /**
     * Clean up stale sessions (older than 24 hours).
     */
    public function cleanupStaleSessions(int $maxAgeSeconds = 86400): int
    {
        $count = 0;
        $files = glob($this->sessionDir . '/session_*.json');
        if ($files === false) {
            return 0;
        }

        $now = time();
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $data = json_decode($content, true);
            if (!is_array($data)) {
                continue;
            }

            $updatedAt = $data['updatedAt'] ?? $data['createdAt'] ?? 0;
            if (($now - $updatedAt) > $maxAgeSeconds) {
                unlink($file);
                $count++;
            }
        }

        return $count;
    }
}
