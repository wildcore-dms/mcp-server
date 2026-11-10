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

## Permissions and components

Tools run with the permissions of the WildCore user owning `WILDCORE_TOKEN`; roles and device groups are
enforced by WildCore itself. On top of that, at startup (for HTTP - every `CAPABILITIES_TTL` seconds) the
server reads `GET /system/settings` and **hides tools** the user has no permission for or whose component
is not enabled - the same way the web panel builds its menu (`need_permissions`, `need_components`).

| Group | Required in WildCore |
|---|---|
| `devices`, `interfaces`, `onts` | `device_show` permission (`search` - `global_search`, `device_groups_list` - `system_info`, `ont_signal_reset` - `device_iface_management`) |
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
cp .env.example .env   # set WILDCORE_URL and WILDCORE_TOKEN
docker pull ghcr.io/wildcore-dms/mcp-server:latest
```

Images are built by GitHub Actions (`.github/workflows/docker.yml`) for `linux/amd64` and `linux/arm64`:
`latest` from `main`, `X.Y.Z` / `X.Y` from `vX.Y.Z` tags, `sha-<commit>` for every build. Each build runs the
unit tests, a smoke test of the image and a Trivy scan (fixed CRITICAL/HIGH findings fail the build; accepted
exceptions with a reason and an expiry date live in `.trivyignore`).

### stdio

```bash
docker run -i --rm --env-file .env ghcr.io/wildcore-dms/mcp-server:latest php bin/wildcore-mcp
```

Without Docker: PHP 8.5.12+ or 8.4.27+ with `ext-sockets`, then `composer install --no-dev && bin/wildcore-mcp`
(configuration from `.env` in the project root or from environment variables).

### HTTP (Streamable HTTP)

```bash
docker compose -f docker-compose.example.yml up -d   # reads .env from the same directory
curl http://127.0.0.1:8000/health                    # ok
```

- `/mcp` - MCP endpoint, `/health` - liveness probe (does not call WildCore);
- the container serves plain HTTP on port 8000; terminate TLS at a reverse proxy in front of it;
- sessions are stored as files in `/tmp`, shared by all workers;
- behind a reverse proxy, put the public host name into `MCP_HTTP_ALLOWED_HOSTS`.

All clients of the HTTP server share the permissions of a single `WILDCORE_TOKEN`; set
`MCP_HTTP_BEARER_TOKEN` and do not expose the port without it.

## Connecting to Claude

1. Create a dedicated WildCore user for Claude with only the role and device groups it should have.
2. Create an API key for it: Management -> Users -> edit the user -> **Create API key** (needs the
   `user_auth_key_generate` permission and your current password). Pick the expiry date consciously - the tools
   stop working when the key expires.
3. `docker pull ghcr.io/wildcore-dms/mcp-server:latest` - all examples below use this image.

`WILDCORE_URL` is resolved inside the container, so `localhost` points to the container itself. Use an address
of WildCore reachable from Docker. For a WildCore on the same machine either use the host's LAN address or add
`--network host` to the docker arguments (not possible with the plugin, which has fixed docker arguments).

### Claude Code - plugin (recommended)

The repository is a Claude Code plugin marketplace. The `wildcore` plugin bundles the MCP server (stdio via
Docker) and two skills: `wildcore` (how to use the tools, safety rules for device-changing actions, signal
level guidance) and `/wildcore:onu-check <serial|MAC|agreement>`.

```
/plugin marketplace add wildcore-dms/mcp-server
/plugin install wildcore@wildcore
```

On recent Claude Code versions one command does both: `/plugin install wildcore --marketplace wildcore-dms/mcp-server`.

On install Claude Code asks for the WildCore URL, the API key (stored as a secret, not in plain config files),
read-only mode (on by default) and the Docker image (default `ghcr.io/wildcore-dms/mcp-server:latest`). The
values can be changed later in the plugin settings in `/plugin`. Restart Claude Code and check the connection
with `/mcp`: the `wildcore` server should be connected and list its tools.

### Claude Code - without the plugin

stdio (local Docker). The API key ends up in plain text in the Claude Code configuration (`~/.claude.json`);
prefer the plugin when that matters.

```bash
claude mcp add wildcore \
  -e WILDCORE_URL=https://wildcore.example.com -e WILDCORE_TOKEN=<api key> -e READ_ONLY_MODE=true \
  -- docker run -i --rm -e WILDCORE_URL -e WILDCORE_TOKEN -e READ_ONLY_MODE ghcr.io/wildcore-dms/mcp-server:latest php bin/wildcore-mcp
```

HTTP (shared server started with `docker-compose.example.yml`):

```bash
claude mcp add --transport http wildcore https://mcp.example.com/mcp --header "Authorization: Bearer <MCP_HTTP_BEARER_TOKEN>"
```

Add `--scope project` to store the server in `.mcp.json` of the current project (shared with the team via git;
keep secrets in environment variables: `"headers": {"Authorization": "Bearer ${WILDCORE_MCP_TOKEN}"}`).

### Claude Desktop

Settings -> Developer -> Edit Config, add to `claude_desktop_config.json` and restart Claude Desktop.
On macOS Claude Desktop may not see Docker in `PATH` - then use the full path as `command`
(`/usr/local/bin/docker`, or `/opt/homebrew/bin/docker`; check with `which docker`).

```json
{
  "mcpServers": {
    "wildcore": {
      "command": "docker",
      "args": ["run", "-i", "--rm", "-e", "WILDCORE_URL", "-e", "WILDCORE_TOKEN", "-e", "READ_ONLY_MODE",
               "ghcr.io/wildcore-dms/mcp-server:latest", "php", "bin/wildcore-mcp"],
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
# dev dependencies (PHPUnit) into ./vendor, then tests inside the image
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2 \
  composer install --ignore-platform-req=ext-sockets
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app wildcore-mcp:dev php vendor/bin/phpunit
```

A new tool is a public method with `#[McpTool]` in a class from `src/Tools/`: the description comes from
PHPDoc, the parameter schema from types and `#[Schema]`. The kind is derived from annotations:
`readOnlyHint: true` - read-only, `openWorldHint: true` without readOnlyHint - device-control, otherwise
config. Permission and component requirements are declared with `#[Requires]`.

## License

MIT, see [LICENSE](LICENSE).
