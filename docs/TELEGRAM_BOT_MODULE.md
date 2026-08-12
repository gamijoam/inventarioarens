# Modulo TelegramBot - Bot de administracion por empresa

Integrado el 2026-08-11 (commits `6407269b`, `92f2549f`, `c7e25b6d`, `a9d543fa`).

El bot permite a cada empresa (tenant) recibir por Telegram un resumen diario de operaciones
y alertas de stock bajo. El acceso es por **lista blanca**: solo los `telegram_chat_id` registrados
en la tabla `telegram_bot_users` reciben respuestas. Todo el resto se ignora en silencio.

## 1. Arquitectura

| Pieza | Ubicacion | Responsabilidad |
|---|---|---|
| Tabla `telegram_bot_users` | `database/migrations/2026_08_12_110000_create_telegram_bot_users_table.php` | Lista blanca: vincula `telegram_chat_id` a `user_id` dentro de un tenant |
| Tabla `tenant_settings` | `database/migrations/2026_08_12_100000_create_tenant_settings_table.php` | Una fila por tenant, JSON de secciones (`telegram`, alertas, etc.) |
| `TelegramApiService` | `app/Modules/TelegramBot/Services/TelegramApiService.php` | Wrapper de la Bot API (`sendMessage`, `setWebhook`) via `Http` de Laravel, sin dependencias externas |
| `TelegramBotService` | `app/Modules/TelegramBot/Services/TelegramBotService.php` | Resuelve el chat (lista blanca activa), deriva visibilidad segun rol y enruta comandos |
| `TelegramReportService` | `app/Modules/TelegramBot/Services/TelegramReportService.php` | Texto del resumen (ventas hoy, POS, cajas, stock bajo, CxC/CxP) reutilizando `DashboardSummaryService` |
| Webhook | `app/Modules/TelegramBot/Controllers/TelegramWebhookController.php` | `POST /telegram/webhook` publico, autenticado por `X-Telegram-Bot-Api-Secret-Token` |
| Handlers | `app/Modules/TelegramBot/Handlers/` | `/start`, `/ayuda`, `/resumen`, `/resumen empresa:<x>`, `/todas` |
| Comandos Artisan | `TelegramLinkCommand`, `TelegramAlertsCommand` | Vincular chat_id; enviar resumen diario y alertas de stock |
| Config | `config/services.php` | Bloque `telegram` (`bot_token`, `webhook_secret`) |
| Frontend | `frontend/src/features/telegram-settings/` | Panel `Configuracion` -> `Telegram` en el Sidebar |

## 2. Configuracion en `.env`

```env
TELEGRAM_BOT_TOKEN=8914439340:AAF8v1gDM_Yemk3qmd7iczk-SzEQKMbntiE
TELEGRAM_WEBHOOK_SECRET=<secret compartido>
```

- `bot_token`: token de BotFather.
- `webhook_secret`: se manda como `secret_token` en `setWebhook` y se valida en cada
  request del webhook via `X-Telegram-Bot-Api-Secret-Token`. Es lo que da autenticidad
  al endpoint publico (Telegram no envia cookies CSRF; el webhook esta excluido de
  `validateCsrfTokens` en `bootstrap/app.php`).

## 3. Webhook

`POST /telegram/webhook` (ruta web, publica):

- Excluida del CSRF: `bootstrap/app.php` -> `validateCsrfTokens(except: ['telegram/webhook'])`.
- Autenticidad: header `X-Telegram-Bot-Api-Secret-Token` contra `TELEGRAM_WEBHOOK_SECRET`.
- Respuesta siempre `204` para que Telegram no reintente.

Registro del webhook (una vez por deploy o cambio de dominio):

```bash
php artisan tinker --execute="
  app(App\Modules\TelegramBot\Services\TelegramApiService::class)
    ->setWebhook('https://app.miinventariofacil.com/telegram/webhook', config('services.telegram.webhook_secret'));
"
```

## 4. Lista blanca (quien tiene acceso)

- La whitelist se sincroniza desde el **panel del frontend**: `TenantSettingController::update`
  llama a `syncTelegramWhitelist()` que **borra todas las filas del tenant y re-inserta** las
  de la lista blanca enviada (delete + insert, es decir lo que envia el panel es la lista completa).
- Vincular desde CLI (para soporte tecnico / automatizacion):

```bash
php artisan telegram:link <tenant-slug> <email> <chat_id> --name="Nombre"
# ejemplo:
php artisan telegram:link oscar-cell gabo@gabo.com 7951437965 --name='Gabo (Master)'
```

- El comando es idempotente: si el `telegram_chat_id` ya esta vinculado a otro user del mismo
  tenant, actualiza; si ya pertenece al mismo user, no duplica.
- Un chat no autorizado que escribe `/start` recibe su `chat_id` como respuesta para que
  se lo pase al administrador y lo agregue a la lista. Cualquier otro comando se ignora en
  silencio (commit `a9d543fa`).

## 5. Visibilidad y comandos

La visibilidad se deriva del rol del user vinculado al chat:

