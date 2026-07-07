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
                    <span>Métricas, inventario, caja y sincronización por empresa.</span>
                </div>

                <form class="admin-login__card" id="admin-login-form">
                    <span class="soft-badge">Acceso gerencial</span>
                    <h2>Iniciar sesión</h2>
                    <p>Usa tu correo para ver las empresas disponibles y entrar al panel web.</p>

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

            <section class="admin-shell" data-view="dashboard" hidden>
                <header class="admin-topbar">
                    <div class="topbar-title">
                        <div class="brand-orb brand-orb--small" aria-hidden="true">SI</div>
                        <div>
                            <p>Portal administrativo</p>
                            <h1 id="dashboard-tenant">Empresa</h1>
                        </div>
                    </div>

                    <div class="topbar-actions">
                        <select id="dashboard-period" aria-label="Periodo del dashboard">
                            <option value="today">Hoy</option>
                            <option value="week">Semana</option>
                            <option value="month">Mes</option>
                        </select>
                        <button class="ghost-button" type="button" id="dashboard-refresh">Actualizar</button>
                        <button class="danger-button" type="button" id="admin-logout">Salir</button>
                    </div>
                </header>

                <main class="dashboard-grid" id="admin-main">
                    <section class="hero-panel">
                        <div>
                            <span class="soft-badge">Resumen ejecutivo</span>
                            <h2>Estado operativo del negocio</h2>
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

                    <section class="content-panel">
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
                    </section>

                    <section class="content-panel">
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
                    </section>

                    <section class="content-panel content-panel--wide">
                        <div class="panel-heading">
                            <div>
                                <h3>Alertas operativas</h3>
                                <p>Prioridades que requieren revision.</p>
                            </div>
                        </div>
                        <div class="alert-list" id="alert-list"></div>
                    </section>
                </main>

                <p class="dashboard-status" id="dashboard-status" role="status" aria-live="polite"></p>
            </section>
        </div>
    </body>
</html>
