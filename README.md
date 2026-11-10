# WildCore MCP Server

**English** | [Українська](README_UK.md)

[Model Context Protocol](https://modelcontextprotocol.io) server for WildCore DMS. It gives LLM clients
(Claude Code, Claude Desktop, Cursor, etc.) the WildCore API as tools: devices, interfaces, ONUs and optical
signals, logs and calling errors, events, switcher-core modules, macros, ONU registration and the components
(switches, routers, OLT control, pinger, traps, topology, analytics ...), plus generic access to every API
operation through the OpenAPI spec of the WildCore instance.

It is a standalone service calling the WildCore REST API (`/api/v1`) with a user API key, modelled after
[librenms-mcp](https://github.com/mhajder/librenms-mcp). Nothing is embedded into WildCore itself.

```
MCP client ──MCP (stdio | Streamable HTTP)──▶ wildcore-mcp ──HTTP + X-Auth-Key──▶ WildCore /api/v1
```

Stack: PHP 8.5, the official [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk) (`mcp/sdk`),
Guzzle, [RoadRunner](https://roadrunner.dev) 3 for HTTP.

## Tool groups

Tools are grouped the same way as WildCore itself:

- **core** groups - areas of the WildCore core: `devices`, `interfaces`, `onts`, `logs`, `poller`,
  `switcher-core`, `api`;
- **component** groups - named by the WildCore component key (`olts`, `macros`, `events` ...); they are listed
  only when that component is enabled in WildCore.

Every tool has tags (in `_meta.tags`): its group, its kind and, for credentials, `secrets`.

- `read-only` - only reads data;
- `device-control` - changes equipment state (macros execution, ONU registration and control, port changes, reboot);
- `config` - changes WildCore data (macros, registration templates, resolving events, min/max signal reset);
- `secrets` - returns credentials (device configs from Oxidized, console session logs, access profiles through
  the API tools); listed only with `ENABLE_SECRETS=true`.

`READ_ONLY_MODE=true` (the default) keeps only `read-only` tools. `DISABLED_TAGS` turns off groups or kinds,
e.g. `DISABLED_TAGS=switches_control,olts_control,api`.

### Core

| Group | Tools | Needs in WildCore |
|---|---|---|
| devices | `devices_list`, `device_get`, `device_find_by_ip`, `search`, `device_groups_list` | `device_show`; `search` - `global_search`; groups - `system_info` |
| interfaces | `interfaces_list`, `interfaces_search`, `device_interfaces`, `interface_get`, `interface_history`, `interface_types` | `device_show` |
| onts | `onts_list` (filter such as `optical.rx < -27`), `ont_signals`, `ont_signal_reset` | `device_show`; reset - `device_iface_management` |
| logs | `logs_actions`, `logs_action_names`, `logs_switcher_core`, `logs_switcher_core_modules`, `calling_errors`, `logs_pollers`, `logs_poller_names`, `logs_schedule`, `logs_schedule_keys`, `latest_system_actions` | `system_logs_actions`, `log_switcher_core_actions`, `log_poller`, `schedule_reports` |
| poller | `device_pollers`, `device_poll` | `poller_info_by_device`; poll - `device_management` |
| switcher-core | `swc_modules`, `swc_read` (read modules only), `swc_call` (any module) | root user only |
| api | `api_search`, `api_describe`, `api_read`, `api_call` | the permission of each operation |

### Components

| Group (component) | Tools | Needs in WildCore |
|---|---|---|
| olts | `olt_onts`, `ont_info`, `olt_pon_ports`, `olt_physical_ports`, `olt_snooping`, `olt_cards`, `olt_resources`, `olt_onts_blacklist` | `olts_info` |
| olts_control | `ont_reboot`, `ont_set_state`, `ont_set_description`, `ont_uni_port_set`, `ont_reset`, `ont_deregister`, `olt_port_reset`, `olt_port_set_description` | `olts_ctrl_*` of each action |
| onts_registration | `onts_unregistered`, `ont_find_unregistered`, `ont_registration_macros`, `ont_registration_preview`, `ont_registration_variables`, `ont_register`, `ont_registration_templates`, `ont_registration_template_get/create/update/clone/delete` | `unregistered_onts`; templates - `unregistered_onts_config` |
| macros | `macros_list`, `macros_get`, `macros_preview`, `macros_variables`, `macros_execute`, `macros_manage_list`, `macros_manage_get`, `macros_create`, `macros_update`, `macros_clone`, `macros_delete` | `macros_execute`; management - `macros_edit` |
| switches | `switch_interfaces`, `switch_vlans`, `switch_snooping`, `switch_resources`, `switch_sfp_diag`, `switch_cable_diag` | `switches_info` |
| switches_control | `switch_port_set`, `switch_clear_counters`, `switch_save_config`, `switch_reboot` | `switches_*` of each action |
| routers | `router_table` (interfaces, ARP, FDB, VLANs, BGP peers, direct routes, resources) | `router_info` |
| events | `events_list`, `events_stat`, `event_names`, `event_rules`, `event_resolve` | `events_show`; rules - `events_configuration`; resolve - `events_resolve` |
| pinger | `pinger_status`, `pinger_logs`, `pinger_statuses` | `device_show` |
| trapservice | `traps_history`, `trap_names`, `traps_stat` | `diag_interface` |
| console | `console_logs`, `console_log_get` (secrets) | `external_apps_console_view_logs` |
| fdb_history | `fdb_history` | `fdb_history_by_interface` |
| search_device | `find_mac`, `find_ip` | `sd_search_mac`, `sd_search_ip*` |
| diagnostic | `interface_diagnostic`, `arp_ping` | `diag_interface`, `diag_arp_ping` |
| live_traffic | `interface_live_traffic` | `live_traffic_info` |
| links | `device_links`, `topology_upward` | `links_view` |
| analytics | `analytics_report` (bad signals, growing errors, duplicated MACs/ONUs, ONU status history ...) | `analytics` |
| pon_boxes | `pon_boxes_list`, `pon_box_get`, `pon_boxes_problems` | `pon_boxes_view` |
| oxidized | `device_config_status`, `device_config` (secrets) | `external_apps_oxidized` |

Tools run with the permissions of the WildCore user owning `WILDCORE_TOKEN`; roles and device groups are
enforced by WildCore itself. In addition, at startup (for HTTP - every `CAPABILITIES_TTL` seconds) the server
reads `GET /system/settings` and hides tools the user has no permission for or whose component is not enabled -
the same way the web panel builds its menu. If WildCore is unreachable at startup, tools are not filtered by
permissions (a warning is logged) and WildCore rejects calls the user is not allowed to make.

`swc_*` are root-only because WildCore has permission rules only for the `system`, `sys_resources` and
`parse_interface` modules. `swc_read` accepts only modules from the explicit `SwitcherCore::READ_MODULES` list:
the `ctrl_` prefix is not enough (`reboot`, `save_config`, `clear_counters`, `console_command`, `cable_diag`,
`arp_ping`, `*_ctrl` also change device state), and new switcher-core modules are treated as writing until listed.

### Generic API tools

The server reads the OpenAPI spec published by WildCore (`{WILDCORE_URL}/swagger/openapi.yaml`, override with
`OPENAPI_URL`), so these tools cover every operation of the installed WildCore version:

1. `api_search` - find operations by words, tag or method; operations of disabled components are skipped;
2. `api_describe` - parameters, request body and response schema of an operation (by operation ID or
   `"GET /device/{id}"`);
3. `api_read` - call a read operation: every GET, plus POST/PUT that only filter or search (logs, analytics
   tables, searches); `api_call` - any other operation (`device-control`, hidden in read-only mode).

Every call must match an operation of the spec. Switcher-core routes are not available here (use `swc_*`, which
check modules), nor the legacy `/universal` dispatcher and authentication routes. Operations returning
credentials (`/device-access`, `/system/configuration`, Oxidized configs) need `ENABLE_SECRETS=true`.

### Tool search mode

About 110 tool definitions take a noticeable part of the model context. With `TOOL_SEARCH_ENABLED=true` the client
sees only `search_tools` (find tools by words, returns their input schemas) and `call_tool` (call one with
validated arguments) - the same filtered tool set, loaded on demand.

### Results

Large lists are sliced with `limit`/`offset` (or `page`/`limit` where WildCore paginates); log records are
compacted (device and user references, error message without stack trace; `verbose: true` returns details).
A result larger than `MAX_RESULT_BYTES` is refused with a request to narrow the query. Log periods ("last 24
hours") are computed in `TZ` - set it to the WildCore server timezone.

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

All settings with comments are in `.env.example`:

| Variable | Default | Meaning |
|---|---|---|
| `WILDCORE_URL` | - | WildCore base URL (required) |
| `WILDCORE_TOKEN` | - | API key of the WildCore user the tools run as |
| `READ_ONLY_MODE` | `true` | only `read-only` tools |
| `DISABLED_TAGS` | - | comma-separated groups or kinds to hide |
| `ENABLE_SECRETS` | `false` | tools and API operations returning credentials |
| `TOOL_SEARCH_ENABLED` | `false` | expose `search_tools` / `call_tool` instead of all tools |
| `TZ` | `UTC` | WildCore server timezone for log periods |
| `OPENAPI_URL` | `{WILDCORE_URL}/swagger/openapi.yaml` | OpenAPI spec for the API tools |
| `MAX_RESULT_BYTES` | `200000` | maximum tool result size |
| `CAPABILITIES_TTL` | `300` | how often the HTTP server reloads permissions, components and the spec |
| `MCP_HTTP_BEARER_TOKEN` | - | bearer token required from HTTP clients |
| `MCP_HTTP_ALLOWED_HOSTS` | `localhost,127.0.0.1,[::1]` | allowed `Host` values (DNS rebinding protection) |
| `MCP_HTTP_HOST`, `MCP_HTTP_PORT`, `MCP_HTTP_WORKERS`, `RATE_LIMIT_PER_MINUTE` | `127.0.0.1`, `8000`, `2`, `120` | HTTP server |

## Development

```bash
docker build -t wildcore-mcp:dev .
# dev dependencies (PHPUnit) into ./vendor, then tests inside the image
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2 \
  composer install --ignore-platform-req=ext-sockets
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app wildcore-mcp:dev php vendor/bin/phpunit
```

A new tool is a public method with `#[McpTool]` in a class from `src/Tools/Core/` (core area) or
`src/Tools/Components/` (`GROUP` and `COMPONENT` = component key); classes are discovered automatically. The
description comes from PHPDoc, the parameter schema from types and `#[Schema]` (use `self::OBJECT` for
`array<string, mixed>` parameters). The kind is derived from annotations:
`readOnlyHint: true` - read-only, `openWorldHint: true` without readOnlyHint - device-control, otherwise
config. Permission and component requirements are declared with `#[Requires]`.

## License

MIT, see [LICENSE](LICENSE).
