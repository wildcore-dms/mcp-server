---
name: onu-check
description: Check one ONU in WildCore - status, optical levels and recent history - by serial, MAC, agreement or interface
argument-hint: <ONU serial | MAC | agreement | interface>
---

# ONU check

Check the ONU identified by: $ARGUMENTS

Use the `wildcore` MCP tools, read-only only - do not change anything on the OLT or in WildCore.

1. Find the ONU with `search` (query = the identifier). If several matches, list them and ask which one.
2. Get stored optical data with `ont_signals` (`interface_id`) and stored history with `ont_info`
   (`device_id`, `bind_key`, `source: "history"`).
3. Only if stored data is missing or older than an hour, get live data with `ont_info` `source: "cache"`.
4. Report briefly:
   - OLT (name, IP), PON port and ONU interface, description/agreement;
   - status and when it last changed;
   - ONU rx, OLT rx, tx, distance, temperature, and the min/max envelope;
   - verdict: normal / marginal / bad signal (GPON rx about -8..-27 dBm normal, below -28 dBm bad) and the likely
     cause if something is off (e.g. both rx low - fiber/connector; only OLT rx low - upstream direction).
