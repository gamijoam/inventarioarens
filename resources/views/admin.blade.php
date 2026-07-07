<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php($appName = config('app.name', 'Sistema de Inventario'))

        <title>Portal administrativo - {{ $appName }}</title>

        @vite(['resources/css/admin.css', 'resources/js/admin.js'])
    </head>
    <body>
        <div class="admin-app" data-app-name="{{ $appName }}">
            <section class="admin-login" data-view="login" aria-labelledby="admin-login-title">
                <div class="admin-login__brand">
                    <div class="brand-orb" aria-hidden="true">SI</div>
                    <p>Portal administrativo</p>
                    <h1 id="admin-login-title">{{ $appName }}</h1>
                    <span>Control gerencial de ventas, inventario, caja y sincronización por empresa.</span>
                </div>

                <form class="admin-login__card" id="admin-login-form">
                    <span class="soft-badge">Acceso gerencial</span>
                    <h2>Iniciar sesión</h2>
                    <p>Busca tus empresas por correo, selecciona una y entra al panel de administración.</p>

                    <label class="field">
                        <span>Correo</span>
                        <input id="admin-email" type="email" autocomplete="email" placeholder="gerente.valencia@demo.test" required>
                    </label>

                    <label class="field">
                        <span>Contraseña</span>
                        <input id="admin-password" type="password" autocomplete="current-password" placeholder="password" required>
                    </label>

                    <button class="ghost-button" type="button" id="admin-load-tenants">
                        Buscar empresas
                    </button>

                    <label class="field tenant-field" id="admin-tenant-field" hidden>
                        <span>Empresa</span>
                        <select id="admin-tenant"></select>
                    </label>

                    <p class="form-status" id="admin-login-status" role="status" aria-live="polite"></p>

                    <button class="primary-button" type="submit" id="admin-login-submit">
                        Entrar al portal
                    </button>
                </form>
            </section>

            <section class="admin-shell" data-view="dashboard" tabindex="-1" hidden>
                <header class="admin-topbar">
                    <div class="topbar-title">
                        <div class="brand-orb brand-orb--small" aria-hidden="true">SI</div>
                        <div>
                            <p>Portal administrativo</p>
                            <h1 id="dashboard-tenant">Empresa</h1>
                        </div>
                    </div>

                    <div class="topbar-actions">
                        <label class="period-control">
                            <span>Periodo</span>
                            <select id="dashboard-period" aria-label="Periodo del dashboard">
                                <option value="today">Hoy</option>
                                <option value="week">Semana</option>
                                <option value="month">Mes</option>
                            </select>
                        </label>
                        <button class="ghost-button" type="button" id="dashboard-refresh">Actualizar</button>
                        <button class="danger-button" type="button" id="admin-logout">Salir</button>
                    </div>
                </header>

                <main class="portal-layout" id="admin-main">
                    <aside class="portal-nav" aria-label="Módulos administrativos">
                        <button class="portal-nav__item is-active" type="button" data-portal-section="overview">
                            <span>Resumen</span>
                            <small>Indicadores</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="sales">
                            <span>Ventas</span>
                            <small>POS y órdenes</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="inventory">
                            <span>Inventario</span>
                            <small>Stock y productos</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="cash">
                            <span>Caja</span>
                            <small>Turnos y cierres</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="users">
                            <span>Usuarios</span>
                            <small>Roles y permisos</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="sync">
                            <span>Sincronización</span>
                            <small>Nodos y eventos</small>
                        </button>
                    </aside>

                    <section class="portal-workspace">
                        <section class="workspace-head">
                            <div>
                                <span class="soft-badge">Resumen ejecutivo</span>
                                <h2>Vista gerencial</h2>
                                <p id="dashboard-period-label">Datos cargados desde el backend.</p>
                            </div>
                            <div class="hero-total">
                                <span>Ventas POS</span>
                                <strong id="metric-pos-total">USD 0.00</strong>
                            </div>
                        </section>

                        <section class="metric-board" aria-label="Métricas principales">
                            <article class="metric-card">
                                <span>Ventas confirmadas</span>
                                <strong id="metric-sales-total">USD 0.00</strong>
                                <small id="metric-sales-count">0 ventas</small>
                            </article>
                            <article class="metric-card metric-card--green">
                                <span>Disponible</span>
                                <strong id="metric-stock-available">0</strong>
                                <small>Unidades vendibles</small>
                            </article>
                            <article class="metric-card metric-card--amber">
                                <span>Cajas abiertas</span>
                                <strong id="metric-open-cash">0</strong>
                                <small id="metric-cash-expected">USD 0.00 esperado</small>
                            </article>
                            <article class="metric-card metric-card--red">
                                <span>Pendientes POS</span>
                                <strong id="metric-pending-pos">0</strong>
                                <small>Órdenes por cerrar</small>
                            </article>
                        </section>

                        <section class="tool-grid">
                            <article class="content-panel">
                                <div class="panel-heading">
                                    <div>
                                        <h3>Inventario</h3>
                                        <p>Productos, stock bajo y disponibilidad.</p>
                                    </div>
                                </div>
                                <div class="inventory-strip">
                                    <div><span>Productos activos</span><strong id="metric-products">0</strong></div>
                                    <div><span>Stock bajo</span><strong id="metric-low-stock">0</strong></div>
                                    <div><span>Sin stock</span><strong id="metric-without-stock">0</strong></div>
                                    <div><span>Reservado</span><strong id="metric-reserved">0</strong></div>
                                </div>
                            </article>

                            <article class="content-panel">
                                <div class="panel-heading">
                                    <div>
                                        <h3>Sincronización</h3>
                                        <p>Estado de nodos y eventos pendientes.</p>
                                    </div>
                                    <span class="status-pill" id="sync-status">Sin datos</span>
                                </div>
                                <div class="sync-list">
                                    <div><span>Nodos activos</span><strong id="metric-sync-nodes">0</strong></div>
                                    <div><span>Pendientes por subir</span><strong id="metric-sync-pending">0</strong></div>
                                    <div><span>Errores</span><strong id="metric-sync-errors">0</strong></div>
                                </div>
                            </article>

                            <article class="content-panel content-panel--wide">
                                <div class="panel-heading">
                                    <div>
                                        <h3>Alertas operativas</h3>
                                        <p>Prioridades que requieren revisión.</p>
                                    </div>
                                </div>
                                <div class="alert-list" id="alert-list"></div>
                            </article>
                        </section>

                        <section class="admin-module-panel inventory-admin" id="admin-inventory-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Inventario</span>
                                    <h3>Productos y precios</h3>
                                    <p>Consulta stock, filtra productos y actualiza precios base para sincronizarlos con las sedes.</p>
                                </div>
                                <button class="ghost-button" type="button" id="admin-inventory-refresh">Actualizar inventario</button>
                            </div>

                            <div class="inventory-admin__layout">
                                <div class="inventory-admin__main">
                                    <div class="inventory-admin__filters" role="search">
                                        <label class="field">
                                            <span>Buscar</span>
                                            <input id="admin-inventory-search" type="search" placeholder="Nombre o SKU">
                                        </label>
                                        <label class="field">
                                            <span>Control</span>
                                            <select id="admin-inventory-tracking">
                                                <option value="">Todos</option>
                                                <option value="quantity">Por cantidad</option>
                                                <option value="serialized">Serializado / IMEI</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>Stock</span>
                                            <select id="admin-inventory-stock">
                                                <option value="all">Todos</option>
                                                <option value="available">Disponible</option>
                                                <option value="low">Stock bajo</option>
                                                <option value="out">Sin stock</option>
                                            </select>
                                        </label>
                                        <button class="primary-button" type="button" id="admin-inventory-apply">Aplicar</button>
                                    </div>

                                    <div class="admin-table-wrap">
                                        <table class="admin-data-table">
                                            <thead>
                                                <tr>
                                                    <th>Producto</th>
                                                    <th>Control</th>
                                                    <th>Precio base</th>
                                                    <th>Disponible</th>
                                                    <th>Reservado</th>
                                                    <th>Estado</th>
                                                    <th>Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-inventory-table"></tbody>
                                        </table>
                                    </div>

                                    <div class="table-footer">
                                        <span id="admin-inventory-count">Sin productos cargados.</span>
                                        <div class="table-footer__actions">
                                            <button class="ghost-button" type="button" id="admin-inventory-prev">Anterior</button>
                                            <button class="ghost-button" type="button" id="admin-inventory-next">Siguiente</button>
                                        </div>
                                    </div>
                                </div>

                                <aside class="inventory-editor" id="admin-inventory-editor" hidden>
                                    <span class="soft-badge">Edición rápida</span>
                                    <h4 id="admin-inventory-editor-title">Producto</h4>
                                    <p id="admin-inventory-editor-subtitle">Selecciona un producto para editar su precio base.</p>

                                    <label class="field">
                                        <span>Precio base</span>
                                        <input id="admin-inventory-price" type="number" min="0" step="0.01" placeholder="0.00">
                                    </label>

                                    <label class="field">
                                        <span>Moneda</span>
                                        <select id="admin-inventory-currency">
                                            <option value="USD">USD</option>
                                            <option value="VES">VES</option>
                                        </select>
                                    </label>

                                    <button class="primary-button" type="button" id="admin-inventory-save">Guardar cambios</button>
                                    <button class="ghost-button" type="button" id="admin-inventory-cancel">Cancelar</button>
                                </aside>
                            </div>

                            <p class="dashboard-status" id="admin-inventory-status" role="status" aria-live="polite"></p>
                        </section>

                        <section class="admin-module-panel access-admin" id="admin-users-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Accesos</span>
                                    <h3>Usuarios y permisos</h3>
                                    <p>Administra usuarios por empresa, asigna roles y controla permisos por modulo.</p>
                                </div>
                                <button class="ghost-button" type="button" id="admin-access-refresh">Actualizar accesos</button>
                            </div>

                            <div class="access-admin__grid">
                                <section class="access-panel access-panel--wide" aria-label="Usuarios de la empresa">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Usuarios</h4>
                                            <p>Solo se muestran usuarios vinculados a la empresa activa.</p>
                                        </div>
                                        <span class="status-pill" id="admin-access-users-count">Sin cargar</span>
                                    </div>

                                    <div class="admin-table-wrap admin-table-wrap--compact">
                                        <table class="admin-data-table admin-data-table--compact">
                                            <thead>
                                                <tr>
                                                    <th>Usuario</th>
                                                    <th>Estado</th>
                                                    <th>Roles</th>
                                                    <th>Accion</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-access-users-table"></tbody>
                                        </table>
                                    </div>
                                </section>

                                <aside class="access-panel">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Nuevo usuario</h4>
                                            <p>Crea o vincula un usuario existente a esta empresa.</p>
                                        </div>
                                    </div>

                                    <label class="field">
                                        <span>Nombre</span>
                                        <input id="admin-access-user-name" type="text" autocomplete="name" placeholder="Ej. Cajero principal">
                                    </label>
                                    <label class="field">
                                        <span>Correo</span>
                                        <input id="admin-access-user-email" type="email" autocomplete="email" placeholder="usuario@empresa.com">
                                    </label>
                                    <label class="field">
                                        <span>Clave inicial</span>
                                        <input id="admin-access-user-password" type="password" autocomplete="new-password" placeholder="Minimo 8 caracteres">
                                    </label>
                                    <label class="field">
                                        <span>Roles iniciales</span>
                                        <select id="admin-access-user-roles" multiple size="5"></select>
                                    </label>
                                    <button class="primary-button" type="button" id="admin-access-create-user">Crear usuario</button>
                                </aside>

                                <section class="access-panel">
                                    <div class="panel-heading">
                                        <div>
                                            <h4 id="admin-access-selected-user-title">Usuario seleccionado</h4>
                                            <p>Selecciona un usuario para cambiar sus roles o estado.</p>
                                        </div>
                                    </div>
                                    <label class="field">
                                        <span>Roles asignados</span>
                                        <select id="admin-access-selected-user-roles" multiple size="7"></select>
                                    </label>
                                    <div class="access-actions">
                                        <button class="primary-button" type="button" id="admin-access-save-user-roles">Guardar roles</button>
                                        <button class="ghost-button" type="button" id="admin-access-toggle-user-status">Activar / inactivar</button>
                                    </div>
                                </section>

                                <section class="access-panel access-panel--wide">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Roles</h4>
                                            <p>Los roles base estan protegidos. Puedes crear roles operativos nuevos.</p>
                                        </div>
                                    </div>

                                    <div class="roles-layout">
                                        <div class="admin-table-wrap admin-table-wrap--compact">
                                            <table class="admin-data-table admin-data-table--compact">
                                                <thead>
                                                    <tr>
                                                        <th>Rol</th>
                                                        <th>Permisos</th>
                                                        <th>Tipo</th>
                                                        <th>Accion</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="admin-access-roles-table"></tbody>
                                            </table>
                                        </div>

                                        <div class="role-create-box">
                                            <label class="field">
                                                <span>Nuevo rol</span>
                                                <input id="admin-access-role-name" type="text" placeholder="Ej. Supervisor tienda">
                                            </label>
                                            <button class="primary-button" type="button" id="admin-access-create-role">Crear rol</button>
                                        </div>
                                    </div>
                                </section>

                                <section class="access-panel access-panel--wide">
                                    <div class="panel-heading">
                                        <div>
                                            <h4 id="admin-access-selected-role-title">Permisos del rol</h4>
                                            <p>Selecciona un rol y marca lo que puede ver o ejecutar.</p>
                                        </div>
                                        <button class="primary-button primary-button--fit" type="button" id="admin-access-save-role-permissions">Guardar permisos</button>
                                    </div>
                                    <div class="permission-grid" id="admin-access-permissions-grid"></div>
                                </section>
                            </div>

                            <p class="dashboard-status" id="admin-access-status" role="status" aria-live="polite"></p>
                        </section>

                        <section class="module-placeholder" id="module-placeholder" hidden>
                            <span class="soft-badge">Herramienta en preparación</span>
                            <h3 id="module-placeholder-title">Módulo</h3>
                            <p id="module-placeholder-copy">Esta sección se conectará a sus APIs específicas en la siguiente fase.</p>
                        </section>

                        <p class="dashboard-status" id="dashboard-status" role="status" aria-live="polite"></p>
                    </section>
                </main>
            </section>
        </div>
    </body>
</html>
