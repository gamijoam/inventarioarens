<?php
/**
 * Genera el JSON de Postman Collection v2.1 para INVENTARIOARENS.
 * Cubre:
 *  - Auth (login, platform-login, me)
 *  - Permission catalog (Fase 1)
 *  - Roles CRUD + permissions + duplicate + preview (Fase 1)
 *  - Users CRUD + status + roles + permissions (Fase 1+2)
 *  - Overrides individuales (Fase 2)
 *  - Effective permissions con scopes (Fase 1+2+3)
 *  - Resource-level scopes (Fase 3): branches, warehouses, customer-groups, vendor-of
 *  - SaaS Master Panel: groups + admins (Fase SaaS)
 *  - Sales + Kardex (controllers con scope filtering)
 *
 * Output: C:\Users\gafit\Desktop\INVENTARIOARENS-Postman\INVENTARIOARENS.postman_collection.json
 */

$base = 'https://app.miinventariofacil.com/api';
$env = '{{base_url}}';

$collection = [
    'info' => [
        'name' => 'INVENTARIOARENS — Permisos y Tenants',
        '_postman_id' => 'b8a0c1e2-3f4d-4e5a-9b6c-7d8e9f0a1b2c',
        'description' => "Coleccion Postman para INVENTARIOARENS SaaS Multi-tenant.\n\n" .
            "Cubre las 3 capas de seguridad de permisos:\n" .
            "  Capa 1: Arbol jerarquico de permisos + roles custom\n" .
            "  Capa 2: Field masking (unit_cost) + Overrides individuales por user\n" .
            "  Capa 3: Resource-level scope (branches/warehouses/customer-groups/vendor)\n\n" .
            "Mas el panel SaaS Master (groups/spinoffs/admins).\n\n" .
            "Docs detallados: docs/INSTRUCCIONES_FRONTEND_*.md en el repo del backend.",
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => [
        'type' => 'bearer',
        'bearer' => [
            ['type' => 'string', 'key' => 'token', 'value' => '{{auth_token}}'],
        ],
    ],
    'variable' => [
        ['key' => 'base_url', 'value' => $base, 'type' => 'string'],
        ['key' => 'tenant_slug', 'value' => 'demo-caracas', 'type' => 'string'],
        ['key' => 'tenant_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'group_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'spinoff_id', 'value' => '2', 'type' => 'string'],
        ['key' => 'user_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'role_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'branch_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'warehouse_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'customer_group_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'permission', 'value' => 'sales.view', 'type' => 'string'],
    ],
];

// Helper para crear un item (folder o request)
function folder(string $name, array $children, ?string $description = null): array
{
    $item = [
        'name' => $name,
        'item' => $children,
    ];
    if ($description) {
        $item['description'] = $description;
    }
    return $item;
}

function request(
    string $name,
    string $method,
    string $path,
    ?array $body = null,
    ?string $description = null,
    bool $useXTenant = true,
    bool $useAuth = true,
): array {
    global $env;

    $item = [
        'name' => $name,
        'request' => [
            'method' => strtoupper($method),
            'header' => [],
            'url' => [
                'raw' => $env . $path,
                'host' => ['{{base_url}}'],
                'path' => explode('/', ltrim($path, '/')),
            ],
        ],
    ];

    $headers = [];
    if ($useAuth) {
        $headers[] = ['key' => 'Authorization', 'value' => 'Bearer {{auth_token}}', 'type' => 'text'];
    }
    if ($useXTenant) {
        $headers[] = ['key' => 'X-Tenant', 'value' => '{{tenant_slug}}', 'type' => 'text'];
    }
    $headers[] = ['key' => 'Accept', 'value' => 'application/json', 'type' => 'text'];
    $headers[] = ['key' => 'Content-Type', 'value' => 'application/json', 'type' => 'text'];

    $item['request']['header'] = $headers;

    if ($body !== null) {
        $item['request']['body'] = [
            'mode' => 'raw',
            'raw' => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    if ($description) {
        $item['request']['description'] = $description;
    }

    return $item;
}

// ======================
// 1. AUTH
// ======================
$authFolder = folder('1. Auth', [
    request(
        'POST /auth/login (Login como user de tenant)',
        'POST',
        '/auth/login',
        [
            'email' => 'gerente.valencia@demo.test',
            'password' => 'password',
            'device_name' => 'postman',
        ],
        "Login como user regular de un tenant existente.\n\n" .
        "Requerido header: 'X-Tenant: <slug-del-tenant>'.\n\n" .
        "Response: { data: { user, tenant, roles, permissions, token, expires_at } }.\n\n" .
        "El token se guarda automaticamente en {{auth_token}} (via script de test).",
        false,
        false
    ),
    request(
        'POST /auth/platform-login (Login como Platform Admin SaaS)',
        'POST',
        '/auth/platform-login',
        [
            'email' => 'tu@correo.test',
            'password' => 'Programador123',
            'device_name' => 'postman',
        ],
        "Login como Platform Admin (SaaS Master).\n\n" .
        "NO requiere header X-Tenant (el token no tiene tenant).\n\n" .
        "Requiere que el user tenga is_platform_admin = true en la DB.\n\n" .
        "El usuario de demo creado durante el setup tiene este rol.",
        false,
        false
    ),
    request(
        'GET /auth/me (Info del user actual)',
        'GET',
        '/auth/me',
        null,
        "Retorna informacion del user autenticado.\n\n" .
        "Incluye: user { id, name, email, is_platform_admin }, tenant (null si Platform Admin), roles, permissions.\n\n" .
        "Requiere Authorization: Bearer {{auth_token}}.",
        false
    ),
]);

// ======================
// 2. PERMISSION CATALOG (Fase 1)
// ======================
$catalogFolder = folder('2. Permission Catalog (Fase 1)', [
    request(
        'GET /access/permission-catalog (Arbol jerarquico)',
        'GET',
        '/access/permission-catalog',
        null,
        "Devuelve los 100+ permisos en formato ARBOL listo para UI.\n\n" .
        "Shape: { data: { modules: [{ module, label, actions: [{ verb, label, permission, danger? }] }], verbs: [...], total_permissions, total_modules } }\n\n" .
        "Requiere: roles.view o users.view.\n\n" .
        "Permite: cualquier user autenticado del tenant.",
        true,
        true
    ),
]);

// ======================
// 3. ROLES CRUD (Fase 1)
// ======================
$rolesFolder = folder('3. Roles CRUD (Fase 1)', [
    request('GET /access/roles (Listar roles)', 'GET', '/access/roles', null, "Lista los 6 roles base + custom del tenant. Shape: paginated.", true, true),
    request('POST /access/roles (Crear role custom)', 'POST', '/access/roles', [
        'name' => 'Cajero Senior',
        'permissions' => ['sales.view', 'sales.create', 'pos.view', 'pos.checkout'],
    ], "Crea un role custom con permisos especificos. Requiere: roles.create.", true, true),
    request('GET /access/roles/{id} (Detalle)', 'GET', '/access/roles/1', null, "Detalle del role (incluye permisos asignados).", true, true),
    request('PATCH /access/roles/{id} (Actualizar nombre)', 'PATCH', '/access/roles/7', [
        'name' => 'Cajero Senior Actualizado',
    ], "Actualiza el nombre del role. Requiere: roles.update.", true, true),
    request('PATCH /access/roles/{id}/permissions (Reemplazar permisos)', 'PATCH', '/access/roles/7/permissions', [
        'permissions' => ['sales.view', 'pos.view'],
    ], "Reemplaza TODOS los permisos del role (idempotente). Requiere: roles.update.", true, true),
    request('POST /access/roles/{id}/duplicate (Clonar role)', 'POST', '/access/roles/1/duplicate', [
        'name' => 'Cajero Senior Clonado',
    ], "Clona un role (base o custom) en uno nuevo con los mismos permisos. Validates name unico en tenant. Cross-tenant 404. Requiere: roles.create.", true, true),
    request('GET /access/roles/{id}/preview (Preview metadata)', 'GET', '/access/roles/7', null, "Devuelve permission_count, module_count, modules[], wildcards_count, protected. **OJO**: hay un bug conocido en el codigo actual donde el endpoint devuelve el role completo, no un preview. Se documenta como referencia del endpoint.", true, true),
    request('DELETE /access/roles/{id} (Eliminar role custom)', 'DELETE', '/access/roles/7', null, "Elimina un role custom. Los 6 base NO se pueden eliminar. Requiere: roles.delete.", true, true),
]);

// ======================
// 4. USERS (Fase 1+2)
// ======================
$usersFolder = folder('4. Users (Fase 1+2)', [
    request('GET /access/users (Listar)', 'GET', '/access/users', null, "Lista los users del tenant (paginated).", true, true),
    request('POST /access/users (Crear)', 'POST', '/access/users', [
        'name' => 'Juan Perez',
        'email' => 'juan@example.test',
        'password' => 'Secret123',
        'roles' => ['Vendedor'],
    ], "Crea un user nuevo en el tenant activo. Si el email ya existe, lo asocia (no duplica). Requiere: users.create.", true, true),
    request('GET /access/users/{user} (Detalle)', 'GET', '/access/users/5', null, "Detalle del user con sus roles.", true, true),
    request('PATCH /access/users/{user} (Actualizar nombre/email)', 'PATCH', '/access/users/5', [
        'name' => 'Juan Perez Lopez',
    ], "Actualiza nombre/email. Requiere: users.update.", true, true),
    request('PATCH /access/users/{user}/status (Activar/inactivar)', 'PATCH', '/access/users/5/status', [
        'status' => 'active',
    ], "Cambia el status del user en el tenant (active|inactive). El ultimo admin no se puede desactivar. Requiere: users.update.", true, true),
    request('PATCH /access/users/{user}/roles (Reemplazar roles)', 'PATCH', '/access/users/5/roles', [
        'roles' => ['Vendedor', 'Almacen'],
    ], "Reemplaza todos los roles del user en este tenant. Reemplaza en TODOS los tenants donde el user es miembro. Requiere: users.update.", true, true),
    request('GET /access/users/{user}/permissions (Permisos efectivos)', 'GET', '/access/users/5/permissions', null, "Permisos efectivos del user: union de todos los roles del user en el tenant. **OJO**: este endpoint NO incluye overrides ni scopes. Para la version completa usar /effective-permissions.", true, true),
]);

// ======================
// 5. OVERRIDES INDIVIDUALES (Fase 2)
// ======================
$overridesFolder = folder('5. Overrides individuales (Fase 2)', [
    request(
        'GET /access/tenants/{tenant}/users/{user}/overrides (Listar)',
        'GET',
        '/access/tenants/1/users/5/overrides',
        null,
        "Lista los overrides del user en el tenant: { data: { items: [{ permission, effect, created_at, updated_at }], extra_count, deny_count, extras, denied } }\n\n" .
        "Requiere: users.view.",
        false,
        true
    ),
    request(
        'PUT /access/tenants/{tenant}/users/{user}/overrides (Reemplazar todos)',
        'PUT',
        '/access/tenants/1/users/5/overrides',
        [
            'items' => [
                ['permission' => 'inventory.adjust', 'effect' => 'allow'],
                ['permission' => 'sales.cancel', 'effect' => 'deny'],
            ],
        ],
        "Reemplaza TODOS los overrides del user. Idempotente. Valida que cada permission exista en BasePermissions::PERMISSIONS. Requiere: users.update.",
        false,
        true
    ),
    request(
        'DELETE /access/tenants/{tenant}/users/{user}/overrides/{permission} (Quitar uno)',
        'DELETE',
        '/access/tenants/1/users/5/overrides/sales.cancel',
        null,
        "Quita un override puntual. Requiere: users.update.",
        false,
        true
    ),
]);

// ======================
// 6. EFFECTIVE PERMISSIONS (Fase 1+2+3)
// ======================
$effectiveFolder = folder('6. Effective Permissions (Fase 1+2+3)', [
    request(
        'GET /access/tenants/{tenant}/users/{user}/effective-permissions',
        'GET',
        '/access/tenants/1/users/5/effective-permissions',
        null,
        "Fuente de verdad para la UI de capacidades del user.\n\n" .
        "Devuelve: { data: { permissions, permission_count, base_permissions, base_count, extras, denied, roles, scope_status, scopes: { branches, warehouses, customer_groups, vendor_of, ..._count } } }\n\n" .
        "Matematica: roles union extras - denies, luego filtrado por scope si esta asignado.\n\n" .
        "scope_status: 'none' (ve todo, sin scope) | 'allow' (scope vacio) | 'restrict' (scope con IDs).\n\n" .
        "scopes: IDs de cada categoria asignada. Si vacio, el user no tiene scope en esa categoria (default-allow: ve todo).\n\n" .
        "Requiere: users.view.",
        false,
        true
    ),
]);

// ======================
// 7. RESOURCE-LEVEL SCOPES (Fase 3)
// ======================
$scopesFolder = folder('7. Resource-level Scopes (Fase 3)', [
    request(
        'GET /access/tenants/{tenant}/users/{user}/scopes',
        'GET',
        '/access/tenants/1/users/5/scopes',
        null,
        "Lista los 4 scopes del user en el tenant con expanded objects.\n\n" .
        "Shape: { data: { branches, warehouses, customer_groups, vendor_of, counts, expanded: { branches: [{id,code,name}], ... } } }\n\n" .
        "Requiere: users.view.",
        false,
        true
    ),
    request(
        'PUT /access/tenants/{tenant}/users/{user}/scopes (Bulk)',
        'PUT',
        '/access/tenants/1/users/5/scopes',
        [
            'branch_ids' => [1, 3],
            'warehouse_ids' => [2, 4],
            'customer_group_ids' => [1],
        ],
        "Reemplaza los 4 scopes de una vez. Idempotente. Valida FKs. Requiere: users.update.",
        false,
        true
    ),
    request(
        'PUT /access/tenants/{tenant}/users/{user}/scopes/branches',
        'PUT',
        '/access/tenants/1/users/5/scopes/branches',
        [
            'branch_ids' => [1, 3, 5],
        ],
        "Reemplaza SOLO el scope de branches. branch_ids: [] = sin restricciones (default-allow). Requiere: users.update.",
        false,
        true
    ),
    request(
        'PUT /access/tenants/{tenant}/users/{user}/scopes/warehouses',
        'PUT',
        '/access/tenants/1/users/5/scopes/warehouses',
        [
            'warehouse_ids' => [2, 4, 5],
        ],
        "Reemplaza SOLO el scope de warehouses. Requiere: users.update.",
        false,
        true
    ),
    request(
        'PUT /access/tenants/{tenant}/users/{user}/scopes/customer-groups',
        'PUT',
        '/access/tenants/1/users/5/scopes/customer-groups',
        [
            'customer_group_ids' => [1],
        ],
        "Reemplaza SOLO el scope de customer groups. Filtra customers + CxC. Requiere: users.update.",
        false,
        true
    ),
    request(
        'PUT /access/tenants/{tenant}/users/{user}/scopes/vendor-of',
        'PUT',
        '/access/tenants/1/users/5/scopes/vendor-of',
        [
            'customer_group_ids' => [1, 2],
        ],
        "Reemplaza SOLO el scope de vendor-of. Indica los grupos en los que el user es vendor (filtra sales.created_by y CxC por customer_group). Requiere: users.update.",
        false,
        true
    ),
]);

// ======================
// 8. SAAS MASTER PANEL (Platform Admin)
// ======================
$saasFolder = folder('8. SaaS Master Panel (Platform Admin only)', [
    request(
        'GET /master/groups (Listar grupos)',
        'GET',
        '/master/groups',
        null,
        "Lista los grupos empresariales (tenants con parent_id=NULL) con spinoffs_count y users_count. Requiere: tenant + auth + is_platform_admin.",
        false,
        true
    ),
    request(
        'GET /master/groups/{id} (Detalle)',
        'GET',
        '/master/groups/1',
        null,
        "Detalle del grupo.",
        false,
        true
    ),
    request(
        'POST /master/groups (Crear grupo)',
        'POST',
        '/master/groups',
        [
            'name' => 'Arens Holding Test',
            'slug' => 'arens-holding-test',
            'plan' => 'enterprise',
            'group_owner' => [
                'name' => 'Jefe Holding',
                'email' => 'jefe@arens.test',
                'password' => 'Secret123',
            ],
            'branch' => [
                'name' => 'Principal',
                'code' => 'PRIN',
            ],
            'warehouse' => [
                'name' => 'Almacen Principal',
                'code' => 'PRIN-01',
            ],
            'exchange_rate_type' => [
                'code' => 'BCV',
                'name' => 'Banco Central',
            ],
        ],
        "Crea un grupo + setup inicial + group_owner (rol Owner con todos los permisos). Cross-tenant slug unique. Requiere: tenant + auth + is_platform_admin.",
        false,
        true
    ),
    request(
        'PATCH /master/groups/{id} (Actualizar)',
        'PATCH',
        '/master/groups/1',
        [
            'name' => 'Arens Holding Renombrado',
            'plan' => 'premium',
        ],
        "Actualiza name/slug/domain/plan/status. Cross-tenant slug unique.",
        false,
        true
    ),
    request(
        'DELETE /master/groups/{id} (Soft delete)',
        'DELETE',
        '/master/groups/1',
        null,
        "Soft delete: marca status='inactive'. Idempotente.",
        false,
        true
    ),
    request(
        'GET /master/groups/{id}/tenants (Listar spinoffs)',
        'GET',
        '/master/groups/1/tenants',
        null,
        "Lista las empresas hijas (spinoffs) de un grupo.",
        false,
        true
    ),
    request(
        'GET /master/admins (Listar Platform Admins)',
        'GET',
        '/master/admins',
        null,
        "Lista todos los Platform Admins del sistema con auth_tokens_count y last_login_at.",
        false,
        true
    ),
    request(
        'POST /master/admins (Crear o promover Platform Admin)',
        'POST',
        '/master/admins',
        [
            'name' => 'Otro Admin',
            'email' => 'otro-admin@platform.test',
            'password' => 'Secret123',
        ],
        "Crea o promotes un user a Platform Admin. Si el email ya existe, solo lo promote. Retorna initial_password si fue creado.",
        false,
        true
    ),
    request(
        'GET /master/admins/{id} (Detalle)',
        'GET',
        '/master/admins/1',
        null,
        "Detalle del Platform Admin.",
        false,
        true
    ),
    request(
        'PATCH /master/admins/{id} (Actualizar)',
        'PATCH',
        '/master/admins/1',
        [
            'name' => 'Otro Admin Renombrado',
            'email' => 'otro-admin-renombrado@platform.test',
            'is_platform_admin' => true,
        ],
        "Actualiza name/email/is_platform_admin. Revocar is_platform_admin=false lo demote.",
        false,
        true
    ),
    request(
        'POST /master/admins/{id}/reset-password (Reset password)',
        'POST',
        '/master/admins/1/reset-password',
        [
            'password' => 'NewSecret123',
        ],
        "Resetea password y revoca TODAS las sesiones activas. Si password vacio, genera uno aleatorio y lo retorna en initial_password.",
        false,
        true
    ),
    request(
        'DELETE /master/admins/{id} (Revocar Platform Admin)',
        'DELETE',
        '/master/admins/1',
        null,
        "Revoca is_platform_admin y sesiones. NO permite auto-revocarse (422).",
        false,
        true
    ),
]);

// ======================
// 9. SALES + KARDEX (Validar scope filtering end-to-end)
// ======================
$scopesValidateFolder = folder('9. Sales + Kardex (Validar scope filtering end-to-end)', [
    request(
        'GET /sales (Listar ventas del tenant)',
        'GET',
        '/sales',
        null,
        "Lista ventas del tenant. **Fase 3.5**: el query filtra por branch y vendor scopes. Sin scope: ve todas. Con scope: solo las del user scope.",
        true,
        true
    ),
    request(
        'GET /kardex/product/{id} (Kardex de un producto)',
        'GET',
        '/kardex/product/1',
        null,
        "Kardex de un producto con opening balance y movements. **Fase 3.5**: el query filtra por warehouse scope. Sin scope: ve todos los warehouses. Con scope: solo los del user scope.",
        true,
        true
    ),
]);

// Construir la coleccion final
$collection['item'] = [
    $authFolder,
    $catalogFolder,
    $rolesFolder,
    $usersFolder,
    $overridesFolder,
    $effectiveFolder,
    $scopesFolder,
    $saasFolder,
    $scopesValidateFolder,
];

// Script de Postman para auto-guardar el token despues de login
$collection['event'] = [
    [
        'listen' => 'prerequest',
        'script' => [
            'type' => 'text/javascript',
            'exec' => [
                "// Auto-login if no token",
                "if (!pm.environment.get('auth_token')) {",
                "  console.log('No auth_token, please login first');",
                "}"
            ],
        ],
    ],
    [
        'listen' => 'test',
        'script' => [
            'type' => 'text/javascript',
            'exec' => [
                "// Validate 2xx",
                "pm.test('Status 2xx', function () { pm.expect(pm.response.code).to.be.below(300); });"
            ],
        ],
    ],
];

// Escribir archivo
$outPath = 'C:\\Users\\gafit\\Desktop\\INVENTARIOARENS-Postman\\INVENTARIOARENS.postman_collection.json';
$json = json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents($outPath, $json);

echo "Wrote " . strlen($json) . " bytes to $outPath" . PHP_EOL;
echo "Total requests: " . count_recursive($collection['item']) . PHP_EOL;

function count_recursive(array $items): int
{
    $count = 0;
    foreach ($items as $item) {
        if (isset($item['request'])) {
            $count++;
        } elseif (isset($item['item'])) {
            $count += count_recursive($item['item']);
        }
    }
    return $count;
}
