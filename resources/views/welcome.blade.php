<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php($appName = config('app.name', 'Sistema de Inventario'))
        @php($devBypassLogin = app()->environment('local') && (bool) env('FRONTEND_DEV_BYPASS_LOGIN', false))

        <title>{{ $appName }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div id="app" class="app-shell">
            <main class="login-screen" aria-labelledby="login-title">
                <section class="login-workspace" aria-label="Acceso a {{ $appName }}" data-dev-bypass-login="{{ $devBypassLogin ? 'true' : 'false' }}">
                    <div class="login-brand">
                        <div class="brand-mark">
                            <div class="brand-mark__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" role="img">
                                    <path d="M5 7.5h14v12H5z"></path>
                                    <path d="M8 7.5V5.8A2.8 2.8 0 0 1 10.8 3h2.4A2.8 2.8 0 0 1 16 5.8v1.7"></path>
                                    <path d="M8 12h8M8 16h5"></path>
                                </svg>
                            </div>
                            <div>
                                <strong>{{ $appName }}</strong>
                                <span>Sistema empresarial</span>
                            </div>
                        </div>
                    </div>

                    <div class="login-card" data-view="login">
                        <div class="login-card__header">
                            <span class="secure-badge">
                                <span aria-hidden="true">+</span>
                                Acceso protegido
                            </span>
                            <h1 id="login-title">Iniciar sesion</h1>
                            <p>Entra al panel para gestionar inventario, caja, ventas y operaciones por empresa.</p>
                        </div>

                        <form class="login-form" id="login-form">
                            <div class="field">
                                <label for="email">Correo</label>
                                <div class="input-wrap">
                                    <span aria-hidden="true">@</span>
                                    <input id="email" name="email" type="email" autocomplete="email" placeholder="gerente.caracas@demo.test" required>
                                </div>
                            </div>

                            <div class="field">
                                <div class="field__row">
                                    <label for="password">Contrasena</label>
                                    <button class="link-button" type="button" id="toggle-password">Mostrar</button>
                                </div>
                                <div class="input-wrap">
                                    <span aria-hidden="true">*</span>
                                    <input id="password" name="password" type="password" autocomplete="current-password" placeholder="password" required>
                                </div>
                            </div>

                            <div class="tenant-picker" id="tenant-picker" hidden>
                                <label for="tenant">Empresa</label>
                                <select id="tenant" name="tenant"></select>
                            </div>

                            <p class="form-message" id="form-message" role="status" aria-live="polite"></p>

                            <button class="primary-button" type="submit" id="submit-button">
                                Ingresar
                                <span aria-hidden="true">-></span>
                            </button>

                            @if ($devBypassLogin)
                                <button class="dev-access-button" type="button" id="dev-access-button">
                                    Entrar en modo demo local
                                </button>
                            @endif
                        </form>

                        <div class="login-card__footer">
                            <span>Token por empresa</span>
                            <span>Permisos validados por backend</span>
                        </div>
                    </div>

                    <div class="workspace-shell" data-view="session" data-app-name="{{ $appName }}" hidden>
                        <aside class="workspace-sidebar" aria-label="Navegacion principal">
                            <div class="workspace-brand">
                                <div class="workspace-brand__mark" aria-hidden="true">{{ mb_substr($appName, 0, 1) }}</div>
                                <div>
                                    <strong>{{ $appName }}</strong>
                                    <span id="session-tenant"></span>
                                </div>
                            </div>

                            <nav class="workspace-nav" id="main-nav"></nav>
                        </aside>

                        <div class="workspace-main">
                            <header class="workspace-topbar">
                                <button class="icon-button mobile-menu-button" type="button" id="toggle-sidebar" aria-label="Mostrar menu">
                                    <span aria-hidden="true">☰</span>
                                </button>

                                <div class="topbar-spacer"></div>

                                <div class="topbar-chip">
                                    <span class="topbar-chip__dot" aria-hidden="true"></span>
                                    <span>BS</span>
                                    <strong>652.97</strong>
                                </div>

                                <div class="topbar-chip topbar-chip--danger">
                                    <span class="topbar-chip__dot" aria-hidden="true"></span>
                                    <strong>Caja cerrada</strong>
                                </div>

                                <button class="topbar-action topbar-action--primary" type="button" data-requires-any="pos.checkout">
                                    <span aria-hidden="true">▣</span>
                                    Vender
                                </button>

                                <button class="topbar-action" type="button" data-requires-any="reports.view finance_reports.view">
                                    <span aria-hidden="true">▥</span>
                                    Reportes
                                </button>

                                <button class="icon-button" type="button" aria-label="Ayuda">
                                    <span aria-hidden="true">?</span>
                                </button>

                                <button class="user-button" type="button" id="user-initials" aria-label="Usuario"></button>
                            </header>

                            <main class="dashboard-view" aria-labelledby="dashboard-title">
                                <section class="dashboard-hero">
                                    <div>
                                        <p class="eyebrow">Operacion</p>
                                        <h1 id="dashboard-title">Resumen del negocio</h1>
                                        <p id="session-summary"></p>
                                    </div>
                                    <div class="hero-actions">
                                        <button class="segmented-button is-active" type="button">Hoy</button>
                                        <button class="segmented-button" type="button">Semana</button>
                                        <button class="segmented-button" type="button">Mes</button>
                                    </div>
                                </section>

                                <section class="metric-grid" aria-label="Indicadores principales">
                                    <article class="metric-card metric-card--green">
                                        <span>Ingresos</span>
                                        <strong>$0.00</strong>
                                        <small>Sin ventas registradas hoy</small>
                                    </article>
                                    <article class="metric-card metric-card--violet">
                                        <span>Ganancia real</span>
                                        <strong>$0.00</strong>
                                        <small>Calculada desde ventas confirmadas</small>
                                    </article>
                                    <article class="metric-card metric-card--blue">
                                        <span>Transacciones</span>
                                        <strong>0</strong>
                                        <small>POS y ventas manuales</small>
                                    </article>
                                    <article class="metric-card metric-card--orange">
                                        <span>Cuentas pendientes</span>
                                        <strong>$0.00</strong>
                                        <small>CxC y CxP disponibles por permiso</small>
                                    </article>
                                </section>

                                <section class="attention-panel">
                                    <div class="section-heading">
                                        <span aria-hidden="true">!</span>
                                        <strong>Requieren atencion</strong>
                                    </div>
                                    <div class="attention-list" id="attention-list"></div>
                                </section>

                                <section class="dashboard-grid">
                                    <article class="work-card">
                                        <div class="section-heading">
                                            <strong>Accesos por modulo</strong>
                                        </div>
                                        <div class="module-shortcuts" id="module-shortcuts"></div>
                                    </article>

                                    <article class="work-card">
                                        <div class="section-heading">
                                            <strong>Sesion y permisos</strong>
                                        </div>
                                        <dl class="session-facts">
                                            <div>
                                                <dt>Usuario</dt>
                                                <dd id="session-user"></dd>
                                            </div>
                                            <div>
                                                <dt>Roles</dt>
                                                <dd id="session-roles"></dd>
                                            </div>
                                            <div>
                                                <dt>Permisos</dt>
                                                <dd id="session-permissions"></dd>
                                            </div>
                                        </dl>
                                        <button class="secondary-button compact-button" type="button" id="logout-button">Cerrar sesion</button>
                                    </article>
                                </section>
                            </main>
                        </div>
                    </div>

                    <p class="login-footnote">2026 {{ $appName }}</p>
                </section>
            </main>
        </div>
    </body>
</html>
