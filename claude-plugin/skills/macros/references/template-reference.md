# Macro template reference

## Macro fields (`macros_create` / `macros_update`)

| Field | Meaning |
|---|---|
| `name`, `description` | shown to operators |
| `display_for` | `DEVICE`, `PORT`, `PON`, `ONU` - pages where the macro is offered |
| `display_output` | `all` - output of every command, `last` - only the last command, `no` - nothing |
| `models` | `[{"id": 87}, ...]` - device models (IDs from `device_models_list`) |
| `user_roles` | `[{"id": 8}, ...]` - roles allowed to run it (`user_roles_list`) |
| `parameters` | form fields, see below |
| `template` | Twig template, one CLI command per rendered line |

## Variables

`macros_template_variables` shows the real values for a device/interface - always check paths there.

| Variable | Content |
|---|---|
| `params` | values of the form fields: `params.<key>` |
| `iface` | the selected interface (port, PON port or ONU): `id`, `name` (as on the device, e.g. `gpon_onu-1/2/3:5`), `bind_key`, `type`, `description`, `status` ...; **null/absent on the device page** |
| `device` | the device: `ip`, `name`, `model` (`key`, `name`, `vendor`), `group`, `params` - custom device parameters set by the admin (e.g. management VLANs) |
| `interfaces_list` | all interfaces of the device: `id`, `type`, `name`, `bind_key`, `status` |
| `data.<module>` | switcher-core data of the device/interface, loaded by context (only modules the model supports) |
| `error.<module>` | error text when a module failed (then `data.<module>` is null) |
| `user` | the operator running the macro: `id`, `name`, `login` |
| `free_onts` | OLT only: free ONU numbers per PON port |

Modules loaded into `data` by context:

| Context | Modules |
|---|---|
| DEVICE | `system`, `vlans`, `card_status`, `pon_profiles`, `unregistered_onts` |
| PORT | `system`, `link_info`, `fdb`, `vlans`, `interface_descriptions`, `parse_interface`, `pon_profiles` |
| PON | `system`, `link_info`, `vlans`, `interface_descriptions`, `parse_interface`, `pon_profiles` |
| ONU | `system`, `pon_onts_status`, `pon_onts_serial`, `pon_onts_mac_addr`, `pon_onts_vendor`, `pon_onts_reasons`, `pon_onts_configuration`, `fdb`, `vlans`, `interface_descriptions`, `parse_interface`, `pon_profiles` |

When a module returns a single record for the interface, `data.<module>` is that record (an object), not a
list - e.g. `data.link_info.oper_status`; `fdb` and `unregistered_onts` always stay lists.
`data.parse_interface` gives the parts of the interface name (slot/port/ONU number, technology) - use them to
build vendor-specific port notations instead of splitting strings.

## Parameters (form fields)

```json
{"key": "vlan", "name": "VLAN", "type": "select_from_variable", "required": true,
 "source_parameter_key": "data.vlans", "item_name": "${item.name} (${item.id})", "item_filter": "item.id != 1",
 "visible_if": ""}
```

| `type` | Form control | Value in `params.<key>` | Fields |
|---|---|---|---|
| `select_from_predefined` | dropdown of fixed values | the chosen string | `variants_list` - values, one per line |
| `select_from_variable` | dropdown of items of an array variable | **the whole item object** (`params.vlan.id`, `params.vlan.name`) plus `_display_name` | `source_parameter_key` (e.g. `interfaces_list`, `data.vlans`, `data.pon_profiles.line`), `item_name` (JS template, default `${item.name}`), `item_filter` (JS condition on `item`) |
| `input_variable` | input prefilled from a scalar variable | string | `source_parameter_key`, `regular_expr` |
| `input_string` | free input | string | `default_value`, `regular_expr` |

Common fields: `required`, `visible_if` - JS condition when the field is shown, e.g. `params.action === 'Add'`
(depends on another field) or `!iface` (only when run from the device page, not from a port).
`regular_expr` is checked on execution - use it for VLAN IDs (`^[0-9]{1,4}$`), MACs, descriptions.

