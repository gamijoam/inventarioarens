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

            <section class="admin-shell admin-shell--v2" data-view="dashboard" tabindex="-1" data-collapsed="false">
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

                <aside class="v2-sidebar" aria-label="Menú principal">
                    <div class="v2-sidebar__brand">
                        <div class="v2-sidebar__logo" aria-hidden="true">SI</div>
                        <div class="v2-sidebar__brand-text">
                            <strong>{{ config('app.name', 'Sistema de Inventario') }}</strong>
                            <small>Portal administrativo</small>
                        </div>
                    </div>

                    <nav class="v2-sidebar__nav" id="v2-sidebar-nav">
                        <button class="v2-nav-item is-active" type="button" data-portal-section="overview" title="Resumen">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            <span class="v2-nav-item__text"><strong>Resumen</strong><small>Indicadores</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="sales" title="Ventas">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>
                            <span class="v2-nav-item__text"><strong>Ventas</strong><small>POS y órdenes</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="reports" title="Reportes">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="3" y1="20" x2="21" y2="20"/></svg>
                            <span class="v2-nav-item__text"><strong>Reportes</strong><small>Operación</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="inventory" title="Inventario">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                            <span class="v2-nav-item__text"><strong>Inventario</strong><small>Stock y productos</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="rates" title="Tasas">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
                            <span class="v2-nav-item__text"><strong>Tasas</strong><small>BCV y paralelo</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="movements" title="Movimientos">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                            <span class="v2-nav-item__text"><strong>Movimientos</strong><small>Entradas y salidas</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="transfers" title="Traslados">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                            <span class="v2-nav-item__text"><strong>Traslados</strong><small>Logística interna</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="purchases" title="Compras">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2h-4"/><polyline points="9 11 12 8 15 11"/><line x1="12" y1="2" x2="12" y2="14"/></svg>
                            <span class="v2-nav-item__text"><strong>Compras</strong><small>Recepciones</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="receivables" title="CxC">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <span class="v2-nav-item__text"><strong>CxC</strong><small>Cobros cliente</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="customers" title="Clientes">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <span class="v2-nav-item__text"><strong>Clientes</strong><small>Datos y cartera</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="payables" title="CxP">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                            <span class="v2-nav-item__text"><strong>CxP</strong><small>Pagos proveedor</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="suppliers" title="Proveedores">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            <span class="v2-nav-item__text"><strong>Proveedores</strong><small>Compras y cuentas</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="cash" title="Caja">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><line x1="6" y1="12" x2="6" y2="12.01"/><line x1="18" y1="12" x2="18" y2="12.01"/></svg>
                            <span class="v2-nav-item__text"><strong>Caja</strong><small>Turnos y cierres</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="users" title="Usuarios">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 11l2 2 4-4"/></svg>
                            <span class="v2-nav-item__text"><strong>Usuarios</strong><small>Perfiles y permisos</small></span>
                        </button>
                        <button class="v2-nav-item" type="button" data-portal-section="sync" title="Sincronización">
                            <svg class="v2-nav-item__icon" viewBox="0 0 24 24" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                            <span class="v2-nav-item__text"><strong>Sincronización</strong><small>Nodos y eventos</small></span>
                        </button>
                    </nav>

                    <div class="v2-sidebar__footer">
                        <button class="v2-sidebar__toggle" type="button" id="v2-sidebar-toggle" title="Colapsar menú">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                            <span>Colapsar menú</span>
                        </button>
                    </div>
                </aside>

                <header class="v2-topbar" role="banner">
                    <div class="v2-topbar__search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="search" placeholder="Buscar global — productos, traslados, clientes..." aria-label="Buscar global">
                    </div>
                    <div class="v2-topbar__spacer"></div>
                    <label class="v2-topbar__field" id="v2-tenant-field">
                        <span>Empresa activa</span>
                        <select id="v2-tenant-switcher" aria-label="Cambiar empresa activa"></select>
                    </label>
                    <label class="v2-topbar__field">
                        <span>Periodo</span>
                        <select id="v2-dashboard-period" aria-label="Periodo del dashboard">
                            <option value="today">Hoy</option>
                            <option value="week">Semana</option>
                            <option value="month">Mes</option>
                        </select>
                    </label>
                    <button class="v2-btn v2-btn--primary" type="button" id="v2-dashboard-refresh">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15A9 9 0 1 1 18 5.3L23 10"/></svg>
                        Actualizar
                    </button>
                    <button class="v2-btn v2-btn--danger" type="button" id="v2-admin-logout">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Salir
                    </button>
                </header>

                <main class="v2-main" id="v2-main">
                    <section class="v2-page" id="v2-overview-page" data-portal-section="overview">
                    <div class="v2-page-head">
                        <div>
                            <h1>Resumen ejecutivo</h1>
                            <p id="v2-period-label">Vista gerencial · cargando…</p>
                        </div>
                    </div>

                    <section class="v2-metric-grid" aria-label="Métricas principales">
                        <article class="v2-metric v2-metric--info">
                            <span class="v2-metric__label">Ventas confirmadas</span>
                            <strong class="v2-metric__value" id="v2-metric-sales-total">USD 0.00</strong>
                            <span class="v2-metric__hint" id="v2-metric-sales-count">0 ventas confirmadas</span>
                        </article>
                        <article class="v2-metric v2-metric--success">
                            <span class="v2-metric__label">Disponible</span>
                            <strong class="v2-metric__value" id="v2-metric-stock-available">0</strong>
                            <span class="v2-metric__hint">Unidades vendibles</span>
                        </article>
                        <article class="v2-metric v2-metric--warning">
                            <span class="v2-metric__label">Cajas abiertas</span>
                            <strong class="v2-metric__value" id="v2-metric-open-cash">0</strong>
                            <span class="v2-metric__hint" id="v2-metric-cash-expected">USD 0.00 esperado</span>
                        </article>
                        <article class="v2-metric v2-metric--danger">
                            <span class="v2-metric__label">Pendientes POS</span>
                            <strong class="v2-metric__value" id="v2-metric-pending-pos">0</strong>
                            <span class="v2-metric__hint">Órdenes por cerrar</span>
                        </article>
                    </section>

                    <section class="v2-panel-grid" aria-label="Inventario y sincronización">
                        <article class="v2-panel">
                            <div class="v2-panel__head">
                                <div>
                                    <h2>Inventario</h2>
                                    <p>Productos, stock bajo y disponibilidad.</p>
                                </div>
                            </div>
                            <div class="v2-mini-grid">
                                <div class="v2-mini v2-mini--info">
                                    <span class="v2-mini__label">Productos activos</span>
                                    <span class="v2-mini__value" id="v2-metric-products">0</span>
                                    <div class="v2-mini__spark" data-sparkline="products"></div>
                                </div>
                                <div class="v2-mini v2-mini--warning">
                                    <span class="v2-mini__label">Stock bajo</span>
                                    <span class="v2-mini__value" id="v2-metric-low-stock">0</span>
                                    <div class="v2-mini__spark" data-sparkline="low-stock"></div>
                                </div>
                                <div class="v2-mini v2-mini--danger">
                                    <span class="v2-mini__label">Sin stock</span>
                                    <span class="v2-mini__value" id="v2-metric-without-stock">0</span>
                                    <div class="v2-mini__spark" data-sparkline="without-stock"></div>
                                </div>
                                <div class="v2-mini v2-mini--info">
                                    <span class="v2-mini__label">Reservado</span>
                                    <span class="v2-mini__value" id="v2-metric-reserved">0</span>
                                    <div class="v2-mini__spark" data-sparkline="reserved"></div>
                                </div>
                            </div>
                        </article>

                        <article class="v2-panel">
                            <div class="v2-panel__head">
                                <div>
                                    <h2>Sincronización</h2>
                                    <p>Estado de nodos y eventos pendientes.</p>
                                </div>
                                <span class="v2-status-pill" id="v2-sync-status">Sin datos</span>
                            </div>
                            <div class="v2-mini-grid v2-mini-grid--3">
                                <div class="v2-mini v2-mini--success">
                                    <span class="v2-mini__label">Nodos activos</span>
                                    <span class="v2-mini__value" id="v2-metric-sync-nodes">0</span>
                                    <div class="v2-mini__spark" data-sparkline="nodes"></div>
                                </div>
                                <div class="v2-mini v2-mini--warning">
                                    <span class="v2-mini__label">Pendientes por subir</span>
                                    <span class="v2-mini__value" id="v2-metric-sync-pending">0</span>
                                    <div class="v2-mini__spark" data-sparkline="pending"></div>
                                </div>
                                <div class="v2-mini v2-mini--success">
                                    <span class="v2-mini__label">Errores</span>
                                    <span class="v2-mini__value" id="v2-metric-sync-errors">0</span>
                                    <div class="v2-mini__spark" data-sparkline="errors"></div>
                                </div>
                            </div>
                        </article>
                    </section>

                    <section class="v2-panel" aria-label="Alertas operativas">
                        <div class="v2-panel__head">
                            <div>
                                <h2>Alertas operativas</h2>
                                <p>Prioridades que requieren revisión.</p>
                            </div>
                        </div>
                        <div class="v2-alerts" id="v2-alert-list"></div>
                    </section>

                    <p class="dashboard-status" id="v2-dashboard-status" role="status" aria-live="polite" style="margin-top: 16px;"></p>
                    </section>

                    <section class="v2-page" id="v2-inventory-page" data-portal-section="inventory" hidden>
                        <div class="v2-page-head">
                            <div>
                                <span class="soft-badge">Inventario</span>
                                <h1>Catálogo de productos</h1>
                                <p id="v2-inv-period">Cargando catálogo...</p>
                            </div>
                            <div class="v2-page-head__actions">
                                <button class="v2-btn v2-btn--primary" type="button" id="v2-inv-new">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Nuevo producto
                                </button>
                                <button class="v2-btn v2-btn--ghost" type="button" id="v2-inv-export">
                                    Exportar CSV
                                </button>
                                <button class="v2-btn v2-btn--ghost" type="button" id="v2-inv-refresh">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15A9 9 0 1 1 18 5.3L23 10"/></svg>
                                    Actualizar
                                </button>
                            </div>
                        </div>

                        <section class="v2-metric-grid v2-metric-grid--compact" aria-label="Indicadores de inventario">
                            <article class="v2-metric v2-metric--info v2-metric--compact">
                                <span class="v2-metric__label">Productos activos</span>
                                <strong class="v2-metric__value" id="v2-inv-metric-total">0</strong>
                                <span class="v2-metric__hint" id="v2-inv-metric-total-hint">Cargando...</span>
                            </article>
                            <article class="v2-metric v2-metric--warning v2-metric--compact">
                                <span class="v2-metric__label">Stock bajo</span>
                                <strong class="v2-metric__value" id="v2-inv-metric-low">0</strong>
                                <span class="v2-metric__hint" id="v2-inv-metric-low-hint">Mínimo operativo: 3</span>
                            </article>
                            <article class="v2-metric v2-metric--danger v2-metric--compact">
                                <span class="v2-metric__label">Sin stock</span>
                                <strong class="v2-metric__value" id="v2-inv-metric-out">0</strong>
                                <span class="v2-metric__hint">Disponibilidad crítica</span>
                            </article>
                            <article class="v2-metric v2-metric--success v2-metric--compact">
                                <span class="v2-metric__label">Unidades disponibles</span>
                                <strong class="v2-metric__value" id="v2-inv-metric-available">0</strong>
                                <span class="v2-metric__hint" id="v2-inv-metric-reserved">0 reservadas · 0 dañadas</span>
                            </article>
                        </section>

                        <div class="v2-alert-strip" id="v2-inv-alert-strip" hidden role="status" aria-live="polite">
                            <svg class="v2-alert-strip__icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                            <div class="v2-alert-strip__list" id="v2-inv-alert-strip-list"></div>
                            <button class="v2-alert-strip__close" type="button" id="v2-inv-alert-strip-close" aria-label="Cerrar alertas">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        <article class="v2-panel v2-panel--flush v2-inv-catalog">
                            <div class="v2-catalog__head">
                                <div class="v2-catalog__title-block">
                                    <h2>Catálogo</h2>
                                    <p id="v2-inv-filter-summary">Vista completa del catálogo.</p>
                                </div>
                                <span class="v2-status-pill v2-status-pill--info" id="v2-inv-active-status">Activos</span>
                            </div>

                            <div class="v2-catalog__toolbar" role="search">
                                <label class="v2-field v2-field--search v2-field--search-lg">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    <input id="v2-inv-search" type="search" placeholder="Buscar nombre o SKU..." autocomplete="off">
                                </label>
                                <label class="v2-field v2-field--inline">
                                    <span>Control</span>
                                    <select id="v2-inv-tracking">
                                        <option value="">Todos</option>
                                        <option value="quantity">Cantidad</option>
                                        <option value="serialized">Serializado</option>
                                    </select>
                                </label>
                                <label class="v2-field v2-field--inline">
                                    <span>Stock</span>
                                    <select id="v2-inv-stock">
                                        <option value="all">Todos</option>
                                        <option value="available">Disponible</option>
                                        <option value="low">Stock bajo</option>
                                        <option value="out">Sin stock</option>
                                    </select>
                                </label>
                                <button class="v2-btn v2-btn--primary" type="button" id="v2-inv-apply">Aplicar</button>
                            </div>

                            <div class="v2-chip-row" id="v2-inv-quick-status" role="tablist" aria-label="Filtro rápido de estado comercial">
                                <button class="v2-chip is-active" type="button" data-v2-inv-active-filter="active">Activos</button>
                                <button class="v2-chip" type="button" data-v2-inv-active-filter="inactive">Inactivos</button>
                                <button class="v2-chip" type="button" data-v2-inv-active-filter="all">Todos</button>
                            </div>

                            <div class="admin-table-wrap">
                                <table class="admin-data-table v2-table v2-table--inventory" id="v2-inv-table">
                                    <thead>
                                        <tr>
                                            <th class="v2-col-product">Producto</th>
                                            <th class="v2-col-price">Precio</th>
                                            <th class="v2-col-stock">Disponible</th>
                                            <th class="v2-col-status">Estado</th>
                                            <th class="v2-col-active">Venta</th>
                                            <th class="v2-col-action" aria-label="Acciones"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="v2-inv-tbody"></tbody>
                                </table>
                            </div>

                            <div class="v2-table-footer">
                                <span id="v2-inv-count">Sin productos cargados.</span>
                                <div class="v2-table-footer__actions">
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-inv-prev">Anterior</button>
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-inv-next">Siguiente</button>
                                </div>
                            </div>
                        </article>

                        <p class="dashboard-status" id="v2-inv-status" role="status" aria-live="polite" style="margin-top: 16px;"></p>
                    </section>

                    <div class="v2-sheet" id="v2-inv-sheet" hidden role="dialog" aria-modal="true" aria-labelledby="v2-sheet-title">
                        <div class="v2-sheet__backdrop" id="v2-inv-sheet-backdrop"></div>
                        <div class="v2-sheet__panel" tabindex="-1">
                            <header class="v2-sheet__head">
                                <div>
                                    <span class="soft-badge" id="v2-sheet-badge">Detalle</span>
                                    <h2 id="v2-sheet-title">Producto</h2>
                                    <p id="v2-sheet-subtitle">Cargando...</p>
                                </div>
                                <div class="v2-sheet__head-actions" id="v2-sheet-actions-view">
                                    <button class="v2-btn v2-btn--primary" type="button" id="v2-sheet-edit">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                        Editar
                                    </button>
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-sheet-close" aria-label="Cerrar">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                                <div class="v2-sheet__head-actions" id="v2-sheet-actions-edit" hidden>
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-sheet-cancel">
                                        Cancelar
                                    </button>
                                    <button class="v2-btn v2-btn--primary" type="button" id="v2-sheet-save">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                                        <span id="v2-sheet-save-label">Guardar</span>
                                    </button>
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-sheet-close-edit" aria-label="Cerrar">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                            </header>

                            <nav class="v2-sheet__tabs" id="v2-inv-sheet-tabs" role="tablist" aria-label="Secciones del producto">
                                <button class="v2-sheet__tab is-active" type="button" data-v2-sheet-tab="general" role="tab" aria-selected="true">General</button>
                                <button class="v2-sheet__tab" type="button" data-v2-sheet-tab="stock" role="tab" aria-selected="false">Stock</button>
                                <button class="v2-sheet__tab" type="button" data-v2-sheet-tab="prices" role="tab" aria-selected="false">Precios</button>
                                <button class="v2-sheet__tab" type="button" data-v2-sheet-tab="history" role="tab" aria-selected="false">Historial</button>
                            </nav>

                            <div class="v2-sheet__body">
                                <section class="v2-sheet__pane" data-v2-sheet-pane="general" role="tabpanel">
                                    <dl class="v2-kv" data-v2-sheet-mode="view">
                                        <div><dt>Nombre</dt><dd id="v2-sheet-name">—</dd></div>
                                        <div><dt>SKU</dt><dd id="v2-sheet-sku">—</dd></div>
                                        <div><dt>Control</dt><dd id="v2-sheet-tracking">—</dd></div>
                                        <div><dt>Moneda</dt><dd id="v2-sheet-currency">—</dd></div>
                                        <div><dt>Precio base</dt><dd id="v2-sheet-base-price">—</dd></div>
                                        <div><dt>Estado</dt><dd id="v2-sheet-active">—</dd></div>
                                    </dl>
                                    <form class="v2-sheet__edit-form" data-v2-sheet-mode="edit" hidden novalidate>
                                        <label class="v2-field v2-field--full">
                                            <span>Nombre comercial</span>
                                            <input id="v2-sheet-edit-name" type="text" maxlength="255" required>
                                        </label>
                                        <label class="v2-field">
                                            <span>SKU</span>
                                            <input id="v2-sheet-edit-sku" type="text" maxlength="255" placeholder="Opcional — se genera del nombre">
                                        </label>
                                        <label class="v2-field">
                                            <span>Tipo de control</span>
                                            <select id="v2-sheet-edit-tracking">
                                                <option value="quantity">Por cantidad</option>
                                                <option value="serialized">Serializado / IMEI</option>
                                            </select>
                                        </label>
                                        <label class="v2-field">
                                            <span>Moneda</span>
                                            <select id="v2-sheet-edit-currency">
                                                <option value="USD">USD — Dólar</option>
                                                <option value="VES">VES — Bolívar</option>
                                            </select>
                                        </label>
                                        <label class="v2-field">
                                            <span>Precio base</span>
                                            <input id="v2-sheet-edit-price" type="number" min="0" step="0.01" placeholder="0.00">
                                        </label>
                                        <label class="v2-field">
                                            <span>Estado</span>
                                            <select id="v2-sheet-edit-active">
                                                <option value="1">Activo para venta</option>
                                                <option value="0">Inactivo</option>
                                            </select>
                                        </label>
                                        <div class="v2-sheet__edit-error" id="v2-sheet-edit-error" hidden></div>
                                    </form>
                                </section>

                                <section class="v2-sheet__pane" data-v2-sheet-pane="stock" role="tabpanel" hidden>
                                    <div class="v2-sheet__pane-head">
                                        <h3>Stock por almacén</h3>
                                        <span class="v2-status-pill" id="v2-sheet-stock-total">0 disp.</span>
                                    </div>
                                    <div class="v2-sheet__list" id="v2-sheet-stock">
                                        <p class="v2-sheet__empty">Sin stock cargado.</p>
                                    </div>
                                </section>

                                <section class="v2-sheet__pane" data-v2-sheet-pane="prices" role="tabpanel" hidden>
                                    <div class="v2-sheet__pane-head">
                                        <h3>Precios por lista</h3>
                                        <span class="v2-status-pill" id="v2-sheet-price-count">0 listas</span>
                                    </div>
                                    <div class="v2-sheet__list" id="v2-sheet-prices">
                                        <p class="v2-sheet__empty">Sin precios por lista.</p>
                                    </div>
                                </section>

                                <section class="v2-sheet__pane" data-v2-sheet-pane="history" role="tabpanel" hidden>
                                    <div class="v2-sheet__pane-head">
                                        <h3>Actividad reciente</h3>
                                        <span class="v2-status-pill" id="v2-sheet-change-count">0 cambios</span>
                                    </div>
                                    <div class="v2-sheet__list" id="v2-sheet-history">
                                        <p class="v2-sheet__empty">Sin movimientos recientes.</p>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </div>

                    <section class="v2-page" id="v2-rates-page" data-portal-section="rates" hidden>
                        <div class="v2-page-head">
                            <div>
                                <span class="soft-badge">Monedas</span>
                                <h1>Tasas de cambio</h1>
                                <p id="v2-rates-period">Cargando tipos y valores vigentes…</p>
                            </div>
                            <div class="v2-page-head__actions">
                                <button class="v2-btn v2-btn--primary" type="button" id="v2-rates-new-type">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Nuevo tipo
                                </button>
                                <button class="v2-btn v2-btn--ghost" type="button" id="v2-rates-new-value">
                                    Registrar tasa
                                </button>
                                <button class="v2-btn v2-btn--ghost" type="button" id="v2-rates-refresh">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15A9 9 0 1 1 18 5.3L23 10"/></svg>
                                    Actualizar
                                </button>
                            </div>
                        </div>

                        <section class="v2-metric-grid v2-metric-grid--compact" aria-label="Indicadores de tasas">
                            <article class="v2-metric v2-metric--info v2-metric--compact">
                                <span class="v2-metric__label">Tipos activos</span>
                                <strong class="v2-metric__value" id="v2-rates-metric-types">0</strong>
                                <span class="v2-metric__hint" id="v2-rates-metric-types-hint">0 predeterminados</span>
                            </article>
                            <article class="v2-metric v2-metric--success v2-metric--compact">
                                <span class="v2-metric__label">Tasa vigente (default)</span>
                                <strong class="v2-metric__value" id="v2-rates-metric-current">—</strong>
                                <span class="v2-metric__hint" id="v2-rates-metric-current-hint">Sin tasa activa</span>
                            </article>
                            <article class="v2-metric v2-metric--warning v2-metric--compact">
                                <span class="v2-metric__label">Tasas registradas</span>
                                <strong class="v2-metric__value" id="v2-rates-metric-total">0</strong>
                                <span class="v2-metric__hint" id="v2-rates-metric-total-hint">Histórico completo</span>
                            </article>
                            <article class="v2-metric v2-metric--info v2-metric--compact">
                                <span class="v2-metric__label">Última actualización</span>
                                <strong class="v2-metric__value" id="v2-rates-metric-last">—</strong>
                                <span class="v2-metric__hint" id="v2-rates-metric-last-hint">—</span>
                            </article>
                        </section>

                        <div class="v2-alert-strip" id="v2-rates-alert-strip" hidden role="status" aria-live="polite">
                            <svg class="v2-alert-strip__icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                            <div class="v2-alert-strip__list" id="v2-rates-alert-strip-list"></div>
                            <button class="v2-alert-strip__close" type="button" id="v2-rates-alert-strip-close" aria-label="Cerrar alertas">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        <article class="v2-panel v2-panel--flush v2-rates-catalog">
                            <div class="v2-catalog__head">
                                <div class="v2-catalog__title-block">
                                    <h2>Tipos de tasa</h2>
                                    <p>Define si una tasa está activa o si será la predeterminada.</p>
                                </div>
                                <span class="v2-status-pill v2-status-pill--info" id="v2-rates-types-count">0 tipos</span>
                            </div>
                            <div class="admin-table-wrap">
                                <table class="admin-data-table v2-table v2-table--rates" id="v2-rates-types-table">
                                    <thead>
                                        <tr>
                                            <th class="v2-col-product">Tipo</th>
                                            <th class="v2-col-default">Predeterminada</th>
                                            <th class="v2-col-status">Estado</th>
                                            <th class="v2-col-count">Tasas</th>
                                            <th class="v2-col-date">Última tasa</th>
                                            <th class="v2-col-action" aria-label="Acciones"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="v2-rates-types-tbody"></tbody>
                                </table>
                            </div>
                            <div class="v2-table-footer">
                                <span id="v2-rates-types-count-foot">Sin tipos cargados.</span>
                            </div>
                        </article>

                        <article class="v2-panel v2-panel--flush v2-rates-catalog">
                            <div class="v2-catalog__head">
                                <div class="v2-catalog__title-block">
                                    <h2>Historial de tasas</h2>
                                    <p>Últimas tasas registradas para auditoría rápida. La activa es la vigente para el POS.</p>
                                </div>
                                <span class="v2-status-pill v2-status-pill--info" id="v2-rates-history-count">0 valores</span>
                            </div>
                            <div class="admin-table-wrap">
                                <table class="admin-data-table v2-table v2-table--rates" id="v2-rates-history-table">
                                    <thead>
                                        <tr>
                                            <th class="v2-col-type">Tipo</th>
                                            <th class="v2-col-rate">Tasa (Bs/USD)</th>
                                            <th class="v2-col-effective">Vigencia</th>
                                            <th class="v2-col-status">Estado</th>
                                            <th class="v2-col-source">Fuente</th>
                                            <th class="v2-col-action" aria-label="Acciones"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="v2-rates-history-tbody"></tbody>
                                </table>
                            </div>
                            <div class="v2-table-footer">
                                <span id="v2-rates-history-count-foot">Mostrando los últimos 50 valores.</span>
                                <div class="v2-table-footer__actions">
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-rates-prev">Anterior</button>
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-rates-next">Siguiente</button>
                                </div>
                            </div>
                        </article>

                        <p class="dashboard-status" id="v2-rates-status" role="status" aria-live="polite" style="margin-top: 16px;"></p>
                    </section>

                    <div class="v2-sheet" id="v2-rates-type-sheet" hidden role="dialog" aria-modal="true" aria-labelledby="v2-rate-type-title">
                        <div class="v2-sheet__backdrop" id="v2-rates-type-sheet-backdrop"></div>
                        <div class="v2-sheet__panel" tabindex="-1">
                            <header class="v2-sheet__head">
                                <div>
                                    <span class="soft-badge" id="v2-rate-type-badge">Tipo</span>
                                    <h2 id="v2-rate-type-title">Tipo de tasa</h2>
                                    <p id="v2-rate-type-subtitle">Cargando…</p>
                                </div>
                                <div class="v2-sheet__head-actions" data-v2-rate-sheet-mode="view">
                                    <button class="v2-btn v2-btn--primary" type="button" id="v2-rate-type-edit">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                        Editar
                                    </button>
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-rate-type-close" aria-label="Cerrar">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                                <div class="v2-sheet__head-actions" data-v2-rate-sheet-mode="edit" hidden>
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-rate-type-cancel">Cancelar</button>
                                    <button class="v2-btn v2-btn--primary" type="button" id="v2-rate-type-save">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                                        <span id="v2-rate-type-save-label">Guardar</span>
                                    </button>
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-rate-type-close-edit" aria-label="Cerrar">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                            </header>

                            <nav class="v2-sheet__tabs" role="tablist" aria-label="Secciones del tipo de tasa">
                                <button class="v2-sheet__tab is-active" type="button" data-v2-rate-type-tab="general" role="tab" aria-selected="true">General</button>
                                <button class="v2-sheet__tab" type="button" data-v2-rate-type-tab="history" role="tab" aria-selected="false">Historial</button>
                            </nav>

                            <div class="v2-sheet__body">
                                <section class="v2-sheet__pane" data-v2-rate-type-pane="general" role="tabpanel">
                                    <dl class="v2-kv" data-v2-rate-type-mode="view">
                                        <div><dt>Código</dt><dd id="v2-rate-type-code">—</dd></div>
                                        <div><dt>Nombre</dt><dd id="v2-rate-type-name-view">—</dd></div>
                                        <div><dt>Predeterminada</dt><dd id="v2-rate-type-default">—</dd></div>
                                        <div><dt>Estado</dt><dd id="v2-rate-type-active">—</dd></div>
                                        <div><dt>Creado</dt><dd id="v2-rate-type-created">—</dd></div>
                                    </dl>
                                    <form class="v2-sheet__edit-form" data-v2-rate-type-mode="edit" hidden novalidate>
                                        <label class="v2-field v2-field--full">
                                            <span>Código</span>
                                            <input id="v2-rate-type-edit-code" type="text" maxlength="50" required placeholder="BCV, PARALELO, ENPARALELO">
                                        </label>
                                        <label class="v2-field v2-field--full">
                                            <span>Nombre</span>
                                            <input id="v2-rate-type-edit-name" type="text" maxlength="255" required placeholder="Banco Central de Venezuela">
                                        </label>
                                        <label class="v2-field">
                                            <span>Predeterminada</span>
                                            <select id="v2-rate-type-edit-default">
                                                <option value="0">No</option>
                                                <option value="1">Sí (única)</option>
                                            </select>
                                        </label>
                                        <label class="v2-field">
                                            <span>Estado</span>
                                            <select id="v2-rate-type-edit-active">
                                                <option value="1">Activo</option>
                                                <option value="0">Inactivo</option>
                                            </select>
                                        </label>
                                        <div class="v2-sheet__edit-error" id="v2-rate-type-edit-error" hidden></div>
                                    </form>
                                </section>

                                <section class="v2-sheet__pane" data-v2-rate-type-pane="history" role="tabpanel" hidden>
                                    <div class="v2-sheet__pane-head">
                                        <h3>Tasas registradas</h3>
                                        <span class="v2-status-pill" id="v2-rate-type-history-count">0 valores</span>
                                    </div>
                                    <div class="v2-sheet__list" id="v2-rate-type-history-list">
                                        <p class="v2-sheet__empty">Sin tasas registradas para este tipo.</p>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </div>

                    <div class="v2-sheet" id="v2-rates-value-sheet" hidden role="dialog" aria-modal="true" aria-labelledby="v2-rate-value-title">
                        <div class="v2-sheet__backdrop" id="v2-rates-value-sheet-backdrop"></div>
                        <div class="v2-sheet__panel" tabindex="-1">
                            <header class="v2-sheet__head">
                                <div>
                                    <span class="soft-badge">Nueva tasa</span>
                                    <h2 id="v2-rate-value-title">Registrar valor</h2>
                                    <p id="v2-rate-value-subtitle">Captura el valor vigente y se aplicará al POS y a la sincronización local.</p>
                                </div>
                                <div class="v2-sheet__head-actions">
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-rate-value-cancel">Cancelar</button>
                                    <button class="v2-btn v2-btn--primary" type="button" id="v2-rate-value-save">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                                        Registrar
                                    </button>
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-rate-value-close" aria-label="Cerrar">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                            </header>

                            <div class="v2-sheet__body">
                                <form class="v2-sheet__edit-form v2-sheet__edit-form--full" novalidate>
                                    <label class="v2-field v2-field--full">
                                        <span>Tipo de tasa</span>
                                        <select id="v2-rate-value-type" required></select>
                                    </label>
                                    <label class="v2-field">
                                        <span>Valor (Bs por USD)</span>
                                        <input id="v2-rate-value-amount" type="number" min="0.000001" step="0.000001" required placeholder="36.50">
                                    </label>
                                    <label class="v2-field">
                                        <span>Vigente desde</span>
                                        <input id="v2-rate-value-effective" type="datetime-local" required>
                                    </label>
                                    <label class="v2-field v2-field--full">
                                        <span>Fuente</span>
                                        <input id="v2-rate-value-source" type="text" maxlength="255" placeholder="Manual, BCV, paralelo, monitor dolar...">
                                    </label>
                                    <label class="v2-field v2-field--full v2-field--inline-check">
                                        <input id="v2-rate-value-active" type="checkbox" checked>
                                        Activar como tasa vigente (desactiva las demás del mismo tipo y par)
                                    </label>
                                    <div class="v2-sheet__edit-error" id="v2-rate-value-error" hidden></div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <section class="v2-page" id="v2-reports-page" data-portal-section="reports" hidden>
                        <div class="v2-page-head">
                            <div>
                                <span class="soft-badge">Reportes</span>
                                <h1>Reportes operativos</h1>
                                <p id="v2-reports-period">Cargando actividad…</p>
                            </div>
                            <div class="v2-page-head__actions">
                                <button class="v2-btn v2-btn--ghost" type="button" id="v2-reports-refresh">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15A9 9 0 1 1 18 5.3L23 10"/></svg>
                                    Actualizar
                                </button>
                            </div>
                        </div>

                        <section class="v2-metric-grid v2-metric-grid--compact" aria-label="Indicadores operativos">
                            <article class="v2-metric v2-metric--compact">
                                <span class="v2-metric__label">Ventas POS</span>
                                <strong class="v2-metric__value" id="v2-reports-metric-pos-total">USD 0.00</strong>
                                <span class="v2-metric__hint" id="v2-reports-metric-pos-hint">0 ventas confirmadas</span>
                            </article>
                            <article class="v2-metric v2-metric--compact">
                                <span class="v2-metric__label">Ticket promedio</span>
                                <strong class="v2-metric__value" id="v2-reports-metric-ticket">USD 0.00</strong>
                                <span class="v2-metric__hint">Promedio por venta POS</span>
                            </article>
                            <article class="v2-metric v2-metric--compact v2-metric--warning">
                                <span class="v2-metric__label">Pendientes POS</span>
                                <strong class="v2-metric__value" id="v2-reports-metric-pending">0</strong>
                                <span class="v2-metric__hint" id="v2-reports-metric-pending-hint">USD 0.00 por cerrar</span>
                            </article>
                            <article class="v2-metric v2-metric--compact v2-metric--info">
                                <span class="v2-metric__label">Cajas abiertas</span>
                                <strong class="v2-metric__value" id="v2-reports-metric-open-cash">0</strong>
                                <span class="v2-metric__hint" id="v2-reports-metric-cash-hint">USD 0.00 esperado</span>
                            </article>
                        </section>

                        <div class="v2-alert-strip" id="v2-reports-alert-strip" hidden role="status" aria-live="polite">
                            <svg class="v2-alert-strip__icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                            <div class="v2-alert-strip__list" id="v2-reports-alert-strip-list"></div>
                            <button class="v2-alert-strip__close" type="button" id="v2-reports-alert-strip-close" aria-label="Cerrar alertas">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        <div class="v2-reports-toolbar" role="search">
                            <label class="v2-field v2-field--inline">
                                <span>Periodo</span>
                                <select id="v2-reports-period-select">
                                    <option value="today">Hoy</option>
                                    <option value="week">Semana</option>
                                    <option value="month">Mes</option>
                                    <option value="quarter">Trimestre</option>
                                </select>
                            </label>
                            <label class="v2-field v2-field--inline">
                                <span>Desde</span>
                                <input id="v2-reports-date-from" type="date">
                            </label>
                            <label class="v2-field v2-field--inline">
                                <span>Hasta</span>
                                <input id="v2-reports-date-to" type="date">
                            </label>
                            <label class="v2-field v2-field--inline">
                                <span>Sucursal</span>
                                <select id="v2-reports-branch">
                                    <option value="">Todas</option>
                                </select>
                            </label>
                            <label class="v2-field v2-field--inline">
                                <span>Caja</span>
                                <select id="v2-reports-cash-register">
                                    <option value="">Todas</option>
                                </select>
                            </label>
                            <label class="v2-field v2-field--inline">
                                <span>Cajero</span>
                                <select id="v2-reports-cashier">
                                    <option value="">Todos</option>
                                </select>
                            </label>
                            <label class="v2-field v2-field--inline">
                                <span>Estado POS</span>
                                <select id="v2-reports-order-status">
                                    <option value="all">Todos</option>
                                    <option value="paid">Pagadas</option>
                                    <option value="open">Pendientes</option>
                                    <option value="cancelled">Canceladas</option>
                                </select>
                            </label>
                            <button class="v2-btn v2-btn--primary" type="button" id="v2-reports-apply">Aplicar</button>
                            <button class="v2-btn v2-btn--ghost" type="button" id="v2-reports-clear">Limpiar</button>
                        </div>

                        <div class="v2-reports-grid">
                            <article class="v2-panel v2-panel--flush v2-reports-panel v2-reports-panel--wide">
                                <div class="v2-catalog__head">
                                    <div class="v2-catalog__title-block">
                                        <h2>Ventas recientes</h2>
                                        <p id="v2-reports-orders-subtitle">Últimas órdenes POS del periodo.</p>
                                    </div>
                                    <button class="v2-btn v2-btn--ghost v2-btn--sm" type="button" id="v2-reports-export-orders">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        CSV
                                    </button>
                                </div>
                                <div class="admin-table-wrap">
                                    <table class="admin-data-table v2-table v2-table--reports">
                                        <thead>
                                            <tr>
                                                <th>Orden</th>
                                                <th>Cliente</th>
                                                <th>Caja</th>
                                                <th>Estado</th>
                                                <th class="v2-col-money">Total</th>
                                                <th class="v2-col-money">Pagado</th>
                                                <th>Fecha</th>
                                            </tr>
                                        </thead>
                                        <tbody id="v2-reports-orders-tbody"></tbody>
                                    </table>
                                </div>
                            </article>

                            <article class="v2-panel v2-panel--flush v2-reports-panel">
                                <div class="v2-catalog__head">
                                    <div class="v2-catalog__title-block">
                                        <h2>Métodos de pago</h2>
                                        <p>Pagos capturados por moneda y método.</p>
                                    </div>
                                    <button class="v2-btn v2-btn--ghost v2-btn--sm" type="button" id="v2-reports-export-payments">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        CSV
                                    </button>
                                </div>
                                <div class="admin-table-wrap">
                                    <table class="admin-data-table v2-table v2-table--reports">
                                        <thead>
                                            <tr>
                                                <th>Método</th>
                                                <th class="v2-col-count">Pagos</th>
                                                <th class="v2-col-money">Total USD</th>
                                            </tr>
                                        </thead>
                                        <tbody id="v2-reports-payments-tbody"></tbody>
                                    </table>
                                </div>
                            </article>

                            <article class="v2-panel v2-panel--flush v2-reports-panel">
                                <div class="v2-catalog__head">
                                    <div class="v2-catalog__title-block">
                                        <h2>Productos vendidos</h2>
                                        <p>Ranking por monto facturado.</p>
                                    </div>
                                    <button class="v2-btn v2-btn--ghost v2-btn--sm" type="button" id="v2-reports-export-products">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        CSV
                                    </button>
                                </div>
                                <div class="admin-table-wrap">
                                    <table class="admin-data-table v2-table v2-table--reports">
                                        <thead>
                                            <tr>
                                                <th class="v2-col-rank">#</th>
                                                <th>Producto</th>
                                                <th class="v2-col-count">Cant.</th>
                                                <th class="v2-col-money">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody id="v2-reports-products-tbody"></tbody>
                                    </table>
                                </div>
                            </article>

                            <article class="v2-panel v2-panel--flush v2-reports-panel v2-reports-panel--wide">
                                <div class="v2-catalog__head">
                                    <div class="v2-catalog__title-block">
                                        <h2>Actividad de caja</h2>
                                        <p>Turnos abiertos o cerrados dentro del periodo.</p>
                                    </div>
                                    <button class="v2-btn v2-btn--ghost v2-btn--sm" type="button" id="v2-reports-export-cash">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        CSV
                                    </button>
                                </div>
                                <div class="admin-table-wrap">
                                    <table class="admin-data-table v2-table v2-table--reports">
                                        <thead>
                                            <tr>
                                                <th>Caja</th>
                                                <th>Sucursal</th>
                                                <th>Cajero</th>
                                                <th>Estado</th>
                                                <th class="v2-col-money">Esperado</th>
                                                <th class="v2-col-money">Diferencia</th>
                                                <th>Apertura</th>
                                            </tr>
                                        </thead>
                                        <tbody id="v2-reports-cash-tbody"></tbody>
                                    </table>
                                </div>
                            </article>
                        </div>

                        <p class="dashboard-status" id="v2-reports-status" role="status" aria-live="polite" style="margin-top: 16px;"></p>
                    </section>

                    <section class="v2-page" id="v2-movements-page" data-portal-section="movements" hidden>
                        <div class="v2-page-head">
                            <div>
                                <span class="soft-badge">Inventario</span>
                                <h1>Historial de movimientos</h1>
                                <p id="v2-mov-period">Cargando actividad…</p>
                            </div>
                            <div class="v2-page-head__actions">
                                <button class="v2-btn v2-btn--ghost" type="button" id="v2-mov-refresh">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15A9 9 0 1 1 18 5.3L23 10"/></svg>
                                    Actualizar
                                </button>
                            </div>
                        </div>

                        <section class="v2-metric-grid v2-metric-grid--compact" aria-label="Indicadores de movimientos">
                            <article class="v2-metric v2-metric--info v2-metric--compact">
                                <span class="v2-metric__label">Movimientos (vista)</span>
                                <strong class="v2-metric__value" id="v2-mov-metric-total">0</strong>
                                <span class="v2-metric__hint" id="v2-mov-metric-total-hint">En la página actual</span>
                            </article>
                            <article class="v2-metric v2-metric--success v2-metric--compact">
                                <span class="v2-metric__label">Entradas netas</span>
                                <strong class="v2-metric__value" id="v2-mov-metric-in">0</strong>
                                <span class="v2-metric__hint">Unidades que entraron a stock</span>
                            </article>
                            <article class="v2-metric v2-metric--warning v2-metric--compact">
                                <span class="v2-metric__label">Salidas netas</span>
                                <strong class="v2-metric__value" id="v2-mov-metric-out">0</strong>
                                <span class="v2-metric__hint">Unidades que salieron de stock</span>
                            </article>
                            <article class="v2-metric v2-metric--info v2-metric--compact">
                                <span class="v2-metric__label">Productos distintos</span>
                                <strong class="v2-metric__value" id="v2-mov-metric-products">0</strong>
                                <span class="v2-metric__hint">En la página actual</span>
                            </article>
                        </section>

                        <div class="v2-alert-strip" id="v2-mov-alert-strip" hidden role="status" aria-live="polite">
                            <svg class="v2-alert-strip__icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                            <div class="v2-alert-strip__list" id="v2-mov-alert-strip-list"></div>
                            <button class="v2-alert-strip__close" type="button" id="v2-mov-alert-strip-close" aria-label="Cerrar alertas">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        <article class="v2-panel v2-panel--flush v2-mov-catalog">
                            <div class="v2-catalog__head">
                                <div class="v2-catalog__title-block">
                                    <h2>Actividad reciente</h2>
                                    <p>Entradas, salidas, ventas, devoluciones, ajustes y traslados por empresa.</p>
                                </div>
                                <span class="v2-status-pill v2-status-pill--info" id="v2-mov-filter-status">Todos los tipos</span>
                            </div>

                            <div class="v2-mov-toolbar" role="search">
                                <label class="v2-field v2-field--search v2-field--search-lg v2-mov-toolbar__search">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    <input id="v2-mov-search" type="search" placeholder="Buscar producto, SKU, motivo o referencia..." autocomplete="off">
                                </label>
                                <label class="v2-field v2-field--inline">
                                    <span>Tipo</span>
                                    <select id="v2-mov-type">
                                        <option value="all">Todos</option>
                                        <option value="purchase">Compra</option>
                                        <option value="purchase_return">Dev. proveedor</option>
                                        <option value="sale">Venta</option>
                                        <option value="sale_return">Dev. venta</option>
                                        <option value="adjustment_in">Ajuste entrada</option>
                                        <option value="adjustment_out">Ajuste salida</option>
                                        <option value="transfer_in">Traslado entrada</option>
                                        <option value="transfer_out">Traslado salida</option>
                                        <option value="return_in">Retorno entrada</option>
                                        <option value="return_out">Retorno salida</option>
                                        <option value="damaged">Dañado</option>
                                        <option value="reserved">Reservado</option>
                                        <option value="released">Liberado</option>
                                    </select>
                                </label>
                                <label class="v2-field v2-field--inline">
                                    <span>Almacén</span>
                                    <select id="v2-mov-warehouse">
                                        <option value="">Todos</option>
                                    </select>
                                </label>
                                <label class="v2-field v2-field--inline">
                                    <span>Desde</span>
                                    <input id="v2-mov-from" type="date">
                                </label>
                                <label class="v2-field v2-field--inline">
                                    <span>Hasta</span>
                                    <input id="v2-mov-to" type="date">
                                </label>
                                <button class="v2-btn v2-btn--primary" type="button" id="v2-mov-apply">Aplicar</button>
                                <button class="v2-btn v2-btn--ghost" type="button" id="v2-mov-clear">Limpiar</button>
                            </div>

                            <div class="admin-table-wrap">
                                <table class="admin-data-table v2-table v2-table--movements" id="v2-mov-table">
                                    <thead>
                                        <tr>
                                            <th class="v2-col-datetime">Fecha</th>
                                            <th class="v2-col-type">Tipo</th>
                                            <th class="v2-col-product">Producto</th>
                                            <th class="v2-col-qty">Cantidad</th>
                                            <th class="v2-col-warehouse">Almacén / Sede</th>
                                            <th class="v2-col-reason">Motivo / referencia</th>
                                            <th class="v2-col-action" aria-label="Acciones"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="v2-mov-tbody"></tbody>
                                </table>
                            </div>

                            <div class="v2-table-footer">
                                <span id="v2-mov-count">Sin movimientos cargados.</span>
                                <div class="v2-table-footer__actions">
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-mov-prev">Anterior</button>
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-mov-next">Siguiente</button>
                                </div>
                            </div>
                        </article>

                        <p class="dashboard-status" id="v2-mov-status" role="status" aria-live="polite" style="margin-top: 16px;"></p>
                    </section>

                    <div class="v2-sheet" id="v2-mov-sheet" hidden role="dialog" aria-modal="true" aria-labelledby="v2-mov-sheet-title">
                        <div class="v2-sheet__backdrop" id="v2-mov-sheet-backdrop"></div>
                        <div class="v2-sheet__panel" tabindex="-1">
                            <header class="v2-sheet__head">
                                <div>
                                    <span class="soft-badge" id="v2-mov-sheet-badge">Movimiento</span>
                                    <h2 id="v2-mov-sheet-title">Movimiento</h2>
                                    <p id="v2-mov-sheet-subtitle">Cargando…</p>
                                </div>
                                <div class="v2-sheet__head-actions">
                                    <button class="v2-btn v2-btn--ghost" type="button" id="v2-mov-sheet-close" aria-label="Cerrar">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                            </header>

                            <nav class="v2-sheet__tabs" role="tablist" aria-label="Secciones del movimiento">
                                <button class="v2-sheet__tab is-active" type="button" data-v2-mov-tab="general" role="tab" aria-selected="true">General</button>
                                <button class="v2-sheet__tab" type="button" data-v2-mov-tab="product" role="tab" aria-selected="false">Producto</button>
                                <button class="v2-sheet__tab" type="button" data-v2-mov-tab="context" role="tab" aria-selected="false">Contexto</button>
                            </nav>

                            <div class="v2-sheet__body">
                                <section class="v2-sheet__pane" data-v2-mov-pane="general" role="tabpanel">
                                    <dl class="v2-kv">
                                        <div><dt>Tipo</dt><dd id="v2-mov-sheet-type">—</dd></div>
                                        <div><dt>Cantidad</dt><dd id="v2-mov-sheet-qty">—</dd></div>
                                        <div><dt>Costo unitario</dt><dd id="v2-mov-sheet-cost">—</dd></div>
                                        <div><dt>Fecha</dt><dd id="v2-mov-sheet-date">—</dd></div>
                                        <div><dt>Motivo</dt><dd id="v2-mov-sheet-reason">—</dd></div>
                                        <div><dt>Referencia</dt><dd id="v2-mov-sheet-ref">—</dd></div>
                                    </dl>
                                </section>

                                <section class="v2-sheet__pane" data-v2-mov-pane="product" role="tabpanel" hidden>
                                    <dl class="v2-kv">
                                        <div><dt>Producto</dt><dd id="v2-mov-sheet-product-name">—</dd></div>
                                        <div><dt>SKU</dt><dd id="v2-mov-sheet-product-sku">—</dd></div>
                                        <div><dt>ID</dt><dd id="v2-mov-sheet-product-id">—</dd></div>
                                    </dl>
                                </section>

                                <section class="v2-sheet__pane" data-v2-mov-pane="context" role="tabpanel" hidden>
                                    <dl class="v2-kv">
                                        <div><dt>Almacén</dt><dd id="v2-mov-sheet-warehouse">—</dd></div>
                                        <div><dt>Sucursal</dt><dd id="v2-mov-sheet-branch">—</dd></div>
                                        <div><dt>Registrado por</dt><dd id="v2-mov-sheet-user">—</dd></div>
                                        <div><dt>ID interno</dt><dd id="v2-mov-sheet-id">—</dd></div>
                                    </dl>
                                </section>
                            </div>
                        </div>
                    </div>
                </main>

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
                        <button class="portal-nav__item" type="button" data-portal-section="rates">
                            <span>Tasas</span>
                            <small>BCV y paralelo</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="movements">
                            <span>Movimientos</span>
                            <small>Entradas y salidas</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="transfers">
                            <span>Traslados</span>
                            <small>Logistica interna</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="purchases">
                            <span>Compras</span>
                            <small>Recepciones</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="receivables">
                            <span>CxC</span>
                            <small>Cobros cliente</small>
                        </button>
                        <button class="portal-nav__item" type="button" data-portal-section="customers">
                            <span>Clientes</span>
                            <small>Datos y cartera</small>
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

                        <section class="admin-module-panel sales-admin" id="admin-sales-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Ventas</span>
                                    <h3>Ventas POS</h3>
                                    <p>Consulta ordenes, pagos, saldos y detalle de productos vendidos por empresa, sucursal, caja o cajero.</p>
                                </div>
                                <div class="module-head__actions">
                                    <button class="ghost-button" type="button" id="admin-sales-export">Exportar CSV</button>
                                    <button class="ghost-button" type="button" id="admin-sales-refresh">Actualizar ventas</button>
                                </div>
                            </div>

                            <div class="sales-admin__filters" aria-label="Filtros de ventas POS">
                                <label>
                                    Desde
                                    <input type="date" id="admin-sales-date-from">
                                </label>
                                <label>
                                    Hasta
                                    <input type="date" id="admin-sales-date-to">
                                </label>
                                <label>
                                    Sucursal
                                    <select id="admin-sales-branch">
                                        <option value="">Todas</option>
                                    </select>
                                </label>
                                <label>
                                    Caja
                                    <select id="admin-sales-cash-register">
                                        <option value="">Todas</option>
                                    </select>
                                </label>
                                <label>
                                    Cajero
                                    <select id="admin-sales-cashier">
                                        <option value="">Todos</option>
                                    </select>
                                </label>
                                <label>
                                    Estado
                                    <select id="admin-sales-status-filter">
                                        <option value="all">Todos</option>
                                        <option value="paid">Pagadas</option>
                                        <option value="open">Pendientes</option>
                                        <option value="cancelled">Canceladas</option>
                                    </select>
                                </label>
                                <label class="sales-admin__search">
                                    Buscar
                                    <input type="search" id="admin-sales-search" placeholder="Orden, cliente, cajero, producto o SKU">
                                </label>
                                <button class="primary-button primary-button--fit" type="button" id="admin-sales-apply">Aplicar</button>
                                <button class="ghost-button ghost-button--compact" type="button" id="admin-sales-clear">Limpiar</button>
                            </div>

                            <div class="sales-admin__summary" aria-label="Resumen de ventas POS">
                                <article>
                                    <span>Ordenes</span>
                                    <strong id="admin-sales-summary-orders">0</strong>
                                    <small>Dentro del filtro</small>
                                </article>
                                <article>
                                    <span>Pagadas</span>
                                    <strong id="admin-sales-summary-paid">0</strong>
                                    <small>Ventas cerradas</small>
                                </article>
                                <article>
                                    <span>Pendientes</span>
                                    <strong id="admin-sales-summary-open">0</strong>
                                    <small>Cobro incompleto</small>
                                </article>
                                <article>
                                    <span>Total</span>
                                    <strong id="admin-sales-summary-total">USD 0.00</strong>
                                    <small>Monto facturado</small>
                                </article>
                                <article>
                                    <span>Cobrado</span>
                                    <strong id="admin-sales-summary-collected">USD 0.00</strong>
                                    <small>Pagos capturados</small>
                                </article>
                                <article>
                                    <span>Ticket prom.</span>
                                    <strong id="admin-sales-summary-ticket">USD 0.00</strong>
                                    <small>Promedio por orden</small>
                                </article>
                            </div>

                            <div class="sales-admin__analytics" aria-label="Indicadores administrativos de ventas POS">
                                <section class="content-panel sales-admin__analytics-card">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Por sucursal</h4>
                                            <p>Ranking por monto cobrado.</p>
                                        </div>
                                    </div>
                                    <div class="sales-admin__ranking" id="admin-sales-by-branch"></div>
                                </section>
                                <section class="content-panel sales-admin__analytics-card">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Por caja</h4>
                                            <p>Rendimiento por caja fisica.</p>
                                        </div>
                                    </div>
                                    <div class="sales-admin__ranking" id="admin-sales-by-cash-register"></div>
                                </section>
                                <section class="content-panel sales-admin__analytics-card">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Por cajero</h4>
                                            <p>Produccion del equipo.</p>
                                        </div>
                                    </div>
                                    <div class="sales-admin__ranking" id="admin-sales-by-cashier"></div>
                                </section>
                                <section class="content-panel sales-admin__analytics-card">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Metodos de pago</h4>
                                            <p>Distribucion cobrada.</p>
                                        </div>
                                    </div>
                                    <div class="sales-admin__ranking" id="admin-sales-by-payment"></div>
                                </section>
                                <section class="content-panel sales-admin__analytics-card">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Productos top</h4>
                                            <p>Unidades mas vendidas.</p>
                                        </div>
                                    </div>
                                    <div class="sales-admin__ranking" id="admin-sales-top-products"></div>
                                </section>
                            </div>

                            <div class="sales-admin__layout">
                                <section class="content-panel sales-admin__list">
                                    <div class="panel-heading">
                                        <div>
                                            <h4>Ordenes POS</h4>
                                            <p id="admin-sales-period">Periodo actual.</p>
                                        </div>
                                    </div>
                                    <div class="admin-table-wrap admin-table-wrap--compact sales-admin__table">
                                        <table class="admin-data-table admin-data-table--compact">
                                            <thead>
                                                <tr>
                                                    <th>Orden</th>
                                                    <th>Cliente</th>
                                                    <th>Caja</th>
                                                    <th>Cajero</th>
                                                    <th>Estado</th>
                                                    <th>Total</th>
                                                    <th>Pagado</th>
                                                    <th>Accion</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-sales-table"></tbody>
                                        </table>
                                    </div>
                                    <div class="table-footer">
                                        <span id="admin-sales-count">Sin ventas cargadas.</span>
                                        <div class="table-footer__actions">
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-sales-prev">Anterior</button>
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-sales-next">Siguiente</button>
                                        </div>
                                    </div>
                                </section>

                                <aside class="content-panel sales-admin__detail">
                                    <div class="panel-heading">
                                        <div>
                                            <h4 id="admin-sales-detail-title">Detalle de venta</h4>
                                            <p id="admin-sales-detail-subtitle">Selecciona una orden para revisar items y pagos.</p>
                                        </div>
                                        <span class="status-pill" id="admin-sales-detail-status">Sin seleccion</span>
                                    </div>

                                    <div class="sales-admin__totals" id="admin-sales-detail-totals">
                                        <div><span>Total</span><strong>USD 0.00</strong></div>
                                        <div><span>Pagado</span><strong>USD 0.00</strong></div>
                                        <div><span>Saldo</span><strong>USD 0.00</strong></div>
                                    </div>

                                    <div class="sales-admin__detail-context" id="admin-sales-detail-context">
                                        <span><strong>Cliente</strong> Sin orden seleccionada</span>
                                        <span><strong>Caja</strong> Sin orden seleccionada</span>
                                        <span><strong>Cajero</strong> Sin orden seleccionada</span>
                                    </div>

                                    <h5>Productos</h5>
                                    <div class="sales-admin__detail-list" id="admin-sales-detail-items">
                                        <p>Sin orden seleccionada.</p>
                                    </div>

                                    <h5>Pagos</h5>
                                    <div class="sales-admin__detail-list" id="admin-sales-detail-payments">
                                        <p>Sin pagos cargados.</p>
                                    </div>
                                </aside>
                            </div>

                            <p class="dashboard-status" id="admin-sales-status" role="status" aria-live="polite"></p>
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

                        <section class="admin-module-panel rates-admin" id="admin-rates-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Monedas</span>
                                    <h3>Tasas de cambio</h3>
                                    <p>Crea tipos de tasa como BCV o paralelo y registra el valor vigente para sincronizarlo con los locales.</p>
                                </div>
                                <div class="module-head__actions">
                                    <button class="ghost-button" type="button" id="admin-rates-refresh">Actualizar tasas</button>
                                </div>
                            </div>

                            <div class="rates-admin__layout">
                                <div class="rates-admin__main">
                                    <section class="content-panel content-panel--flat">
                                        <div class="panel-heading">
                                            <div>
                                                <h4>Tipos de tasa</h4>
                                                <p>Define si una tasa esta activa o si sera la predeterminada.</p>
                                            </div>
                                        </div>
                                        <div class="admin-table-wrap admin-table-wrap--compact rates-admin__table">
                                            <table class="admin-data-table admin-data-table--compact">
                                                <thead>
                                                    <tr>
                                                        <th>Codigo</th>
                                                        <th>Nombre</th>
                                                        <th>Pred.</th>
                                                        <th>Estado</th>
                                                        <th>Tasa activa</th>
                                                        <th>Accion</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="admin-rate-types-table"></tbody>
                                            </table>
                                        </div>
                                    </section>

                                    <section class="content-panel content-panel--flat">
                                        <div class="panel-heading">
                                            <div>
                                                <h4>Historial reciente</h4>
                                                <p>Ultimas tasas registradas para auditoria rapida.</p>
                                            </div>
                                        </div>
                                        <div class="admin-table-wrap admin-table-wrap--compact rates-admin__table rates-admin__table--short">
                                            <table class="admin-data-table admin-data-table--compact">
                                                <thead>
                                                    <tr>
                                                        <th>Tipo</th>
                                                        <th>Valor</th>
                                                        <th>Vigencia</th>
                                                        <th>Estado</th>
                                                        <th>Fuente</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="admin-rates-table"></tbody>
                                            </table>
                                        </div>
                                    </section>
                                </div>

                                <aside class="rates-editor">
                                    <section class="rates-editor__block">
                                        <span class="soft-badge">Tipo</span>
                                        <h4 id="admin-rate-type-title">Nuevo tipo de tasa</h4>
                                        <div class="rates-editor__grid">
                                            <label class="field">
                                                <span>Codigo</span>
                                                <input id="admin-rate-type-code" type="text" maxlength="30" placeholder="BCV">
                                            </label>
                                            <label class="field">
                                                <span>Nombre</span>
                                                <input id="admin-rate-type-name" type="text" maxlength="120" placeholder="Banco Central de Venezuela">
                                            </label>
                                        </div>
                                        <div class="inline-checks">
                                            <label><input id="admin-rate-type-default" type="checkbox"> Predeterminada</label>
                                            <label><input id="admin-rate-type-active" type="checkbox" checked> Activa</label>
                                        </div>
                                        <div class="purchase-editor__actions">
                                            <button class="primary-button" type="button" id="admin-rate-type-save">Guardar tipo</button>
                                            <button class="ghost-button" type="button" id="admin-rate-type-deactivate">Desactivar</button>
                                            <button class="ghost-button" type="button" id="admin-rate-type-cancel">Limpiar</button>
                                        </div>
                                    </section>

                                    <section class="rates-editor__block">
                                        <span class="soft-badge">Valor</span>
                                        <h4>Registrar tasa</h4>
                                        <div class="rates-editor__grid">
                                            <label class="field">
                                                <span>Tipo</span>
                                                <select id="admin-rate-value-type"></select>
                                            </label>
                                            <label class="field">
                                                <span>Valor Bs por USD</span>
                                                <input id="admin-rate-value" type="number" min="0.000001" step="0.000001" placeholder="500.00">
                                            </label>
                                            <label class="field">
                                                <span>Vigente desde</span>
                                                <input id="admin-rate-effective-at" type="datetime-local">
                                            </label>
                                            <label class="field">
                                                <span>Fuente</span>
                                                <input id="admin-rate-source" type="text" maxlength="255" placeholder="Manual, BCV...">
                                            </label>
                                        </div>
                                        <label class="inline-check"><input id="admin-rate-active" type="checkbox" checked> Activar como tasa vigente</label>
                                        <button class="primary-button" type="button" id="admin-rate-save">Registrar tasa</button>
                                    </section>
                                </aside>
                            </div>

                            <p class="dashboard-status" id="admin-rates-status" role="status" aria-live="polite"></p>
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

                        <section class="admin-module-panel customers-admin" id="admin-customers-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Cartera</span>
                                    <h3>Clientes</h3>
                                    <p>Administra clientes por empresa para ventas POS, cuentas por cobrar, reportes y sincronizacion.</p>
                                </div>
                                <div class="module-head__actions">
                                    <button class="primary-button primary-button--fit" type="button" id="admin-customer-new">Nuevo cliente</button>
                                    <button class="ghost-button" type="button" id="admin-customers-refresh">Actualizar clientes</button>
                                </div>
                            </div>

                            <div class="customers-admin__layout">
                                <div class="customers-admin__main">
                                    <div class="customers-admin__filters" role="search">
                                        <label class="field">
                                            <span>Buscar</span>
                                            <input id="admin-customers-search" type="search" placeholder="Nombre, documento, correo o telefono">
                                        </label>
                                        <label class="field">
                                            <span>Estado</span>
                                            <select id="admin-customers-active">
                                                <option value="all">Todos</option>
                                                <option value="active">Activos</option>
                                                <option value="inactive">Inactivos</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>Tipo</span>
                                            <select id="admin-customers-type">
                                                <option value="all">Todos</option>
                                                <option value="regular">Clientes</option>
                                                <option value="generic">Consumidor final</option>
                                            </select>
                                        </label>
                                        <button class="primary-button primary-button--fit" type="button" id="admin-customers-apply">Aplicar</button>
                                        <button class="ghost-button ghost-button--compact" type="button" id="admin-customers-clear">Limpiar</button>
                                    </div>

                                    <div class="admin-table-wrap customers-admin__table">
                                        <table class="admin-data-table admin-data-table--compact">
                                            <thead>
                                                <tr>
                                                    <th>Cliente</th>
                                                    <th>Documento</th>
                                                    <th>Contacto</th>
                                                    <th>Tipo</th>
                                                    <th>Estado</th>
                                                    <th>Actualizado</th>
                                                    <th>Accion</th>
                                                </tr>
                                            </thead>
                                            <tbody id="admin-customers-table"></tbody>
                                        </table>
                                    </div>

                                    <div class="table-footer">
                                        <span id="admin-customers-count">Sin clientes cargados.</span>
                                        <div class="table-footer__actions">
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-customers-prev">Anterior</button>
                                            <button class="ghost-button ghost-button--compact" type="button" id="admin-customers-next">Siguiente</button>
                                        </div>
                                    </div>
                                </div>

                                <aside class="customer-editor" id="admin-customer-editor">
                                    <span class="soft-badge">Registro rapido</span>
                                    <h4 id="admin-customer-editor-title">Nuevo cliente</h4>
                                    <p id="admin-customer-editor-subtitle">Completa datos de identificacion y contacto. El documento es unico por empresa.</p>

                                    <label class="field">
                                        <span>Nombre</span>
                                        <input id="admin-customer-name" type="text" maxlength="255" placeholder="Nombre o razon social">
                                    </label>

                                    <div class="customer-editor__grid">
                                        <label class="field">
                                            <span>Tipo</span>
                                            <select id="admin-customer-document-type">
                                                <option value="V">V</option>
                                                <option value="J">J</option>
                                                <option value="E">E</option>
                                                <option value="G">G</option>
                                                <option value="P">P</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>Documento</span>
                                            <input id="admin-customer-document-number" type="text" maxlength="50" placeholder="Cedula, RIF o pasaporte">
                                        </label>
                                    </div>

                                    <div class="customer-editor__grid">
                                        <label class="field">
                                            <span>Telefono</span>
                                            <input id="admin-customer-phone" type="text" maxlength="50" placeholder="Telefono">
                                        </label>
                                        <label class="field">
                                            <span>Correo</span>
                                            <input id="admin-customer-email" type="email" maxlength="255" placeholder="cliente@correo.com">
                                        </label>
                                    </div>

                                    <label class="field">
                                        <span>Direccion fiscal</span>
                                        <textarea id="admin-customer-address" rows="2" maxlength="500" placeholder="Direccion fiscal"></textarea>
                                    </label>

                                    <div class="customer-editor__checks">
                                        <label class="customer-editor__check">
                                            <input id="admin-customer-generic-edit" type="checkbox">
                                            <span>Consumidor final</span>
                                        </label>
                                        <label class="customer-editor__check">
                                            <input id="admin-customer-active-edit" type="checkbox" checked>
                                            <span>Cliente activo</span>
                                        </label>
                                    </div>

                                    <div class="customer-editor__actions">
                                        <button class="primary-button" type="button" id="admin-customer-save">Guardar cliente</button>
                                        <button class="danger-button" type="button" id="admin-customer-deactivate">Desactivar</button>
                                        <button class="ghost-button" type="button" id="admin-customer-cancel">Limpiar</button>
                                    </div>
                                </aside>
                            </div>

                            <p class="dashboard-status" id="admin-customers-status" role="status" aria-live="polite"></p>
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

                        <section class="admin-module-panel transfers-admin" id="admin-transfers-module" hidden>
                            <div class="module-head">
                                <div>
                                    <span class="soft-badge">Logistica</span>
                                    <h3>Traslados entre almacenes</h3>
                                    <p>Listado administrativo de traslados: busca por codigo, filtra por estado, almacen o periodo y detecta diferencias pendientes.</p>
                                </div>
                                <div class="module-head__actions">
                                    <button class="ghost-button" type="button" id="admin-transfers-export">Exportar CSV</button>
                                    <button class="ghost-button" type="button" id="admin-transfers-refresh">Actualizar traslados</button>
                                </div>
                            </div>

                            <div class="transfers-chips" id="admin-transfers-chips" role="group" aria-label="Resumen por estado">
                                <button class="transfer-chip transfer-chip--total" type="button" data-admin-transfer-chip="all">
                                    <span class="transfer-chip__label">Total</span>
                                    <strong id="admin-transfers-chip-total">0</strong>
                                </button>
                                <button class="transfer-chip" type="button" data-admin-transfer-chip="in_flight">
                                    <span class="transfer-chip__label">En transito</span>
                                    <strong id="admin-transfers-chip-in-flight">0</strong>
                                </button>
                                <button class="transfer-chip" type="button" data-admin-transfer-chip="with_differences">
                                    <span class="transfer-chip__label">Con diferencias</span>
                                    <strong id="admin-transfers-chip-differences">0</strong>
                                </button>
                                <button class="transfer-chip" type="button" data-admin-transfer-chip="requested">
                                    <span class="transfer-chip__label">Solicitados</span>
                                    <strong id="admin-transfers-chip-requested">0</strong>
                                </button>
                                <button class="transfer-chip" type="button" data-admin-transfer-chip="dispatched">
                                    <span class="transfer-chip__label">Despachados</span>
                                    <strong id="admin-transfers-chip-dispatched">0</strong>
                                </button>
                                <button class="transfer-chip" type="button" data-admin-transfer-chip="completed_with_differences">
                                    <span class="transfer-chip__label">Cerrados c/diff</span>
                                    <strong id="admin-transfers-chip-completed-differences">0</strong>
                                </button>
                            </div>

                            <div class="transfers-admin__filters" role="search">
                                <label class="field transfers-admin__search">
                                    <span>Buscar</span>
                                    <input id="admin-transfers-search" type="search" placeholder="Codigo, guia, referencia o notas">
                                </label>
                                <label class="field">
                                    <span>Almacen</span>
                                    <select id="admin-transfers-warehouse">
                                        <option value="">Todos</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Desde</span>
                                    <input id="admin-transfers-date-from" type="date">
                                </label>
                                <label class="field">
                                    <span>Hasta</span>
                                    <input id="admin-transfers-date-to" type="date">
                                </label>
                                <label class="field transfers-admin__statuses">
                                    <span>Estados</span>
                                    <div class="transfers-admin__status-options" id="admin-transfers-status-options">
                                        <label class="status-toggle"><input type="checkbox" value="requested">Solicitado</label>
                                        <label class="status-toggle"><input type="checkbox" value="in_preparation">En preparacion</label>
                                        <label class="status-toggle"><input type="checkbox" value="prepared">Preparado</label>
                                        <label class="status-toggle"><input type="checkbox" value="prepared_with_differences">Prep. c/diff</label>
                                        <label class="status-toggle"><input type="checkbox" value="dispatched">Despachado</label>
                                        <label class="status-toggle"><input type="checkbox" value="in_reception">En recepcion</label>
                                        <label class="status-toggle"><input type="checkbox" value="completed">Completado</label>
                                        <label class="status-toggle"><input type="checkbox" value="completed_with_differences">Comp. c/diff</label>
                                        <label class="status-toggle"><input type="checkbox" value="rejected">Rechazado</label>
                                        <label class="status-toggle"><input type="checkbox" value="cancelled">Cancelado</label>
                                    </div>
                                </label>
                                <div class="transfers-admin__actions">
                                    <button class="primary-button primary-button--fit" type="button" id="admin-transfers-apply">Aplicar</button>
                                    <button class="ghost-button ghost-button--compact" type="button" id="admin-transfers-clear">Limpiar</button>
                                </div>
                            </div>

                            <div class="admin-table-wrap transfers-admin__table">
                                <table class="admin-data-table admin-data-table--compact">
                                    <thead>
                                        <tr>
                                            <th>Codigo</th>
                                            <th>Origen a Destino</th>
                                            <th>Estado</th>
                                            <th>Items</th>
                                            <th>Diferencias</th>
                                            <th>Procesado</th>
                                            <th>Accion</th>
                                        </tr>
                                    </thead>
                                    <tbody id="admin-transfers-table"></tbody>
                                </table>
                            </div>

                            <div class="table-footer">
                                <span id="admin-transfers-count">Sin traslados cargados.</span>
                                <div class="table-footer__actions">
                                    <button class="ghost-button ghost-button--compact" type="button" id="admin-transfers-prev">Anterior</button>
                                    <button class="ghost-button ghost-button--compact" type="button" id="admin-transfers-next">Siguiente</button>
                                </div>
                            </div>

                            <p class="dashboard-status" id="admin-transfers-status" role="status" aria-live="polite"></p>
                        </section>

                        <aside class="transfers-drawer" id="admin-transfer-drawer" hidden>
                            <div class="transfers-drawer__backdrop" data-admin-transfer-drawer-close></div>
                            <div class="transfers-drawer__panel" role="dialog" aria-labelledby="admin-transfer-drawer-title" aria-modal="true">
                                <header class="transfers-drawer__header">
                                    <div class="transfers-drawer__heading">
                                        <span class="soft-badge">Traslado</span>
                                        <h3 id="admin-transfer-drawer-title">Cargando...</h3>
                                        <p id="admin-transfer-drawer-subtitle">—</p>
                                    </div>
                                    <button class="transfers-drawer__close" type="button" data-admin-transfer-drawer-close aria-label="Cerrar">×</button>
                                </header>

                                <div class="transfers-drawer__body">
                                    <div class="transfers-drawer__status" id="admin-transfer-drawer-status-pill"></div>

                                    <dl class="transfers-drawer__meta">
                                        <div><dt>Origen</dt><dd id="admin-transfer-drawer-from">—</dd></div>
                                        <div><dt>Destino</dt><dd id="admin-transfer-drawer-to">—</dd></div>
                                        <div><dt>Referencia</dt><dd id="admin-transfer-drawer-reference">—</dd></div>
                                        <div><dt>Motivo</dt><dd id="admin-transfer-drawer-reason">—</dd></div>
                                        <div><dt>Solicitado</dt><dd id="admin-transfer-drawer-requested-at">—</dd></div>
                                        <div><dt>Preparado</dt><dd id="admin-transfer-drawer-prepared-at">—</dd></div>
                                        <div><dt>Despachado</dt><dd id="admin-transfer-drawer-dispatched-at">—</dd></div>
                                        <div><dt>Recibido</dt><dd id="admin-transfer-drawer-received-at">—</dd></div>
                                        <div><dt>Cancelado</dt><dd id="admin-transfer-drawer-cancelled-at">—</dd></div>
                                    </dl>

                                    <h4 class="transfers-drawer__section-title">Productos</h4>
                                    <div class="transfers-drawer__items" id="admin-transfer-drawer-items"></div>

                                    <h4 class="transfers-drawer__section-title">Historial</h4>
                                    <div class="transfers-drawer__audit" id="admin-transfer-drawer-audit"></div>

                                    <div class="transfers-drawer__action-bar" id="admin-transfer-drawer-actions"></div>

                                    <div class="transfers-drawer__form" id="admin-transfer-drawer-form" hidden></div>

                                    <p class="dashboard-status" id="admin-transfer-drawer-feedback" role="status" aria-live="polite"></p>
                                </div>
                            </div>
                        </aside>

                        <aside class="transfers-imei-picker" id="admin-transfer-imei-picker" hidden>
                            <div class="transfers-imei-picker__backdrop" data-admin-imei-picker-close></div>
                            <div class="transfers-imei-picker__panel" role="dialog" aria-labelledby="admin-imei-picker-title" aria-modal="true">
                                <header class="transfers-imei-picker__header">
                                    <div>
                                        <span class="soft-badge">IMEI / SERIAL</span>
                                        <h3 id="admin-imei-picker-title">Seleccionar seriales</h3>
                                        <p id="admin-imei-picker-subtitle">—</p>
                                    </div>
                                    <button class="transfers-imei-picker__close" type="button" data-admin-imei-picker-close aria-label="Cerrar">×</button>
                                </header>
                                <div class="transfers-imei-picker__body">
                                    <label class="field">
                                        <span>Buscar</span>
                                        <input id="admin-imei-picker-search" type="search" placeholder="IMEI, serial o codigo">
                                    </label>
                                    <p class="dashboard-status" id="admin-imei-picker-status" role="status" aria-live="polite">Cargando seriales disponibles...</p>
                                    <div class="transfers-imei-picker__list" id="admin-imei-picker-list"></div>
                                </div>
                                <footer class="transfers-imei-picker__footer">
                                    <span id="admin-imei-picker-counter" class="transfers-imei-picker__counter">0 seleccionados</span>
                                    <div>
                                        <button class="ghost-button" type="button" data-admin-imei-picker-close>Cancelar</button>
                                        <button class="primary-button" type="button" id="admin-imei-picker-confirm">Aplicar selección</button>
                                    </div>
                                </footer>
                            </div>
                        </aside>

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
                                            <p>Selecciona un usuario para cambiar sus perfiles, permisos extra o capacidades.</p>
                                        </div>
                                    </div>

                                    <div class="access-subtabs" role="tablist" aria-label="Detalle del usuario">
                                        <button class="access-subtab is-active" type="button" data-user-subtab="roles">Perfiles</button>
                                        <button class="access-subtab" type="button" data-user-subtab="overrides">Permisos extra</button>
                                        <button class="access-subtab" type="button" data-user-subtab="capabilities">Capacidades</button>
                                    </div>

                                    <div class="access-subpanel" data-user-subpanel="roles">
                                        <label class="field">
                                            <span>Perfiles asignados</span>
                                            <select id="admin-access-selected-user-roles" multiple size="7"></select>
                                        </label>
                                        <div class="access-actions">
                                            <button class="primary-button" type="button" id="admin-access-save-user-roles">Guardar perfiles</button>
                                            <button class="ghost-button" type="button" id="admin-access-toggle-user-status">Activar / inactivar</button>
                                        </div>
                                    </div>

                                    <div class="access-subpanel" data-user-subpanel="overrides" hidden>
                                        <div class="panel-heading">
                                            <h5>Permisos extra (overrides)</h5>
                                            <p>Asigna permisos individuales que el usuario tiene ADEMAS de sus perfiles, o quitale permisos que sus perfiles le darian.</p>
                                        </div>
                                        <div class="overrides-editor">
                                            <div class="override-section override-section--add">
                                                <label class="field">
                                                    <span>Buscar permiso del catalogo</span>
                                                    <select id="admin-access-overrides-add" data-overrides-add></select>
                                                </label>
                                                <div class="access-actions">
                                                    <button class="primary-button" type="button" id="admin-access-overrides-allow-btn">+ Asignar (allow)</button>
                                                    <button class="ghost-button" type="button" id="admin-access-overrides-deny-btn">+ Asignar (deny)</button>
                                                </div>
                                            </div>
                                            <div class="override-section">
                                                <h6>Extras (allow) <span class="status-pill" id="admin-access-overrides-extras-count">0</span></h6>
                                                <ul id="admin-access-overrides-extras" class="override-list"></ul>
                                            </div>
                                            <div class="override-section">
                                                <h6>Denegados (deny) <span class="status-pill" id="admin-access-overrides-denied-count">0</span></h6>
                                                <ul id="admin-access-overrides-denied" class="override-list"></ul>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="access-subpanel" data-user-subpanel="capabilities" hidden>
                                        <div class="panel-heading">
                                            <h5>Capacidades efectivas</h5>
                                            <p>Lo que el usuario puede hacer REALMENTE = permisos de sus perfiles + extras - denegados.</p>
                                        </div>
                                        <div id="admin-access-capabilities-summary" class="capabilities-summary">
                                            <p class="access-empty">Selecciona un usuario y abre esta pestana para ver sus capacidades.</p>
                                        </div>
                                        <details class="capabilities-detail">
                                            <summary>Ver lista completa de permisos efectivos</summary>
                                            <pre id="admin-access-capabilities-json" class="capabilities-json"></pre>
                                        </details>
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
