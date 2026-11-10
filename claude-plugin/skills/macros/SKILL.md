---
name: macros
description: Use when the user wants to write, create, change, fix or explain a WildCore macro (CLI command template run on switches/OLTs/routers from the device, port, PON port or ONU page) or an ONU registration template - including parameters (dropdowns, inputs), Twig template syntax, available variables and special commands like <sleep>, <confirm>, <exception>.
---

# Writing WildCore macros and ONU registration templates

A macro is a **Twig template of CLI commands**. WildCore renders it with variables of the selected device and
interface plus the values of the macro form (`params`), then sends the commands to the device one by one
(switcher-core module `multi_console_command`). An ONU registration template is the same kind of template,
bound to OLT models and rendered for an unregistered ONU.

Read `references/template-reference.md` before writing a template: variables, parameter types, Twig rules,
special commands and examples. Registration templates: `references/registration-templates.md`.

## Workflow - ask as little as possible

Find everything you can yourself; ask the user only for what the network cannot tell you (the intent and,
if unclear, the device commands).

1. **Target models.** From the device or vendor the user mentions: `device_get` / `search` -> model name,
   then `device_models_list` (filter by `vendor` / `type`) for model IDs. Include the sibling models the
   same commands work on (same vendor and series). The model must support `multi_console_command`
   (`swc_modules` on a sample device) - otherwise macros can not run on it, say so.
2. **Where it is offered (`display_for`)**: `DEVICE` for whole-device commands, `PORT` for a switch port,
   `PON` for a PON port, `ONU` for an ONU. Several values are allowed; with both DEVICE and PORT write the
   template so it works with and without `iface` (see the reference).
3. **Roles (`user_roles`)**: if the user did not say, reuse the roles of existing macros for the same models
   (`macros_manage_list` / `macros_manage_get`); if there are none, ask once, listing `user_roles_list`.
4. **Real variables.** Pick a sample device of the model (and an interface for PORT/PON/ONU) and call
   `macros_template_variables`. Build paths from what it actually returns (`data.vlans`, `iface.bind_key`,
   `data.pon_onts_status.status` ...), never from memory - data differs between vendors.
5. **Write parameters and the template** following the reference. Prefer dropdowns from variables over
   free input: fewer mistakes for operators.
6. **Check** with `macros_template_preview` (same device/interface, sample `params`) until the rendered
   commands are exactly right; test every branch of `if` and the case without `iface`.
7. **Create** with `macros_create` (or `macros_update` for an existing macro - get it first with
   `macros_manage_get`). Show the user the final template, parameters, models and roles in the answer.
8. **Never execute** the macro on a device yourself unless the user explicitly asks; if asked, follow the
   preview -> confirmation -> `macros_execute` rule of the `wildcore` skill.

For a template that must not run in some context, use `<exception "message">` (e.g. a port macro started on a
trunk/uplink) instead of relying on operators.

## Fixing a macro

`macros_manage_get` -> reproduce with `macros_template_preview` (the same device/interface/params the user
used) -> fix -> `macros_update`. Errors on execution are in `logs_switcher_core` (module
`multi_console_command`, `verbose: true` shows the device output) and in `logs_actions` (action `macros:execute`).
