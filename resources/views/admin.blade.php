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
                        <label class="tenant-switcher" id="admin-tenant-switcher-field" hidden>
                            <span>Empresa activa</span>
                            <select id="admin-tenant-switcher" aria-label="Cambiar empresa activa"></select>
                        </label>
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
                        <button class="portal-nav__item" type="button" data-portal-section="reports">
                            <span>Reportes</span>
                            <small>Operacion</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="inventory">
                            <span>Inventario</span>
                            <small>Stock y productos</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="movements">
                            <span>Movimientos</span>
                            <small>Entradas y salidas</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="purchases">
                            <span>Compras</span>
                            <small>Recepciones</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="receivables">
                            <span>CxC</span>
                            <small>Cobros cliente</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="payables">
                            <span>CxP</span>
                            <small>Pagos proveedor</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="suppliers">
                            <span>Proveedores</span>
                            <small>Compras y cuentas</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="cash">
                            <span>Caja</span>
                            <small>Turnos y cierres</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="users">
                            <span>Usuarios</span>
                            <small>Perfiles y permisos</small>
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

                        <section class="admin-module-panel reports-admin" id="admin-reports-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Reportes</span>
                                    <h3>Reportes operativos</h3>
                                    <p>Ventas POS, caja, metodos de pago y productos mas vendidos de la empresa activa.</p>
                                </div>
                                <div class="module-head__actions">
                                    <button class="ghost-button" type="button" id="admin-reports-refresh">Actualizar reportes</button>
                                </div>
                            </div>

                            <div class="reports-admin__filters" aria-label="Filtros de reportes operativos">
                                <label>
                                    Desde
                                    <input type="date" id="admin-reports-date-from">
                                </label>
                                <label>
                                    Hasta
                                    <input type="date" id="admin-reports-date-to">
                                </label>
                                <label>
                                    Sucursal
                                    <select id="admin-reports-branch">
                                        <option value="">Todas</option>
                                    </select>
                                </label>
                                <label>
                                    Caja
                                    <select id="admin-reports-cash-register">
                                        <option value="">Todas</option>
                                    </select>
                                </label>
                                <label>
                                    Cajero
                                    <select id="admin-reports-cashier">
                                        <option value="">Todos</option>
                                    </select>
                                </label>
                                <label>
                                    Estado POS
                                    <select id="admin-reports-order-status">
                                        <option value="all">Todos</option>
                                        <option value="paid">Pagadas</option>
                                        <option value="open">Pendientes</option>
                                        <option value="cancelled">Canceladas</option>
                                    </select>
                                </label>
                                <button class="ghost-button ghost-button--compact" type="button" id="admin-reports-clear-filters">Limpiar</button>
                            </div>

                            <div class="reports-admin__summary" aria-label="Resumen operativo">
                                <article>
                                    <span>Ventas POS</span>
                                    <strong id="admin-reports-pos-total">USD 0.00</strong>
                                    <small id="admin-reports-pos-count">0 ventas</small>
                                </article>
                                <article>
                                    <span>Ticket promedio</span>
                                    <strong id="admin-reports-ticket">USD 0.00</strong>
                                    <small>Promedio por venta POS</small>
                                </article>
                                <article>
                                    <span>Pendientes POS</span>
                                    <strong id="admin-reports-pending">0</strong>
                                    <small id="admin-reports-pending-total">USD 0.00 por cerrar</small>
                                </article>
                                <article>
                                    <span>Cajas abiertas</span>
                                    <strong id="admin-reports-open-cash">0</strong>
                                    <small id="admin-reports-cash-expected">USD 0.00 esperado</small>
                                </article>
                            </div>

                            <div class="reports-admin__grid">
                                <section class="content-panel reports-admin__panel reports-admin__panel--wide">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Ventas recientes</h4>
                                            <p id="admin-reports-period">Periodo actual.</p>
                                        </div>
                                        <button class="ghost-button ghost-button--compact" type="button" id="admin-reports-export-orders">CSV</button>
                                    </div>
                                    <div class="admin-table-wrap admin-table-wrap--compact reports-admin__table">
                                        <table class="admin-data-table admin-data-table--compact">
                                            <thead>
                                                <tr>
                                                    <th>Orden</th>
                                                    <th>Cliente</th>
                                                    <th>Caja</th>
                                                    <th>Estado</th>
                                                    <th>Total</th>
                                                    <th>Pagado</th>
                                                    <th>Fecha</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-reports-orders-table"></tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="content-panel reports-admin__panel">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Metodos de pago</h4>
                                            <p>Pagos capturados por moneda y metodo.</p>
                                        </div>
                                        <button class="ghost-button ghost-button--compact" type="button" id="admin-reports-export-payments">CSV</button>
                                    </div>
                                    <div class="admin-table-wrap admin-table-wrap--compact reports-admin__table reports-admin__table--short">
                                        <table class="admin-data-table admin-data-table--compact">
                                            <thead>
                                                <tr>
                                                    <th>Metodo</th>
                                                    <th>Pagos</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-reports-payments-table"></tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="content-panel reports-admin__panel">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Productos vendidos</h4>
                                            <p>Ranking por monto facturado.</p>
                                        </div>
                                        <button class="ghost-button ghost-button--compact" type="button" id="admin-reports-export-products">CSV</button>
                                    </div>
                                    <div class="admin-table-wrap admin-table-wrap--compact reports-admin__table reports-admin__table--short">
                                        <table class="admin-data-table admin-data-table--compact">
                                            <thead>
                                                <tr>
                                                    <th>Producto</th>
                                                    <th>Cant.</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-reports-products-table"></tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="content-panel reports-admin__panel reports-admin__panel--wide">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Actividad de caja</h4>
                                            <p>Turnos abiertos o cerrados dentro del periodo.</p>
                                        </div>
                                        <button class="ghost-button ghost-button--compact" type="button" id="admin-reports-export-cash">CSV</button>
                                    </div>
                                    <div class="admin-table-wrap admin-table-wrap--compact reports-admin__table">
                                        <table class="admin-data-table admin-data-table--compact">
                                            <thead>
                                                <tr>
                                                    <th>Caja</th>
                                                    <th>Sucursal</th>
                                                    <th>Cajero</th>
                                                    <th>Estado</th>
                                                    <th>Esperado</th>
                                                    <th>Diferencia</th>
                                                    <th>Apertura</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-reports-cash-table"></tbody>
                                        </table>
                                    </div>
                                </section>
                            </div>

                            <p class="dashboard-status" id="admin-reports-status" role="status" aria-live="polite"></p>
                        </section>

                        <section class="admin-module-panel inventory-admin" id="admin-inventory-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Inventario</span>
                                    <h3>Productos y precios</h3>
                                    <p>Consulta stock, filtra productos y actualiza precios base para sincronizarlos con las sedes.</p>
                                </div>
                                <div class="module-head__actions">
                                    <button class="primary-button primary-button--fit" type="button" id="admin-inventory-new">Nuevo producto</button>
                                    <button class="ghost-button" type="button" id="admin-inventory-refresh">Actualizar inventario</button>
                                </div>
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
                                        <label class="field">
                                            <span>Estado</span>
                                            <select id="admin-inventory-active">
                                                <option value="all">Todos</option>
                                                <option value="active">Activos</option>
                                                <option value="inactive">Inactivos</option>
                                            </select>
                                        </label>
                                        <button class="primary-button" type="button" id="admin-inventory-apply">Aplicar</button>
                                    </div>

                                    <div class="inventory-admin__quickbar" aria-label="Filtros rapidos de inventario">
                                        <div class="inventory-admin__quickfilters" id="admin-inventory-quick-status">
                                            <button class="filter-chip is-active" type="button" data-inventory-active-filter="all">Todos</button>
                                            <button class="filter-chip" type="button" data-inventory-active-filter="active">Activos</button>
                                            <button class="filter-chip" type="button" data-inventory-active-filter="inactive">Inactivos</button>
                                        </div>
                                        <span class="inventory-admin__filter-summary" id="admin-inventory-filter-summary">Vista completa del catalogo.</span>
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
                                                    <th>Venta</th>
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
                                        <span>Nombre</span>
                                        <input id="admin-inventory-name" type="text" maxlength="255" placeholder="Nombre comercial">
                                    </label>

                                    <label class="field">
                                        <span>SKU</span>
                                        <input id="admin-inventory-sku" type="text" maxlength="255" placeholder="SKU único por empresa">
                                    </label>

                                    <label class="field">
                                        <span>Tipo de control</span>
                                        <select id="admin-inventory-tracking-edit">
                                            <option value="quantity">Por cantidad</option>
                                            <option value="serialized">Serializado / IMEI</option>
                                        </select>
                                    </label>

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

                                    <label class="field">
                                        <span>Tasa de venta</span>
                                        <select id="admin-inventory-rate-type">
                                            <option value="">Sin tasa asignada</option>
                                        </select>
                                    </label>

                                    <label class="field">
                                        <span>Garantia</span>
                                        <select id="admin-inventory-warranty">
                                            <option value="">Sin política asignada</option>
                                        </select>
                                    </label>

                                    <label class="field">
                                        <span>Estado comercial</span>
                                        <select id="admin-inventory-active-edit">
                                            <option value="1">Activo para venta</option>
                                            <option value="0">Inactivo</option>
                                        </select>
                                    </label>

                                    <div class="inventory-editor__actions">
                                        <button class="primary-button" type="button" id="admin-inventory-save">Guardar producto</button>
                                        <button class="danger-button" type="button" id="admin-inventory-deactivate">Desactivar</button>
                                        <button class="ghost-button" type="button" id="admin-inventory-cancel">Cancelar</button>
                                    </div>

                                    <section class="price-list-editor" aria-labelledby="admin-price-list-title">
                                        <div class="price-list-editor__head">
                                            <div>
                                                <span class="soft-badge">Listas</span>
                                                <h5 id="admin-price-list-title">Precios por lista</h5>
                                                <p>Completa precios faltantes para POS y sincronización.</p>
                                            </div>
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-price-copy-base">
                                                Copiar base
                                            </button>
                                        </div>

                                        <div class="price-list-rows" id="admin-price-list-rows">
                                            <p class="price-list-empty">Selecciona un producto para cargar sus listas.</p>
                                        </div>

                                        <button class="primary-button" type="button" id="admin-price-list-save">
                                            Guardar listas de precio
                                        </button>
                                    </section>
                                </aside>

                                <aside class="inventory-detail" id="admin-inventory-detail" hidden>
                                    <div class="inventory-detail__head">
                                        <div>
                                            <span class="soft-badge">Detalle operativo</span>
                                            <h4 id="admin-inventory-detail-title">Producto</h4>
                                            <p id="admin-inventory-detail-subtitle">Selecciona un producto para revisar stock, listas y actividad.</p>
                                        </div>
                                        <button class="ghost-button ghost-button--compact" type="button" id="admin-inventory-detail-close">Cerrar</button>
                                    </div>

                                    <div class="inventory-detail__actions">
                                        <button class="primary-button" type="button" id="admin-inventory-detail-edit">Editar</button>
                                        <button class="ghost-button" type="button" id="admin-inventory-detail-toggle">Cambiar estado</button>
                                    </div>

                                    <div class="inventory-detail__meta" id="admin-inventory-detail-meta"></div>

                                    <section class="inventory-detail__section">
                                        <div class="inventory-detail__section-head">
                                            <h5>Stock por almacen</h5>
                                            <span id="admin-inventory-detail-stock-total">0 disp.</span>
                                        </div>
                                        <div class="inventory-detail__list" id="admin-inventory-detail-stock">
                                            <p class="inventory-detail__empty">Sin stock cargado.</p>
                                        </div>
                                    </section>

                                    <section class="inventory-detail__section">
                                        <div class="inventory-detail__section-head">
                                            <h5>Precios por lista</h5>
                                            <span id="admin-inventory-detail-price-count">0</span>
                                        </div>
                                        <div class="inventory-detail__list" id="admin-inventory-detail-prices">
                                            <p class="inventory-detail__empty">Sin precios por lista.</p>
                                        </div>
                                    </section>

                                    <section class="inventory-detail__section">
                                        <div class="inventory-detail__section-head">
                                            <h5>Actividad reciente</h5>
                                            <span id="admin-inventory-detail-change-count">0</span>
                                        </div>
                                        <div class="inventory-detail__list" id="admin-inventory-detail-changes">
                                            <p class="inventory-detail__empty">Sin movimientos recientes.</p>
                                        </div>
                                    </section>
                                </aside>
                            </div>

                            <p class="dashboard-status" id="admin-inventory-status" role="status" aria-live="polite"></p>
                        </section>

                        <section class="admin-module-panel movements-admin" id="admin-movements-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Inventario</span>
                                    <h3>Historial de movimientos</h3>
                                    <p>Consulta entradas, salidas, ventas, devoluciones, ajustes y traslados por empresa.</p>
                                </div>
                                <button class="ghost-button" type="button" id="admin-movements-refresh">Actualizar movimientos</button>
                            </div>

                            <div class="movements-admin__filters" role="search">
                                <label class="field">
                                    <span>Buscar</span>
                                    <input id="admin-movements-search" type="search" placeholder="Producto, SKU, motivo o referencia">
                                </label>
                                <label class="field">
                                    <span>Tipo</span>
                                    <select id="admin-movements-type">
                                        <option value="all">Todos</option>
                                        <option value="purchase">Entrada compra</option>
                                        <option value="purchase_return">Dev. proveedor</option>
                                        <option value="sale">Venta</option>
                                        <option value="sale_return">Dev. venta</option>
                                        <option value="adjustment_in">Ajuste entrada</option>
                                        <option value="adjustment_out">Ajuste salida</option>
                                        <option value="transfer_in">Traslado entrada</option>
                                        <option value="transfer_out">Traslado salida</option>
                                        <option value="return_in">Retorno entrada</option>
                                        <option value="return_out">Retorno salida</option>
                                        <option value="damaged">Danado</option>
                                        <option value="reserved">Reservado</option>
                                        <option value="released">Liberado</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Almacen</span>
                                    <select id="admin-movements-warehouse">
                                        <option value="">Todos</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Desde</span>
                                    <input id="admin-movements-from" type="date">
                                </label>
                                <label class="field">
                                    <span>Hasta</span>
                                    <input id="admin-movements-to" type="date">
                                </label>
                                <button class="primary-button primary-button--fit" type="button" id="admin-movements-apply">Aplicar</button>
                                <button class="ghost-button ghost-button--compact" type="button" id="admin-movements-clear">Limpiar</button>
                            </div>

                            <div class="admin-table-wrap movements-admin__table">
                                <table class="admin-data-table admin-data-table--compact">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Producto</th>
                                            <th>Tipo</th>
                                            <th>Cant.</th>
                                            <th>Almacen</th>
                                            <th>Motivo / referencia</th>
                                            <th>Usuario</th>
                                        </tr>
                                    </thead>
                                    <tbody id="admin-movements-table"></tbody>
                                </table>
                            </div>

                            <div class="inventory-admin__quickbar">
                                <span class="inventory-admin__filter-summary" id="admin-movements-count">Sin movimientos cargados.</span>
                                <div class="module-head__actions">
                                    <button class="ghost-button ghost-button--compact" type="button" id="admin-movements-prev">Anterior</button>
                                    <button class="ghost-button ghost-button--compact" type="button" id="admin-movements-next">Siguiente</button>
                                </div>
                            </div>

                            <p class="dashboard-status" id="admin-movements-status" role="status" aria-live="polite"></p>
                        </section>

                        <section class="admin-module-panel purchases-admin" id="admin-purchases-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Compras</span>
                                    <h3>Ordenes de compra</h3>
                                    <p>Registra compras de proveedor, revisa pendientes y recibe mercancia para alimentar inventario.</p>
                                </div>
                                <div class="module-head__actions">
                                    <button class="primary-button primary-button--fit" type="button" id="admin-purchase-new">Nueva compra</button>
                                    <button class="ghost-button" type="button" id="admin-purchases-refresh">Actualizar compras</button>
                                </div>
                            </div>

                            <div class="purchases-admin__layout">
                                <div class="purchases-admin__main">
                                    <div class="purchases-admin__filters" role="search">
                                        <label class="field">
                                            <span>Buscar</span>
                                            <input id="admin-purchases-search" type="search" placeholder="Factura, proveedor o documento">
                                        </label>
                                        <label class="field">
                                            <span>Estado</span>
                                            <select id="admin-purchases-status-filter">
                                                <option value="all">Todos</option>
                                                <option value="draft">Pendiente</option>
                                                <option value="partially_received">Parcial</option>
                                                <option value="received">Recibida</option>
                                                <option value="cancelled">Anulada</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>Proveedor</span>
                                            <select id="admin-purchases-supplier-filter">
                                                <option value="">Todos</option>
                                            </select>
                                        </label>
                                        <button class="primary-button primary-button--fit" type="button" id="admin-purchases-apply">Aplicar</button>
                                        <button class="ghost-button ghost-button--compact" type="button" id="admin-purchases-clear">Limpiar</button>
                                    </div>

                                    <div class="admin-table-wrap purchases-admin__table">
                                        <table class="admin-data-table admin-data-table--compact">
                                            <thead>
                                                <tr>
                                                    <th>Compra</th>
                                                    <th>Proveedor</th>
                                                    <th>Estado</th>
                                                    <th>Total</th>
                                                    <th>Recibido</th>
                                                    <th>Items</th>
                                                    <th>Accion</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-purchases-table"></tbody>
                                        </table>
                                    </div>

                                    <div class="table-footer">
                                        <span id="admin-purchases-count">Sin compras cargadas.</span>
                                        <div class="table-footer__actions">
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-purchases-prev">Anterior</button>
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-purchases-next">Siguiente</button>
                                        </div>
                                    </div>
                                </div>

                                <aside class="purchase-editor" id="admin-purchase-editor">
                                    <span class="soft-badge">Registro rapido</span>
                                    <h4 id="admin-purchase-editor-title">Nueva compra</h4>
                                    <p id="admin-purchase-editor-subtitle">Agrega proveedor, documento e items. Recibir mueve stock al inventario.</p>

                                    <div class="purchase-editor__grid purchase-editor__grid--two">
                                        <label class="field">
                                            <span>Proveedor</span>
                                            <select id="admin-purchase-supplier">
                                                <option value="">Sin proveedor</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>Documento</span>
                                            <input id="admin-purchase-document" type="text" maxlength="80" placeholder="FAC-0001">
                                        </label>
                                    </div>

                                    <div class="purchase-editor__grid purchase-editor__grid--three">
                                        <label class="field">
                                            <span>Emision</span>
                                            <input id="admin-purchase-issued-at" type="date">
                                        </label>
                                        <label class="field">
                                            <span>Vence</span>
                                            <input id="admin-purchase-due-date" type="date">
                                        </label>
                                        <label class="field">
                                            <span>Moneda</span>
                                            <select id="admin-purchase-currency">
                                                <option value="USD">USD</option>
                                                <option value="VES">VES</option>
                                            </select>
                                        </label>
                                    </div>

                                    <section class="purchase-items-editor" aria-label="Items de compra">
                                        <div class="purchase-items-editor__head">
                                            <strong>Items</strong>
                                            <small id="admin-purchase-items-total">0 items</small>
                                        </div>

                                        <div class="purchase-item-form">
                                            <label class="field">
                                                <span>Producto</span>
                                                <select id="admin-purchase-product"></select>
                                            </label>
                                            <label class="field">
                                                <span>Almacen</span>
                                                <select id="admin-purchase-warehouse"></select>
                                            </label>
                                            <label class="field">
                                                <span>Cant.</span>
                                                <input id="admin-purchase-quantity" type="number" min="0.0001" step="0.0001" value="1">
                                            </label>
                                            <label class="field">
                                                <span>Costo</span>
                                                <input id="admin-purchase-unit-cost" type="number" min="0" step="0.01" value="0">
                                            </label>
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-purchase-add-item">Agregar item</button>
                                        </div>

                                        <div class="admin-table-wrap admin-table-wrap--compact purchase-items-editor__table">
                                            <table class="admin-data-table admin-data-table--compact">
                                                <thead>
                                                    <tr>
                                                        <th>Producto</th>
                                                        <th>Almacen</th>
                                                        <th>Cant.</th>
                                                        <th>Costo</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody id="admin-purchase-items-table"></tbody>
                                            </table>
                                        </div>
                                    </section>

                                    <div class="purchase-editor__summary" id="admin-purchase-summary">
                                        <span>Total estimado</span>
                                        <strong>USD 0.00</strong>
                                    </div>

                                    <div class="purchase-editor__actions">
                                        <button class="primary-button" type="button" id="admin-purchase-save">Guardar compra</button>
                                        <button class="ghost-button" type="button" id="admin-purchase-receive">Recibir</button>
                                        <button class="danger-button" type="button" id="admin-purchase-cancel-order">Anular</button>
                                        <button class="ghost-button" type="button" id="admin-purchase-clear">Limpiar</button>
                                    </div>
                                </aside>
                            </div>

                            <p class="dashboard-status" id="admin-purchases-status" role="status" aria-live="polite"></p>
                        </section>

                        <section class="admin-module-panel receivables-admin" id="admin-receivables-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Finanzas</span>
                                    <h3>Cuentas por cobrar</h3>
                                    <p>Consulta saldos de clientes, vencimientos y registra cobros parciales o totales.</p>
                                </div>
                                <div class="module-head__actions">
                                    <button class="ghost-button" type="button" id="admin-receivables-refresh">Actualizar cuentas</button>
                                </div>
                            </div>

                            <div class="receivables-admin__layout">
                                <div class="receivables-admin__main">
                                    <div class="receivables-admin__filters" role="search">
                                        <label class="field">
                                            <span>Buscar</span>
                                            <input id="admin-receivables-search" type="search" placeholder="Documento o cliente">
                                        </label>
                                        <label class="field">
                                            <span>Estado</span>
                                            <select id="admin-receivables-status-filter">
                                                <option value="all">Todos</option>
                                                <option value="pending">Pendiente</option>
                                                <option value="partial">Parcial</option>
                                                <option value="overdue">Vencida</option>
                                                <option value="paid">Cobrada</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>Cliente</span>
                                            <select id="admin-receivables-customer-filter">
                                                <option value="">Todos</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>Desde</span>
                                            <input id="admin-receivables-due-from" type="date">
                                        </label>
                                        <label class="field">
                                            <span>Hasta</span>
                                            <input id="admin-receivables-due-to" type="date">
                                        </label>
                                        <button class="primary-button primary-button--fit" type="button" id="admin-receivables-apply">Aplicar</button>
                                        <button class="ghost-button ghost-button--compact" type="button" id="admin-receivables-clear">Limpiar</button>
                                    </div>

                                    <div class="admin-table-wrap receivables-admin__table">
                                        <table class="admin-data-table admin-data-table--compact">
                                            <thead>
                                                <tr>
                                                    <th>Cuenta</th>
                                                    <th>Cliente</th>
                                                    <th>Estado</th>
                                                    <th>Total</th>
                                                    <th>Cobrado</th>
                                                    <th>Saldo</th>
                                                    <th>Accion</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-receivables-table"></tbody>
                                        </table>
                                    </div>

                                    <div class="table-footer">
                                        <span id="admin-receivables-count">Sin cuentas cargadas.</span>
                                        <div class="table-footer__actions">
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-receivables-prev">Anterior</button>
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-receivables-next">Siguiente</button>
                                        </div>
                                    </div>
                                </div>

                                <aside class="receivable-editor" id="admin-receivable-editor">
                                    <span class="soft-badge">Cobro a cliente</span>
                                    <h4 id="admin-receivable-title">Selecciona una cuenta</h4>
                                    <p id="admin-receivable-subtitle">El cobro se registra contra la cuenta seleccionada.</p>

                                    <div class="receivable-summary" id="admin-receivable-summary">
                                        <div><span>Total</span><strong>USD 0.00</strong></div>
                                        <div><span>Cobrado</span><strong>USD 0.00</strong></div>
                                        <div><span>Saldo</span><strong>USD 0.00</strong></div>
                                    </div>

                                    <section class="receivable-payments">
                                        <div class="purchase-items-editor__head">
                                            <strong>Cobros registrados</strong>
                                            <small id="admin-receivable-payments-count">0 cobros</small>
                                        </div>
                                        <div class="admin-table-wrap admin-table-wrap--compact receivable-payments__table">
                                            <table class="admin-data-table admin-data-table--compact">
                                                <thead>
                                                    <tr>
                                                        <th>Fecha</th>
                                                        <th>Monto</th>
                                                        <th>Metodo</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="admin-receivable-payments-table"></tbody>
                                            </table>
                                        </div>
                                    </section>

                                    <section class="receivable-payment-form" aria-label="Registrar cobro">
                                        <div class="purchase-editor__grid purchase-editor__grid--two">
                                            <label class="field">
                                                <span>Moneda</span>
                                                <select id="admin-receivable-payment-currency">
                                                    <option value="USD">USD</option>
                                                    <option value="VES">VES</option>
                                                </select>
                                            </label>
                                            <label class="field">
                                                <span>Monto</span>
                                                <input id="admin-receivable-payment-amount" type="number" min="0.01" step="0.01" placeholder="0.00">
                                            </label>
                                        </div>

                                        <div class="purchase-editor__grid purchase-editor__grid--two">
                                            <label class="field">
                                                <span>Metodo</span>
                                                <input id="admin-receivable-payment-method" type="text" maxlength="100" placeholder="Transferencia, efectivo...">
                                            </label>
                                            <label class="field">
                                                <span>Referencia</span>
                                                <input id="admin-receivable-payment-reference" type="text" maxlength="150" placeholder="Operacion bancaria">
                                            </label>
                                        </div>

                                        <label class="field">
                                            <span>Notas</span>
                                            <textarea id="admin-receivable-payment-notes" rows="2" maxlength="1000" placeholder="Observacion opcional"></textarea>
                                        </label>

                                        <div class="purchase-editor__actions">
                                            <button class="primary-button" type="button" id="admin-receivable-collect">Registrar cobro</button>
                                            <button class="ghost-button" type="button" id="admin-receivable-fill-balance">Usar saldo</button>
                                        </div>
                                    </section>
                                </aside>
                            </div>

                            <p class="dashboard-status" id="admin-receivables-status" role="status" aria-live="polite"></p>
                        </section>

                        <section class="admin-module-panel payables-admin" id="admin-payables-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Finanzas</span>
                                    <h3>Cuentas por pagar</h3>
                                    <p>Consulta saldos de proveedores, vencimientos y registra pagos parciales o totales.</p>
                                </div>
                                <div class="module-head__actions">
                                    <button class="ghost-button" type="button" id="admin-payables-refresh">Actualizar cuentas</button>
                                </div>
                            </div>

                            <div class="payables-admin__layout">
                                <div class="payables-admin__main">
                                    <div class="payables-admin__filters" role="search">
                                        <label class="field">
                                            <span>Buscar</span>
                                            <input id="admin-payables-search" type="search" placeholder="Documento o proveedor">
                                        </label>
                                        <label class="field">
                                            <span>Estado</span>
                                            <select id="admin-payables-status-filter">
                                                <option value="all">Todos</option>
                                                <option value="pending">Pendiente</option>
                                                <option value="partial">Parcial</option>
                                                <option value="overdue">Vencida</option>
                                                <option value="paid">Pagada</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>Proveedor</span>
                                            <select id="admin-payables-supplier-filter">
                                                <option value="">Todos</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>Desde</span>
                                            <input id="admin-payables-due-from" type="date">
                                        </label>
                                        <label class="field">
                                            <span>Hasta</span>
                                            <input id="admin-payables-due-to" type="date">
                                        </label>
                                        <button class="primary-button primary-button--fit" type="button" id="admin-payables-apply">Aplicar</button>
                                        <button class="ghost-button ghost-button--compact" type="button" id="admin-payables-clear">Limpiar</button>
                                    </div>

                                    <div class="admin-table-wrap payables-admin__table">
                                        <table class="admin-data-table admin-data-table--compact">
                                            <thead>
                                                <tr>
                                                    <th>Cuenta</th>
                                                    <th>Proveedor</th>
                                                    <th>Estado</th>
                                                    <th>Total</th>
                                                    <th>Pagado</th>
                                                    <th>Saldo</th>
                                                    <th>Accion</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-payables-table"></tbody>
                                        </table>
                                    </div>

                                    <div class="table-footer">
                                        <span id="admin-payables-count">Sin cuentas cargadas.</span>
                                        <div class="table-footer__actions">
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-payables-prev">Anterior</button>
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-payables-next">Siguiente</button>
                                        </div>
                                    </div>
                                </div>

                                <aside class="payable-editor" id="admin-payable-editor">
                                    <span class="soft-badge">Pago a proveedor</span>
                                    <h4 id="admin-payable-title">Selecciona una cuenta</h4>
                                    <p id="admin-payable-subtitle">El pago se registra contra la cuenta seleccionada.</p>

                                    <div class="payable-summary" id="admin-payable-summary">
                                        <div><span>Total</span><strong>USD 0.00</strong></div>
                                        <div><span>Pagado</span><strong>USD 0.00</strong></div>
                                        <div><span>Saldo</span><strong>USD 0.00</strong></div>
                                    </div>

                                    <section class="payable-payments">
                                        <div class="purchase-items-editor__head">
                                            <strong>Pagos registrados</strong>
                                            <small id="admin-payable-payments-count">0 pagos</small>
                                        </div>
                                        <div class="admin-table-wrap admin-table-wrap--compact payable-payments__table">
                                            <table class="admin-data-table admin-data-table--compact">
                                                <thead>
                                                    <tr>
                                                        <th>Fecha</th>
                                                        <th>Monto</th>
                                                        <th>Metodo</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="admin-payable-payments-table"></tbody>
                                            </table>
                                        </div>
                                    </section>

                                    <section class="payable-payment-form" aria-label="Registrar pago">
                                        <div class="purchase-editor__grid purchase-editor__grid--two">
                                            <label class="field">
                                                <span>Moneda</span>
                                                <select id="admin-payable-payment-currency">
                                                    <option value="USD">USD</option>
                                                    <option value="VES">VES</option>
                                                </select>
                                            </label>
                                            <label class="field">
                                                <span>Monto</span>
                                                <input id="admin-payable-payment-amount" type="number" min="0.01" step="0.01" placeholder="0.00">
                                            </label>
                                        </div>

                                        <div class="purchase-editor__grid purchase-editor__grid--two">
                                            <label class="field">
                                                <span>Metodo</span>
                                                <input id="admin-payable-payment-method" type="text" maxlength="100" placeholder="Transferencia, efectivo...">
                                            </label>
                                            <label class="field">
                                                <span>Referencia</span>
                                                <input id="admin-payable-payment-reference" type="text" maxlength="150" placeholder="Operacion bancaria">
                                            </label>
                                        </div>

                                        <label class="field">
                                            <span>Notas</span>
                                            <textarea id="admin-payable-payment-notes" rows="2" maxlength="1000" placeholder="Observacion opcional"></textarea>
                                        </label>

                                        <div class="purchase-editor__actions">
                                            <button class="primary-button" type="button" id="admin-payable-pay">Registrar pago</button>
                                            <button class="ghost-button" type="button" id="admin-payable-fill-balance">Usar saldo</button>
                                        </div>
                                    </section>
                                </aside>
                            </div>

                            <p class="dashboard-status" id="admin-payables-status" role="status" aria-live="polite"></p>
                        </section>

                        <section class="admin-module-panel suppliers-admin" id="admin-suppliers-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Compras</span>
                                    <h3>Proveedores</h3>
                                    <p>Gestiona proveedores por empresa para compras, cuentas por pagar y reportes financieros.</p>
                                </div>
                                <div class="module-head__actions">
                                    <button class="primary-button primary-button--fit" type="button" id="admin-supplier-new">Nuevo proveedor</button>
                                    <button class="ghost-button" type="button" id="admin-suppliers-refresh">Actualizar proveedores</button>
                                </div>
                            </div>

                            <div class="suppliers-admin__layout">
                                <div class="suppliers-admin__main">
                                    <div class="suppliers-admin__filters" role="search">
                                        <label class="field">
                                            <span>Buscar</span>
                                            <input id="admin-suppliers-search" type="search" placeholder="Nombre, RIF, correo o teléfono">
                                        </label>
                                        <label class="field">
                                            <span>Estado</span>
                                            <select id="admin-suppliers-active">
                                                <option value="all">Todos</option>
                                                <option value="active">Activos</option>
                                                <option value="inactive">Inactivos</option>
                                            </select>
                                        </label>
                                        <button class="primary-button primary-button--fit" type="button" id="admin-suppliers-apply">Aplicar</button>
                                        <button class="ghost-button ghost-button--compact" type="button" id="admin-suppliers-clear">Limpiar</button>
                                    </div>

                                    <div class="admin-table-wrap suppliers-admin__table">
                                        <table class="admin-data-table admin-data-table--compact">
                                            <thead>
                                                <tr>
                                                    <th>Proveedor</th>
                                                    <th>Documento</th>
                                                    <th>Contacto</th>
                                                    <th>Estado</th>
                                                    <th>Actualizado</th>
                                                    <th>Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-suppliers-table"></tbody>
                                        </table>
                                    </div>

                                    <div class="table-footer">
                                        <span id="admin-suppliers-count">Sin proveedores cargados.</span>
                                        <div class="table-footer__actions">
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-suppliers-prev">Anterior</button>
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-suppliers-next">Siguiente</button>
                                        </div>
                                    </div>
                                </div>

                                <aside class="supplier-editor" id="admin-supplier-editor">
                                    <span class="soft-badge">Edición rápida</span>
                                    <h4 id="admin-supplier-editor-title">Nuevo proveedor</h4>
                                    <p id="admin-supplier-editor-subtitle">Completa los datos principales. El documento es único por empresa.</p>

                                    <label class="field">
                                        <span>Nombre</span>
                                        <input id="admin-supplier-name" type="text" maxlength="255" placeholder="Nombre comercial">
                                    </label>

                                    <div class="supplier-editor__grid">
                                        <label class="field">
                                            <span>Tipo</span>
                                            <select id="admin-supplier-document-type">
                                                <option value="J">J</option>
                                                <option value="V">V</option>
                                                <option value="E">E</option>
                                                <option value="G">G</option>
                                                <option value="P">P</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>Documento</span>
                                            <input id="admin-supplier-document-number" type="text" maxlength="50" placeholder="RIF o cédula">
                                        </label>
                                    </div>

                                    <div class="supplier-editor__grid">
                                        <label class="field">
                                            <span>Teléfono</span>
                                            <input id="admin-supplier-phone" type="text" maxlength="50" placeholder="Teléfono">
                                        </label>
                                        <label class="field">
                                            <span>Correo</span>
                                            <input id="admin-supplier-email" type="email" maxlength="255" placeholder="compras@proveedor.com">
                                        </label>
                                    </div>

                                    <label class="field">
                                        <span>Dirección fiscal</span>
                                        <textarea id="admin-supplier-address" rows="2" maxlength="500" placeholder="Dirección fiscal"></textarea>
                                    </label>

                                    <label class="field">
                                        <span>Notas</span>
                                        <textarea id="admin-supplier-notes" rows="2" maxlength="1000" placeholder="Condiciones, contacto o detalle interno"></textarea>
                                    </label>

                                    <label class="supplier-editor__active">
                                        <input id="admin-supplier-active-edit" type="checkbox" checked>
                                        <span>Proveedor activo</span>
                                    </label>

                                    <div class="supplier-editor__actions">
                                        <button class="primary-button" type="button" id="admin-supplier-save">Guardar proveedor</button>
                                        <button class="danger-button" type="button" id="admin-supplier-deactivate">Desactivar</button>
                                        <button class="ghost-button" type="button" id="admin-supplier-cancel">Limpiar</button>
                                    </div>
                                </aside>
                            </div>

                            <p class="dashboard-status" id="admin-suppliers-status" role="status" aria-live="polite"></p>
                        </section>

                        <section class="admin-module-panel access-admin" id="admin-users-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Accesos</span>
                                    <h3>Usuarios y permisos</h3>
                                    <p>Administra usuarios por empresa, asigna perfiles reutilizables y controla permisos por modulo.</p>
                                </div>
                                <button class="ghost-button" type="button" id="admin-access-refresh">Actualizar accesos</button>
                            </div>

                            <div class="access-tabs" role="tablist" aria-label="Usuarios y permisos">
                                <button class="access-tab is-active" type="button" data-access-tab="users">Usuarios</button>
                                <button class="access-tab" type="button" data-access-tab="profiles">Perfiles</button>
                                <button class="access-tab" type="button" data-access-tab="permissions">Permisos</button>
                            </div>

                            <div class="access-admin__grid is-active" data-access-panel="users">
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
                                                    <th>Perfiles</th>
                                                    <th>Acción</th>
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
                                        <span>Perfiles iniciales</span>
                                        <select id="admin-access-user-roles" multiple size="5"></select>
                                    </label>
                                    <button class="primary-button" type="button" id="admin-access-create-user">Crear usuario</button>
                                </aside>

                                <section class="access-panel">
                                    <div class="panel-heading">
                                        <div>
                                            <h4 id="admin-access-selected-user-title">Usuario seleccionado</h4>
                                            <p>Selecciona un usuario para cambiar sus perfiles o estado.</p>
                                        </div>
                                    </div>
                                    <label class="field">
                                        <span>Perfiles asignados</span>
                                        <select id="admin-access-selected-user-roles" multiple size="7"></select>
                                    </label>
                                    <div class="access-actions">
                                        <button class="primary-button" type="button" id="admin-access-save-user-roles">Guardar perfiles</button>
                                        <button class="ghost-button" type="button" id="admin-access-toggle-user-status">Activar / inactivar</button>
                                    </div>
                                </section>
                            </div>

                            <div class="access-admin__grid access-admin__grid--single" data-access-panel="profiles">
                                <section class="access-panel access-panel--wide">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Perfiles de permisos</h4>
                                            <p>Un perfil agrupa permisos, por ejemplo Cajero, Almacen o Supervisor. Luego se asigna a varios usuarios.</p>
                                        </div>
                                    </div>

                                    <div class="roles-layout">
                                        <div class="admin-table-wrap admin-table-wrap--compact">
                                            <table class="admin-data-table admin-data-table--compact">
                                                <thead>
                                                    <tr>
                                                        <th>Perfil</th>
                                                        <th>Permisos</th>
                                                        <th>Tipo</th>
                                                        <th>Acción</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="admin-access-roles-table"></tbody>
                                            </table>
                                        </div>

                                        <div class="role-create-box">
                                            <label class="field">
                                                <span>Nuevo perfil</span>
                                                <input id="admin-access-role-name" type="text" placeholder="Ej. Cajero Norte">
                                            </label>
                                            <label class="field">
                                                <span>Plantilla de permisos</span>
                                                <select id="admin-access-role-template">
                                                    <option value="">Sin plantilla</option>
                                                    <option value="cashier">Perfil Cajero</option>
                                                    <option value="inventory">Perfil Inventario</option>
                                                    <option value="manager">Perfil Gerente</option>
                                                </select>
                                            </label>
                                            <button class="primary-button" type="button" id="admin-access-create-role">Crear perfil</button>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <div class="access-admin__grid access-admin__grid--single" data-access-panel="permissions">
                                <section class="access-panel access-panel--wide">
                                    <div class="panel-heading">
                                        <div>
                                            <h4 id="admin-access-selected-role-title">Permisos del perfil</h4>
                                            <p>Selecciona un perfil y marca lo que puede ver o ejecutar.</p>
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
