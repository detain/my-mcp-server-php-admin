# Admin MCP Proxy Server

A standalone MCP (Model Context Protocol) proxy server for the MyAdmin admin API. This server acts as an MCP intermediary that:

- Fetches the OpenAPI spec from a remote URL
- Exposes MCP tools generated from the spec
- Proxies tool calls to the actual admin API
- Supports OAuth 2.1 protected resource metadata

## Requirements

- PHP 8.2+
- Composer

## Installation

1. Clone the repository and install dependencies:

```bash
cd admin-mcp-proxy
composer install
```

2. Copy the environment template and configure:

```bash
cp .env.example .env
# Edit .env with your settings
```

3. Configure the environment variables:

| Variable | Description | Default |
|----------|-------------|---------|
| `OPENAPI_SPEC_URL` | URL to fetch the OpenAPI admin spec from | `https://my.interserver.net/admin/spec/openapi-admin.yaml` |
| `API_BASE_URL` | Base URL of the admin API to proxy to | `https://my.interserver.net/apiv2/admin` |
| `SESSION_DIR` | Directory for session storage | `/tmp/mcp_admin_sessions` |
| `CACHE_DIR` | Directory for cached tool definitions | `/tmp/mcp_admin_cache` |
| `SERVER_NAME` | MCP server name | `myadmin-admin-mcp` |
| `SERVER_VERSION` | MCP server version | `1.0.0` |

## Web Server Configuration

### Apache (`.htaccess`)

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ public/index.php [QSA,L]
```

### Nginx

```nginx
location / {
    try_files $uri $uri/ /public/index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

### LiteSpeed

The server is designed to work with LiteSpeed Web Server. Point the document root to the `public/` directory.

## Endpoints

| Path | Method | Description |
|------|--------|-------------|
| `/mcp` | POST | MCP JSON-RPC endpoint |
| `/mcp` | GET | SSE streaming endpoint (for server-initiated responses) |
| `/mcp` | DELETE | Session termination |
| `/.well-known/oauth-protected-resource` | GET | OAuth 2.1 protected resource metadata |
| `/.well-known/oauth-authorization-server` | GET | OAuth authorization server metadata |

## Authentication

The proxy supports multiple authentication methods:

1. **Bearer Token** - `Authorization: Bearer <token>`
2. **API Key** - `X-API-KEY: <key>`
3. **Session ID** - `sessionid: <session_id>`

The proxy forwards the first valid auth credential it finds to the upstream API.

## STDIO Transport (Claude Desktop / Cursor)

The proxy supports stdio transport for local AI tool integration, suitable for use with Claude Desktop, Cursor, and other MCP clients that communicate over stdio.

### Usage

```bash
# Install dependencies
composer install

# Make the CLI executable
chmod +x bin/mcp

# Run with environment variables
OPENAPI_SPEC_URL=https://my.interserver.net/admin/spec/openapi-admin.yaml \
API_BASE_URL=https://my.interserver.net/apiv2/admin \
BEARER_TOKEN=your_bearer_token \
bin/mcp
```

### Environment Variables for STDIO Mode

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `OPENAPI_SPEC_URL` | No | `https://my.interserver.net/admin/spec/openapi-admin.yaml` | URL to fetch OpenAPI spec from |
| `API_BASE_URL` | No | `https://my.interserver.net/apiv2/admin` | Base URL of the admin API |
| `API_KEY` | No | - | API key for authentication |
| `SESSION_ID` | No | - | Session ID for authentication |
| `BEARER_TOKEN` | No | - | Bearer token for authentication |
| `SESSION_DIR` | No | `/tmp/mcp_admin_sessions` | Directory for session storage |
| `CACHE_DIR` | No | `/tmp/mcp_admin_cache` | Directory for cached tool definitions |
| `SERVER_NAME` | No | `myadmin-admin-mcp` | MCP server name |
| `SERVER_VERSION` | No | `1.0.0` | MCP server version |

### Claude Desktop Configuration

Add to your Claude Desktop configuration file:

**macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`
**Windows:** `%APPDATA%\Claude\claude_desktop_config.json`
**Linux:** `~/.config/Claude/claude_desktop_config.json`

```json
{
  "mcpServers": {
    "myadmin-admin": {
      "command": "php",
      "args": ["/path/to/admin-mcp-proxy/bin/mcp"],
      "env": {
        "OPENAPI_SPEC_URL": "https://my.interserver.net/admin/spec/openapi-admin.yaml",
        "API_BASE_URL": "https://my.interserver.net/apiv2/admin",
        "BEARER_TOKEN": "your_bearer_token"
      }
    }
  }
}
```

### Cursor Configuration

Add to Cursor settings (Settings → MCP Servers):

```json
{
  "mcpServers": {
    "myadmin-admin": {
      "command": "php",
      "args": ["/path/to/admin-mcp-proxy/bin/mcp"],
      "env": {
        "OPENAPI_SPEC_URL": "https://my.interserver.net/admin/spec/openapi-admin.yaml",
        "API_BASE_URL": "https://my.interserver.net/apiv2/admin",
        "BEARER_TOKEN": "your_bearer_token"
      }
    }
  }
}
```

### Notes

- Errors are logged to STDERR (per MCP stdio specification)
- The server exits cleanly on EOF (end-of-file) from STDIN
- Sessions are stored in `SESSION_DIR` for state management

## Session Persistence

Sessions are stored as JSON files in the configured `SESSION_DIR`. To clean up stale sessions:

```php
use AdminMcp\FileSessionStore;

$store = new FileSessionStore('/tmp/mcp_admin_sessions');
$cleaned = $store->cleanupStaleSessions(86400); // Clean sessions older than 24 hours
```

## Caching

Tool definitions are cached as PHP files in `CACHE_DIR` for 1 hour. To force a fresh fetch:

```php
use AdminMcp\OpenApiParser;

$parser = new OpenApiParser('/tmp/mcp_admin_cache');
$parser->clearCache('https://my.interserver.net/admin/spec/openapi-admin.yaml');
```

## Running

### Development

```bash
php -S localhost:8080 -t public
```

### Production

Configure your web server to serve the `public/` directory and point to `public/index.php` as the entry point.

## OAuth 2.1 Compliance

This server implements the OAuth 2.1 protected resource specification:

- Exposes `/.well-known/oauth-protected-resource` metadata endpoint
- Supports Bearer token authentication
- Includes resource indicator in token validation

## MCP Protocol

This server implements the Streamable HTTP transport for MCP:

- POST requests for JSON-RPC messages
- GET requests for SSE streaming (server-initiated responses)
- DELETE for session termination
- Session IDs via `Mcp-Session-Id` header

## License

Proprietary - InterServer
