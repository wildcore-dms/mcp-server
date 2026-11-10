# ONU registration templates

A registration template (component `onts_registration`) renders the commands that register an unregistered ONU
on an OLT. It uses the same Twig rules, parameter format and special commands as macros
(`template-reference.md`), with these differences:

- **One template per OLT model** - creating a second template for a model fails. Check
  `ont_registration_templates` first and update the existing one instead.
- Fields: `name`, `enabled`, `models`, `parameters`, `template` (no roles, no display_for).
- The context is an unregistered ONU instead of an interface.

## Variables

Get the real values with `ont_registration_template_variables` (OLT device ID + the ONU record exactly as
returned by `onts_unregistered`).

| Variable | Content |
|---|---|
| `ont` | the unregistered ONU: serial (GPON) or `mac_address` (EPON), `_serial_hex` (serial in hex, as many OLTs expect), the PON port reference, vendor/model data when the OLT reports it |
| `iface` | the PON port the ONU is on: `name`, `bind_key`, `_port` (port in OLT notation), `_technology` (`gpon` / `epon`), `_pon_max_ont_size` |
| `free.first` | the first free ONU number on that PON port; `free.all` - all free numbers |
| `device` | the OLT; `device.params` - custom parameters set by the admin (per-OLT VLANs, profiles) |
| `data.<module>` | OLT data, e.g. `data.pon_profiles.line` / `data.pon_profiles.srv` (line and service profiles) |
| `interfaces_list` | interfaces of the OLT with `params` and `description` |
| `params` | values of the form fields |

## Example (C-Data FD16xx FW3, GPON)

Parameters: `line` - `select_from_variable` from `data.pon_profiles.line`, item name `${item.name} (${item.id})`,
item filter `item.id != 0`; `srv` - the same from `data.pon_profiles.srv`.

```twig
interface gpon 0/0
ont add {{ iface._port }} {{ free.first }} sn-auth {{ ont._serial_hex }} ont-lineprofile-id {{ params.line.id }} ont-srvprofile-id {{ params.srv.id }}<confirm-if="y","Are you sure">
```

Per-OLT values belong in `device.params` (e.g. `{{ device.params.internet_vlan | default(100) }}`), not in the
template - one template then serves every OLT of the model.

## Checking and running

1. `ont_registration_template_preview` with a real unregistered ONU and sample `params` until the commands are right.
2. `ont_registration_template_create` / `ont_registration_template_update`.
3. Registration itself: `ont_registration_preview` -> user confirmation -> `ont_register` (`wildcore` skill rules).

Execution stops at the first failing command. If it fails after the ONU was already added, the ONU is
registered but not fully configured: find it (`interfaces_search` with `ont_ident`) and tell the user to
finish or delete it. The full device output is in `logs_switcher_core` (module `multi_console_command`,
`verbose: true`).
