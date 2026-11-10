# WildCore MCP Server

[Model Context Protocol](https://modelcontextprotocol.io) server for WildCore DMS. It gives LLM clients
(Claude Code, Claude Desktop, Cursor, etc.) tools to work with WildCore: devices, interfaces, ONUs and
optical signal levels, switcher-core modules, macros and ONU registration.

It is a standalone service calling the WildCore REST API (`/api/v1`) with a user API key, modelled after
[librenms-mcp](https://github.com/mhajder/librenms-mcp). Nothing is embedded into WildCore itself.

```
MCP client ──MCP (stdio | Streamable HTTP)──▶ wildcore-mcp ──HTTP + X-Auth-Key──▶ WildCore /api/v1
```

Stack: PHP 8.5, the official [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk) (`mcp/sdk`),
Guzzle, [RoadRunner](https://roadrunner.dev) 3 for HTTP.

## PHP security

PHP **8.5.12+** or **8.4.27+** (8.3.36+) is required: earlier versions are affected by CVE-2026-109618 /
CVE-2026-109619 (state corruption after OOM in multipart parsing, CVSS 9.2). `composer.json` refuses to
install on an unpatched version, and the server logs a warning at startup. In addition:

- HTTP is accepted by RoadRunner (Go): the PHP worker runs as CLI and gets an already parsed PSR-7 request, so PHP's
  rfc1867 multipart parser never runs; each worker is a separate process restarted after 1000 requests or 128 MB;
- RoadRunner limits the body to 1 MB and rate-limits clients by IP (`RATE_LIMIT_PER_MINUTE`); `/mcp` accepts only
  `application/json`;
- `php.ini` additionally disables `enable_post_data_reading`, `file_uploads` and `session.upload_progress`;
- the container runs as an unprivileged user with a read-only root filesystem, no capabilities and `no-new-privileges`.

## Permissions and components

Tools run with the permissions of the WildCore user owning `WILDCORE_TOKEN`; roles and device groups are
enforced by WildCore itself. On top of that, at startup (for HTTP - every `CAPABILITIES_TTL` seconds) the
server reads `GET /system/settings` and **hides tools** the user has no permission for or whose component
is not enabled - the same way the web panel builds its menu (`need_permissions`, `need_components`).

| Group | Required in WildCore |
|---|---|
| `devices`, `interfaces`, `onts` | `device_show` permission (`search` - `global_search`, `ont_signal_reset` - `device_iface_management`) |
| `olt_onts`, `ont_info`, `olt_pon_ports` | `olts` component, `olts_info` permission |
| `switcher-core` | root user only (system/owner roles): WildCore has permission rules only for `system`, `sys_resources`, `parse_interface` |
| `macros` | `macros` component, `macros_execute` permission; management - `macros_edit` |
| `ont-registration` | `onts_registration` component, `unregistered_onts` permission; templates - `unregistered_onts_config` |

If WildCore is unreachable at startup, tools are not filtered by permissions (a warning is logged) -
WildCore rejects calls the user is not allowed to make anyway.

## Tools

Every tool has two tags (in `_meta.tags`): its group and its kind.

- `read-only` - only reads data;
- `device-control` - changes equipment state (`macros_execute`, `ont_register`, `swc_call`);
- `config` - changes WildCore data (macros, registration templates, min/max signal reset).

`READ_ONLY_MODE=true` (the default) keeps only `read-only` tools. `DISABLED_TAGS` turns off groups or kinds.

| Group | Tools |
|---|---|
| devices | `devices_list`, `device_get`, `device_find_by_ip`, `search`, `device_groups_list` |
| interfaces | `interfaces_list`, `interfaces_search`, `device_interfaces`, `interface_get`, `interface_history`, `interface_types` |
| onts | `onts_list` (with a filter such as `optical.rx < -27`), `ont_signals`, `olt_onts`, `ont_info`, `olt_pon_ports`, `ont_signal_reset` |
| switcher-core | `swc_modules`, `swc_read` (read modules only), `swc_call` (any module) |
| macros | `macros_list`, `macros_get`, `macros_preview`, `macros_variables`, `macros_execute`, `macros_manage_list`, `macros_manage_get`, `macros_create`, `macros_update`, `macros_clone`, `macros_delete` |
| ont-registration | `onts_unregistered`, `ont_find_unregistered`, `ont_registration_macros`, `ont_registration_preview`, `ont_registration_variables`, `ont_register`, `ont_registration_templates`, `ont_registration_template_get/create/update/clone/delete` |
| api | `api_get` - GET on any `/api/v1` path when no dedicated tool fits |

`swc_read` accepts only modules from the explicit `SwitcherCore::READ_MODULES` list. The `ctrl_` prefix is not
enough: `reboot`, `save_config`, `clear_counters`, `console_command`, `cable_diag`, `arp_ping` and `*_ctrl` also
change device state. New switcher-core modules are treated as writing until they are added to the list.

Large lists are sliced with `limit`/`offset`; a result larger than `MAX_RESULT_BYTES` is refused with a
request to narrow the query.

## Running

```bash
cp .env.example .env   # WILDCORE_URL, WILDCORE_TOKEN
docker build -t wildcore-mcp .
```

### stdio

```bash
docker run -i --rm --env-file /path/to/.env wildcore-mcp php bin/wildcore-mcp
```

Without Docker (PHP 8.5.12+ or 8.4.27+): `composer install && bin/wildcore-mcp`.

### HTTP (Streamable HTTP)

```bash
docker compose -f docker-compose.example.yml up -d
```

- `/mcp` - MCP endpoint, `/health` - liveness probe (does not call WildCore);
- the container serves plain HTTP on port 8000; terminate TLS at a reverse proxy in front of it;
- sessions are stored as files in `/tmp`, shared by all workers;
- behind a reverse proxy, put the public host name into `MCP_HTTP_ALLOWED_HOSTS`.

All clients of the HTTP server share the permissions of a single `WILDCORE_TOKEN`; set
`MCP_HTTP_BEARER_TOKEN` and do not expose the port without it.

## Connecting to Claude

Create a dedicated WildCore user for Claude with only the permissions and device groups it should have, and
generate an API key for it (Users -> user -> generate auth key). Build the image once:
`docker build -t wildcore-mcp:latest https://github.com/wildcore-dms/mcp-server.git` (or from a local clone).

`WILDCORE_URL` is resolved inside the container: use an address reachable from Docker, not `localhost`
(add `--network host` to the docker arguments to reach a WildCore on the same machine).

### Claude Code - plugin (recommended)

The repository is a Claude Code plugin marketplace. The `wildcore` plugin bundles the MCP server (stdio via
Docker) and two skills: `wildcore` (how to use the tools, safety rules for device-changing actions, signal
level guidance) and `/wildcore:onu-check <serial|MAC|agreement>`.

```
/plugin marketplace add wildcore-dms/mcp-server     # or a local clone: /path/to/mcp-server
/plugin install wildcore@wildcore
```

On install Claude Code asks for the WildCore URL, the API key (stored as a secret), read-only mode (on by
default) and the Docker image name. Change them later in `/plugin` -> wildcore -> configure. Check the
connection with `/mcp`.

### Claude Code - without the plugin

stdio (local Docker):

```bash
claude mcp add wildcore \
  -e WILDCORE_URL=https://wildcore.example.com -e WILDCORE_TOKEN=<api key> -e READ_ONLY_MODE=true \
  -- docker run -i --rm -e WILDCORE_URL -e WILDCORE_TOKEN -e READ_ONLY_MODE wildcore-mcp:latest php bin/wildcore-mcp
```

HTTP (shared server started with `docker-compose.example.yml`):

```bash
claude mcp add --transport http wildcore https://mcp.example.com/mcp --header "Authorization: Bearer <MCP_HTTP_BEARER_TOKEN>"
```

Add `--scope project` to store the server in `.mcp.json` of the current project (shared with the team via git;
keep secrets in environment variables: `"headers": {"Authorization": "Bearer ${WILDCORE_MCP_TOKEN}"}`).

### Claude Desktop

Settings -> Developer -> Edit Config, add to `claude_desktop_config.json` and restart Claude Desktop:

```json
{
  "mcpServers": {
    "wildcore": {
      "command": "docker",
      "args": ["run", "-i", "--rm", "-e", "WILDCORE_URL", "-e", "WILDCORE_TOKEN", "-e", "READ_ONLY_MODE",
               "wildcore-mcp:latest", "php", "bin/wildcore-mcp"],
      "env": {
        "WILDCORE_URL": "https://wildcore.example.com",
        "WILDCORE_TOKEN": "<api key>",
        "READ_ONLY_MODE": "true"
      }
    }
  }
}
```

### claude.ai and remote connectors

Custom connectors in claude.ai / Claude Desktop (Settings -> Connectors) support only OAuth or no
authentication - a static bearer token (`MCP_HTTP_BEARER_TOKEN`) is not accepted there, and the server has to
be reachable from Anthropic over public HTTPS. This server does not implement OAuth, so use Claude Code or the
local Claude Desktop configuration above. Do not expose an unauthenticated instance to the internet.

## Configuration

See `.env.example`. Main settings: `WILDCORE_URL`, `WILDCORE_TOKEN`, `READ_ONLY_MODE`, `DISABLED_TAGS`,
`MCP_HTTP_BEARER_TOKEN`, `MCP_HTTP_ALLOWED_HOSTS`.

## Development

```bash
docker build -t wildcore-mcp:dev .
docker run --rm -v "$PWD":/app -w /app wildcore-mcp:dev php vendor/bin/phpunit
```

A new tool is a public method with `#[McpTool]` in a class from `src/Tools/`: the description comes from
PHPDoc, the parameter schema from types and `#[Schema]`. The kind is derived from annotations:
`readOnlyHint: true` - read-only, `openWorldHint: true` without readOnlyHint - device-control, otherwise
config. Permission and component requirements are declared with `#[Requires]`.

## License

MIT, see [LICENSE](LICENSE).