| Rol del user vinculado | Alcance |
|---|---|
| Platform Admin (`is_platform_admin`) | Todas las empresas |
| Owner de grupo | El grupo + sus empresas hijas |
| Administrador | Solo su empresa |

Comandos:

- `/start` - vincula/confirma; responde el `chat_id` si no esta en la lista.
- `/ayuda` - lista los comandos disponibles.
- `/resumen` - resumen del tenant actual.
- `/resumen empresa:<x>` - resumen de la empresa `<x>` (si el user tiene acceso).
- `/todas` - resumen de todas las empresas a las que tiene acceso.

## 6. Alertas programadas

- `php artisan telegram:alerts` corre via `withSchedule` en `bootstrap/app.php` (schedule horario).
- En el VPS hay un cron que ejecuta `php artisan schedule:run` (verificado 2026-08-11).
- Envia:
  - **Resumen diario** a la hora configurada (`report_time` en `tenant_settings.telegram`).
  - **Alertas de stock bajo** segun frecuencia (`daily` / `4h` / `8h`) y umbral
    (`low_stock_threshold`).

## 7. Endpoints API

### `GET /api/tenant-settings` (auth + tenant)

Devuelve la configuracion por empresa:

```json
{
  "data": {
    "tenant_id": 2,
    "settings": {
      "telegram": {
        "whitelist": [
          { "id": 1, "name": "Gabo (Master)", "telegram_id": "7951437965" }
        ]
      }
    }
  }
}
```

La whitelist del response se fusiona desde la tabla `telegram_bot_users` via
`mergeWhitelistInto()` (el JSON de `tenant_settings` no guarda la whitelist; la tabla es la fuente).

### `PATCH /api/tenant-settings` (auth + tenant, Owner/Admin)

```json
{
  "settings": {
    "telegram": {
      "enabled": true,
      "report_time": "21:00",
      "low_stock_alerts": true,
      "low_stock_frequency": "4h",
      "low_stock_threshold": 5,
      "whitelist": [
        { "name": "Gabo (Master)", "telegram_id": "7951437965" }
      ]
    }
  }
}
```

- Actualiza (merge) la seccion `telegram` en `tenant_settings`, preservando otras secciones.
- `whitelist` sincroniza la tabla `telegram_bot_users` (delete + insert): **la lista enviada
  es la lista completa**; si se omite un ID que estaba antes, ese ID deja de tener acceso.
- Solo `Owner` y `Administrador` pueden modificar (`Gate::authorize('update', ...)`); 403 si
  el user autenticado no es miembro del tenant.

## 8. Frontend

- Ruta: `frontend/src/routes/_authed/settings/telegram.tsx` -> `TelegramSettingsPanel`.
- Menu: `Sidebar.tsx` seccion `Configuracion` -> `Telegram`.
- Funcionalidades del panel:
  - Activar/desactivar bot.
  - Hora del resumen diario.
  - Alertas de stock bajo + frecuencia + umbral.
  - Lista blanca editable (agregar/quitar filas `name` + `telegram_id`).
  - Boton `Guardar` dispara `PATCH /api/tenant-settings`.

### Nota operativa (bug detectado 2026-08-11)

Al probar el flujo en el VPS se confirmo que el backend responde correctamente a
`PATCH /api/tenant-settings` (verificado con `whitelist: []` devuelve 200 y deja la tabla vacia).
Si el usuario elimina su ID en el panel pero no presiona **Guardar configuracion**, el cambio
nunca llega al backend (en el log de Laravel solo aparecen GETs y no hay PATCH). El panel edita
el estado local y solo persiste con el boton Guardar.

## 9. Tests

- `tests/Feature/TelegramBot/TelegramBotTest.php` - visibilidad Owner/Admin/Platform, whitelist.
- `tests/Feature/TelegramBot/TelegramWebhookTest.php` - secret token, chat no listado ignorado,
  `/start` (incluye el caso de chat no listado que responde su `chat_id`).
- `tests/Feature/TelegramBot/TelegramAlertsCommandTest.php` - hora de resumen, stock on/off.
- `tests/Feature/TelegramBot/TelegramReportServiceTest.php` - texto del resumen.
- `tests/Feature/Tenancy/TenantSettingTest.php` - auto-create, get/set, aislamiento por tenant.
- `tests/Feature/Tenancy/TenantSettingApiTest.php` - leer, actualizar seccion telegram, preservar
  otras secciones, 403 si no es miembro.

Suite TelegramBot: 16/16 verde; suite Tenancy: 104/104.

## 10. Deploy al VPS

```bash
cd /opt/inventarioarens-cloud
sudo /usr/bin/env git pull
sudo /usr/bin/env composer install --no-dev --optimize-autoloader
sudo /usr/bin/env php artisan migrate --force
sudo /usr/bin/env php artisan optimize:clear
# frontend:
cd frontend && pnpm install && pnpm run build && cd ..
# registrar webhook una sola vez:
sudo -u www-data php artisan tinker --execute="..."
# verificar cron:
crontab -l | grep schedule
```

- Las migraciones son idempotentes (`2026_08_12_100000_*`, `2026_08_12_110000_*`).
- Desplegado y verificado en `a9d543fa` (backend + frontend + migraciones + cron).