When previewing or executing through the tools, pass `params` as the form would: a string for predefined and
input fields, the full item object (taken from the source array in the variables) for `select_from_variable`.

## Twig rules that matter

- **Strict variables**: a missing key is an error ("Key ... does not exist"). For optional data use
  `{% if data.fdb is defined and data.fdb %}`, `{{ params.speed | default('100M') }}`.
- **Put every `{% ... %}` tag on its own line.** A newline right after a tag is removed, so
  `{% if x %}cmd1{% endif %}` followed by `cmd2` on the next line renders as `cmd1cmd2`. Empty lines are dropped,
  so the rendered template is exactly one command per line.
- Comments: `{# ... #}`. Variables: `{% set port = params.port %}`. Filters: `lower`, `upper`, `replace`,
  `trim`, `join`, `slice`, `length`, `default`, `first`, `last`.
- Loops: `{% for v in params.vlans %}...{% endfor %}` - one command per item.
- Works with and without `iface` (DEVICE + PORT) - the commands go to the `else` branch of the guard:
  ```twig
  {% set port = params.port is defined and params.port ? params.port : null %}
  {% if iface %}
  {% set port = iface %}
  {% endif %}
  {% if not port %}
  <exception "Choose a port">
  {% else %}
  config vlan {{ params.vlan.name }} add untagged {{ port.bind_key }}
  {% endif %}
  ```

## Special commands

Write them on a command line (alone or appended to a command):

| Command | Effect |
|---|---|
| `<sleep x>` | pause `x` seconds (e.g. port off -> sleep -> port on) |
| `<exception "message">` | stop with this error before anything is sent - guards for wrong context/input. The template is still rendered completely first, so put the commands that need the checked data in the `{% else %}` branch - otherwise a Twig error ("Impossible to access an attribute ... on a null variable") comes instead of your message; previews show the rendered `<exception ...>` line, execution stops on it |
| `<cr>` | send an extra Enter (some Huawei MA56xx/MA58xx commands) |
| `<prompt="text">` | wait for this prompt instead of the standard one |
| `<confirm="y">` | answer a confirmation the command always asks |
| `<confirm-if="y","Are you sure">` | answer only if that prompt appears (no hang if it does not) |
| `<stream_timeout=x>` | wait up to `x` seconds (fractions allowed) for a slow command's output |

## Examples

ONU configuration (no parameters, `display_for: ["ONU"]`, ZTE):
```twig
show run int {{ iface.name }}
show onu conf {{ iface.name }}
```

Speed profile from a dropdown (`speed`: `select_from_predefined`, variants `100MB` / `1GB`, ONU, ZTE):
```twig
conf t
interface {{ iface.name }}
tcont 1 name T-INET profile UP-{{ params.speed }}
gemport 1 traffic-limit downstream DOWN-{{ params.speed }}
end
wr
```

VLAN on a port (D-Link, `display_for: ["DEVICE", "PORT"]`): parameters `action` (predefined `Add`/`Delete`),
`type` (predefined `Untagged`/`Tagged`, `visible_if: params.action === 'Add'`), `vlan` (from variable
`data.vlans`, item name `${item.name} (${item.id})`), `port` (from variable `interfaces_list`, `visible_if: !iface`):
```twig
{% set port = params.port %}
{% if iface %}
{% set port = iface %}
{% endif %}
{% if params.action == 'Delete' %}
config vlan {{ params.vlan.name }} delete {{ port.bind_key }}
{% else %}
config vlan {{ params.vlan.name }} add {{ params.type | lower }} {{ port.bind_key }}
{% endif %}
```

Port bounce with a guard (`display_for: ["PORT"]`, D-Link):
```twig
{% if iface.type not in ['FE', 'GE', 'ETH'] %}
<exception "Only for access ports">
{% else %}
config ports {{ iface.bind_key }} state disable
<sleep 5>
config ports {{ iface.bind_key }} state enable
{% endif %}
```

Commands differ between vendors and firmware - check them against the device documentation or an existing
macro for the same model (`macros_manage_list` -> `macros_manage_get`).
