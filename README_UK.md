# WildCore MCP Server

[English](README.md) | **Українська**

Сервер [Model Context Protocol](https://modelcontextprotocol.io) для WildCore DMS. Він надає LLM-клієнтам
(Claude Code, Claude Desktop, Cursor тощо) API WildCore у вигляді інструментів (tools): пристрої, інтерфейси,
ONU та оптичні сигнали, журнали й помилки викликів, події, модулі switcher-core, макроси, реєстрацію ONU та
компоненти (комутатори, маршрутизатори, керування OLT, pinger, трапи, топологія, аналітика ...), а також
універсальний доступ до будь-якої операції API через OpenAPI-специфікацію вашого екземпляра WildCore.

Це окремий сервіс, який звертається до REST API WildCore (`/api/v1`) з API-ключем користувача, за зразком
[librenms-mcp](https://github.com/mhajder/librenms-mcp). У сам WildCore нічого не вбудовується.

```
MCP-клієнт ──MCP (stdio | Streamable HTTP)──▶ wildcore-mcp ──HTTP + X-Auth-Key──▶ WildCore /api/v1
```

Стек: PHP 8.5, офіційний [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk) (`mcp/sdk`),
Guzzle, [RoadRunner](https://roadrunner.dev) 3 для HTTP.

## Групи інструментів

Інструменти згруповано так само, як влаштовано сам WildCore:

- групи **ядра** - розділи ядра WildCore: `devices`, `interfaces`, `onts`, `logs`, `poller`, `switcher-core`, `users`, `api`;
- групи **компонентів** - мають назву ключа компонента WildCore (`olts`, `macros`, `events` ...); вони
  показуються лише тоді, коли цей компонент увімкнено у WildCore.

Кожен інструмент має теги (у `_meta.tags`): свою групу, свій вид і, для облікових даних, `secrets`.

- `read-only` - лише читає дані;
- `device-control` - змінює стан обладнання (виконання макросів, реєстрація та керування ONU, зміни портів, перезавантаження);
- `config` - змінює дані WildCore (макроси, шаблони реєстрації, закриття подій, скидання min/max сигналу);
- `secrets` - повертає облікові дані (конфігурації пристроїв з Oxidized, журнали консольних сесій, профілі доступу
  через API-інструменти); показуються лише з `ENABLE_SECRETS=true`.

`READ_ONLY_MODE=true` (за замовчуванням) залишає лише інструменти `read-only`. `DISABLED_TAGS` вимикає групи чи
види, наприклад `DISABLED_TAGS=switches_control,olts_control,api`.

### Ядро

| Група | Інструменти | Потрібно у WildCore |
|---|---|---|
| devices | `devices_list`, `device_get`, `device_find_by_ip`, `search`, `device_groups_list`, `device_models_list` | `device_show`; `search` - `global_search`; групи - `system_info` |
| interfaces | `interfaces_list`, `interfaces_search`, `device_interfaces`, `interface_get`, `interface_history`, `interface_types` | `device_show` |
| onts | `onts_list` (фільтр на кшталт `optical.rx < -27`), `ont_signals`, `ont_signal_reset` | `device_show`; скидання - `device_iface_management` |
| logs | `logs_actions`, `logs_action_names`, `logs_switcher_core`, `logs_switcher_core_modules`, `calling_errors`, `logs_pollers`, `logs_poller_names`, `logs_schedule`, `logs_schedule_keys`, `latest_system_actions` | `system_logs_actions`, `log_switcher_core_actions`, `log_poller`, `schedule_reports` |
| poller | `device_pollers`, `device_poll` | `poller_info_by_device`; опитування - `device_management` |
| switcher-core | `swc_modules`, `swc_read` (лише модулі читання), `swc_call` (будь-який модуль) | лише root-користувач |
| users | `user_roles_list` | `user_self_control` (за замовчуванням у кожної ролі) |
| api | `api_search`, `api_describe`, `api_read`, `api_call` | право кожної операції |

### Компоненти

| Група (компонент) | Інструменти | Потрібно у WildCore |
|---|---|---|
| olts | `olt_onts`, `ont_info`, `olt_pon_ports`, `olt_physical_ports`, `olt_snooping`, `olt_cards`, `olt_resources`, `olt_onts_blacklist` | `olts_info` |
| olts_control | `ont_reboot`, `ont_set_state`, `ont_set_description`, `ont_uni_port_set`, `ont_reset`, `ont_deregister`, `olt_port_reset`, `olt_port_set_description` | `olts_ctrl_*` для кожної дії |
| onts_registration | `onts_unregistered`, `ont_find_unregistered`, `ont_registration_macros`, `ont_registration_preview`, `ont_registration_variables`, `ont_register`, `ont_registration_templates`, `ont_registration_template_variables`, `ont_registration_template_preview`, `ont_registration_template_get/create/update/clone/delete` | `unregistered_onts`; шаблони - `unregistered_onts_config` |
| macros | `macros_list`, `macros_get`, `macros_preview`, `macros_variables`, `macros_execute`, `macros_template_variables`, `macros_template_preview`, `macros_manage_list`, `macros_manage_get`, `macros_create`, `macros_update`, `macros_clone`, `macros_delete` | `macros_execute`; керування - `macros_edit` |
| switches | `switch_interfaces`, `switch_vlans`, `switch_snooping`, `switch_resources`, `switch_sfp_diag`, `switch_cable_diag` | `switches_info` |
| switches_control | `switch_port_set`, `switch_clear_counters`, `switch_save_config`, `switch_reboot` | `switches_*` для кожної дії |
| routers | `router_table` (інтерфейси, ARP, FDB, VLAN, BGP-піри, прямі маршрути, ресурси) | `router_info` |
| events | `events_list`, `events_stat`, `event_names`, `event_rules`, `event_resolve` | `events_show`; правила - `events_configuration`; закриття - `events_resolve` |
| pinger | `pinger_status`, `pinger_logs`, `pinger_statuses` | `device_show` |
| trapservice | `traps_history`, `trap_names`, `traps_stat` | `diag_interface` |
| console | `console_logs`, `console_log_get` (secrets) | `external_apps_console_view_logs` |
| fdb_history | `fdb_history` | `fdb_history_by_interface` |
| search_device | `find_mac`, `find_ip` | `sd_search_mac`, `sd_search_ip*` |
| diagnostic | `interface_diagnostic`, `arp_ping` | `diag_interface`, `diag_arp_ping` |
| live_traffic | `interface_live_traffic` | `live_traffic_info` |
| links | `device_links`, `topology_upward` | `links_view` |
| analytics | `analytics_report` (поганий сигнал, зростання помилок, дублікати MAC/ONU, історія статусів ONU ...) | `analytics` |
| oxidized | `device_config_status`, `device_config` (secrets) | `external_apps_oxidized` |

Інструменти виконуються з правами користувача WildCore, якому належить `WILDCORE_TOKEN`; ролі та групи пристроїв
перевіряє сам WildCore. Крім того, під час запуску (для HTTP - кожні `CAPABILITIES_TTL` секунд) сервер читає
`GET /system/settings` і приховує інструменти, на які в користувача немає прав або компонент яких не ввімкнено, -
так само, як веб-панель будує своє меню. Якщо WildCore недоступний під час запуску, інструменти не фільтруються
за правами (у журнал пишеться попередження), і недозволені виклики відхиляє WildCore.

`swc_*` доступні лише root, бо у WildCore є правила прав лише для модулів `system`, `sys_resources` і
`parse_interface`. `swc_read` приймає лише модулі з явного списку `SwitcherCore::READ_MODULES`: префікса `ctrl_`
недостатньо (`reboot`, `save_config`, `clear_counters`, `console_command`, `cable_diag`, `arp_ping`, `*_ctrl` теж
змінюють стан пристрою), а нові модулі switcher-core вважаються такими, що змінюють стан, доки їх не додано до списку.

### Універсальні API-інструменти

Сервер читає OpenAPI-специфікацію, яку публікує WildCore (`{WILDCORE_URL}/swagger/openapi.yaml`, можна змінити через
`OPENAPI_URL`), тож ці інструменти покривають усі операції встановленої версії WildCore:

1. `api_search` - пошук операцій за словами, тегом або методом; операції вимкнених компонентів пропускаються;
2. `api_describe` - параметри, тіло запиту та схема відповіді операції (за ID операції або `"GET /device/{id}"`);
3. `api_read` - виклик операції читання: усі GET, а також POST/PUT, які лише фільтрують чи шукають (журнали,
   таблиці аналітики, пошуки); `api_call` - будь-яка інша операція (`device-control`, прихована в режимі лише читання).

Кожен виклик має відповідати операції зі специфікації. Маршрути switcher-core тут недоступні (використовуйте
`swc_*`, які перевіряють модулі), як і застарілий диспетчер `/universal` та маршрути автентифікації. Операції,
що повертають облікові дані (`/device-access`, `/system/configuration`, конфігурації Oxidized), потребують
`ENABLE_SECRETS=true`.

### Режим пошуку інструментів

Близько 110 описів інструментів займають помітну частину контексту моделі. З `TOOL_SEARCH_ENABLED=true` клієнт
бачить лише `search_tools` (пошук інструментів за словами, повертає їхні схеми вхідних даних) і `call_tool`
(виклик інструмента з перевіркою аргументів) - той самий відфільтрований набір, що завантажується на вимогу.

### Результати

Великі списки розбиваються через `limit`/`offset` (або `page`/`limit`, де пагінацію робить WildCore); записи журналів
стискаються (посилання на пристрій і користувача, повідомлення про помилку без стека викликів; `verbose: true`
повертає подробиці). Результат, більший за `MAX_RESULT_BYTES`, відхиляється з проханням звузити запит. Періоди
журналів ("останні 24 години") обчислюються в `TZ` - вкажіть часовий пояс сервера WildCore.

## Запуск

```bash
cp .env.example .env   # задайте WILDCORE_URL і WILDCORE_TOKEN
docker pull ghcr.io/wildcore-dms/mcp-server:latest
```

Образи збирає GitHub Actions (`.github/workflows/docker.yml`) для `linux/amd64` і `linux/arm64`: `latest` з `main`,
`X.Y.Z` / `X.Y` з тегів `vX.Y.Z`, `sha-<commit>` для кожної збірки. Кожна збірка запускає модульні тести, smoke-тест
образу та сканування Trivy (виправні знахідки CRITICAL/HIGH зупиняють збірку; прийняті винятки з причиною та
терміном дії зберігаються в `.trivyignore`).

### stdio

```bash
docker run -i --rm --env-file .env ghcr.io/wildcore-dms/mcp-server:latest php bin/wildcore-mcp
```

Без Docker: PHP 8.5.12+ або 8.4.27+ з `ext-sockets`, потім `composer install --no-dev && bin/wildcore-mcp`
(налаштування з `.env` у корені проєкту або зі змінних оточення).

### HTTP (Streamable HTTP)

```bash
docker compose -f docker-compose.example.yml up -d   # читає .env з того самого каталогу
curl http://127.0.0.1:8000/health                    # ok
```

- `/mcp` - MCP-ендпоінт, `/health` - перевірка живучості (не звертається до WildCore);
- контейнер обслуговує звичайний HTTP на порту 8000; TLS завершуйте на зворотному проксі перед ним;
- сесії зберігаються файлами в `/tmp`, спільними для всіх воркерів;
- за зворотним проксі вкажіть публічне ім'я хоста в `MCP_HTTP_ALLOWED_HOSTS`.

Усі клієнти HTTP-сервера мають права одного `WILDCORE_TOKEN`; задайте `MCP_HTTP_BEARER_TOKEN` і не відкривайте
порт назовні без нього.

## Підключення до Claude

1. Створіть для Claude окремого користувача WildCore лише з тією роллю та групами пристроїв, які йому потрібні.
2. Створіть для нього API-ключ: Management -> Users -> редагування користувача -> **Create API key** (потрібне
   право `user_auth_key_generate` і ваш поточний пароль). Свідомо оберіть термін дії - після його завершення
   інструменти перестануть працювати.
3. `docker pull ghcr.io/wildcore-dms/mcp-server:latest` - усі приклади нижче використовують цей образ.

`WILDCORE_URL` розв'язується всередині контейнера, тож `localhost` вказує на сам контейнер. Використовуйте адресу
WildCore, доступну з Docker. Для WildCore на тій самій машині вкажіть LAN-адресу хоста або додайте
`--network host` до аргументів docker (неможливо з плагіном, у якого аргументи docker фіксовані).

### Claude Code - плагін (рекомендовано)

Репозиторій є маркетплейсом плагінів Claude Code. Плагін `wildcore` містить MCP-сервер (stdio через Docker) і
skills: `wildcore` (як користуватися інструментами, значення полів, рівні сигналу, правила безпеки для дій,
що змінюють пристрої), `macros` (написання макросів і шаблонів реєстрації ONU: змінні, типи параметрів, правила
Twig, спецкоманди, приклади) і `/wildcore:onu-check <serial|MAC|договір>`.

```
/plugin marketplace add wildcore-dms/mcp-server
/plugin install wildcore@wildcore
```

У свіжих версіях Claude Code одна команда робить обидва кроки: `/plugin install wildcore --marketplace wildcore-dms/mcp-server`.

Під час встановлення Claude Code запитує URL WildCore, API-ключ (зберігається як секрет, а не у відкритих
конфігураційних файлах), режим лише читання (увімкнено за замовчуванням) і Docker-образ (за замовчуванням
`ghcr.io/wildcore-dms/mcp-server:latest`). Значення можна змінити пізніше в налаштуваннях плагіна в `/plugin`.
Перезапустіть Claude Code і перевірте підключення через `/mcp`: сервер `wildcore` має бути підключений і показувати
свої інструменти.

### Claude Code - без плагіна

stdio (локальний Docker). API-ключ потрапляє відкритим текстом у конфігурацію Claude Code (`~/.claude.json`);
якщо це важливо, використовуйте плагін.

```bash
claude mcp add wildcore \
  -e WILDCORE_URL=https://wildcore.example.com -e WILDCORE_TOKEN=<api key> -e READ_ONLY_MODE=true \
  -- docker run -i --rm -e WILDCORE_URL -e WILDCORE_TOKEN -e READ_ONLY_MODE ghcr.io/wildcore-dms/mcp-server:latest php bin/wildcore-mcp
```

HTTP (спільний сервер, запущений з `docker-compose.example.yml`):

```bash
claude mcp add --transport http wildcore https://mcp.example.com/mcp --header "Authorization: Bearer <MCP_HTTP_BEARER_TOKEN>"
```

Додайте `--scope project`, щоб зберегти сервер у `.mcp.json` поточного проєкту (спільний з командою через git;
секрети тримайте у змінних оточення: `"headers": {"Authorization": "Bearer ${WILDCORE_MCP_TOKEN}"}`).

### Claude Desktop

Settings -> Developer -> Edit Config, додайте до `claude_desktop_config.json` і перезапустіть Claude Desktop.
На macOS Claude Desktop може не бачити Docker у `PATH` - тоді вкажіть повний шлях у `command`
(`/usr/local/bin/docker` або `/opt/homebrew/bin/docker`; перевірте через `which docker`).

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

### claude.ai та віддалені конектори

Власні конектори в claude.ai / Claude Desktop (Settings -> Connectors) підтримують лише OAuth або роботу без
автентифікації - статичний bearer-токен (`MCP_HTTP_BEARER_TOKEN`) там не приймається, а сервер має бути доступний
з Anthropic через публічний HTTPS. Цей сервер не реалізує OAuth, тому використовуйте Claude Code або локальну
конфігурацію Claude Desktop вище. Не відкривайте екземпляр без автентифікації в інтернет.

## Налаштування

Усі налаштування з коментарями - в `.env.example`:

| Змінна | За замовчуванням | Значення |
|---|---|---|
| `WILDCORE_URL` | - | базова адреса WildCore (обов'язково) |
| `WILDCORE_TOKEN` | - | API-ключ користувача WildCore, від імені якого працюють інструменти |
| `READ_ONLY_MODE` | `true` | лише інструменти `read-only` |
| `DISABLED_TAGS` | - | групи або види через кому, які треба приховати |
| `ENABLE_SECRETS` | `false` | інструменти та API-операції, що повертають облікові дані |
| `TOOL_SEARCH_ENABLED` | `false` | надавати `search_tools` / `call_tool` замість усіх інструментів |
| `TZ` | `UTC` | часовий пояс сервера WildCore для періодів журналів |
| `OPENAPI_URL` | `{WILDCORE_URL}/swagger/openapi.yaml` | OpenAPI-специфікація для API-інструментів |
| `MAX_RESULT_BYTES` | `200000` | максимальний розмір результату інструмента |
| `CAPABILITIES_TTL` | `300` | як часто HTTP-сервер перечитує права, компоненти та специфікацію |
| `MCP_HTTP_BEARER_TOKEN` | - | bearer-токен, який вимагається від HTTP-клієнтів |
| `MCP_HTTP_ALLOWED_HOSTS` | `localhost,127.0.0.1,[::1]` | дозволені значення `Host` (захист від DNS rebinding) |
| `MCP_HTTP_HOST`, `MCP_HTTP_PORT`, `MCP_HTTP_WORKERS`, `RATE_LIMIT_PER_MINUTE` | `127.0.0.1`, `8000`, `2`, `120` | HTTP-сервер |

## Розробка

```bash
docker build -t wildcore-mcp:dev .
# dev-залежності (PHPUnit) у ./vendor, потім тести всередині образу
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2 \
  composer install --ignore-platform-req=ext-sockets
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app wildcore-mcp:dev php vendor/bin/phpunit
```

Новий інструмент - це публічний метод з `#[McpTool]` у класі з `src/Tools/Core/` (розділ ядра) або
`src/Tools/Components/` (`GROUP` і `COMPONENT` = ключ компонента); класи знаходяться автоматично. Опис береться з
PHPDoc, схема параметрів - з типів і `#[Schema]` (для параметрів `array<string, mixed>` використовуйте `self::OBJECT`).
Вид визначається анотаціями: `readOnlyHint: true` - read-only, `openWorldHint: true` без readOnlyHint -
device-control, інакше config. Вимоги до прав і компонентів оголошуються через `#[Requires]`.

## Ліцензія

MIT, див. [LICENSE](LICENSE).
