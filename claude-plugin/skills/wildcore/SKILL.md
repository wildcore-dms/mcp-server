---
name: wildcore
description: Use when the user works with WildCore DMS or its network - finding devices, switches, OLTs, ports, ONUs/ONTs, subscribers by agreement or MAC, optical signal levels, PON diagnostics, switcher-core modules, macros, or registering new ONUs. Explains which WildCore MCP tools to use, in what order, and the safety rules for actions that change equipment.
---

# Working with WildCore through MCP

The `wildcore` MCP server exposes WildCore DMS. Every call runs with the permissions and device groups of the
WildCore user that owns the configured API key - a tool missing from the list or a "HTTP 403" error means
that user lacks the permission or the WildCore component is not enabled. Say so instead of retrying.

## Finding things

- Unknown identifier (IP, name, agreement, MAC, ONU serial, interface description): start with `search`.
- Device by management IP: `device_find_by_ip`. Device details and poller status: `device_get`.
- Ports of a device: `device_interfaces`. Narrow searches (name, bind key, ONU ident, MAC): `interfaces_search`.
- IDs matter: most tools take `device_id` and `interface_id` (numeric WildCore IDs). OLT/ONU tools take the
  interface **`bind_key`** (`interface.bind_key` in results), not the interface name.

## ONU / optical diagnostics

1. Locate the ONU: `search` with the serial/MAC, or `onts_list` with a filter
   (`field: "ident"`, `expression: "="`, `value: "<serial>"`).
2. Stored data first: `ont_signals` (`interface_id`) gives rx/tx with the min/max envelope collected by the poller;
   `ont_info` with `source: "history"` gives stored history without touching the OLT.
3. Live data only when needed: `ont_info` with `source: "cache"` (refreshes if stale) or `"device"` (always live).
   Live requests load the OLT - avoid them in loops over many ONUs.
4. Weak signal reports: `onts_list` with `field: "optical.rx"`, `expression: "<"`, `value: "-27"`.
   WildCore compares loosely, so ONUs without metrics (`rx: null`) also match - drop them from the report.

Typical GPON ONU receive levels: about -8 to -27 dBm is normal, -27 to -28 is marginal, below -28 dBm usually
means a dirty connector, a bad splice or a too long/split-heavy branch. Treat these as guidance; the operator's
own thresholds win. Compare `rx` with `olt_rx` (upstream) - only one side being bad points at the direction.

## switcher-core

`swc_modules` lists modules supported by the device model. `swc_read` calls read-only modules (e.g. `link_info`,
`fdb`, `pon_onts_optical`, `snooping_info`); pass `arguments: {"interface": "<name>"}` to narrow output.
Prefer `source: "cache"`; `"store"` fails with "Cache not found" when nothing is cached yet. These tools are
available to root WildCore users only.

## Actions that change equipment or data

Tools tagged `device-control` (`macros_execute`, `ont_register`, `swc_call`) change the network; `config` tools
change WildCore data. They are hidden in read-only mode. Before calling any of them:

1. Run the matching preview: `macros_preview` before `macros_execute`, `ont_registration_preview` before
   `ont_register`. For `swc_call` describe the module and arguments.
2. Show the user the exact commands / change and the target device and interface.
3. Get an explicit confirmation for this specific action. Never chain several device-changing calls on one
   confirmation, and never retry a failed device-changing call without asking.

### Macros

`macros_list` (for a device, optionally an interface) -> `macros_get` to see parameters -> `macros_preview` with
`params` -> confirmation -> `macros_execute` with the same arguments.

### ONU registration

`onts_unregistered` (one OLT or all) -> pick the ONU -> `ont_registration_macros` to see the template parameters
-> `ont_registration_preview` passing the **unchanged** ONU record from `onts_unregistered` as `ont` plus `params`
-> confirmation -> `ont_register` with the same `ont` and `params`.

## Large results

Lists are paginated (`limit`/`offset` or `page`/`limit`; `total` / `meta` tell the size). If a tool answers that the
result exceeds the byte limit, narrow the request (device, interface, filter) instead of paging through everything.
Use `api_get` only when no dedicated tool covers the data.
