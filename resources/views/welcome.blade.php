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
                            <h1 id="login-title">Iniciar sesión</h1>
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
                                    <label for="password">Contraseña</label>
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
                        <aside class="workspace-sidebar" aria-label="Navegación principal">
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
                                <button class="icon-button mobile-menu-button" type="button" id="toggle-sidebar" aria-label="Mostrar menú">
                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                        <path d="M4 7h16M4 12h16M4 17h16"></path>
                                    </svg>
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
                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                        <path d="M6 6h15l-2 8H8L6 6Z"></path>
                                        <path d="M6 6 5 3H2"></path>
                                        <path d="M9 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2ZM18 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"></path>
                                    </svg>
                                    Vender
                                </button>

                                <button class="topbar-action" type="button" data-requires-any="reports.view finance_reports.view">
                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                        <path d="M4 19V5"></path>
                                        <path d="M9 19v-7"></path>
                                        <path d="M14 19V8"></path>
                                        <path d="M19 19v-4"></path>
                                    </svg>
                                    Reportes
                                </button>

                                <button class="icon-button" type="button" aria-label="Ayuda">
                                    <span aria-hidden="true">?</span>
                                </button>

                                <button class="user-button" type="button" id="user-initials" aria-label="Usuario"></button>

                                <button class="topbar-action topbar-action--logout" type="button" data-logout-action>
                                    Cerrar sesión
                                </button>
                            </header>

                            <main class="workspace-content">
                                <section class="workspace-panel dashboard-view" data-panel="dashboard" aria-labelledby="dashboard-title">
                                <div class="dashboard-hero">
                                    <div>
                                        <p class="eyebrow">Operación</p>
                                        <h1 id="dashboard-title">Resumen del negocio</h1>
                                        <p id="session-summary"></p>
                                    </div>
                                    <div class="hero-actions">
                                        <button class="segmented-button is-active" type="button">Hoy</button>
                                        <button class="segmented-button" type="button">Semana</button>
                                        <button class="segmented-button" type="button">Mes</button>
                                    </div>
                                </div>

                                <div class="metric-grid" aria-label="Indicadores principales">
                                    <article class="metric-card metric-card--green">
                                        <span>Ingresos</span>
                                        <strong data-dashboard-value="sales_total">$0.00</strong>
                                        <small data-dashboard-detail="sales_count">Cargando ventas...</small>
                                    </article>
                                    <article class="metric-card metric-card--violet">
                                        <span>POS cobrado</span>
                                        <strong data-dashboard-value="pos_paid">$0.00</strong>
                                        <small data-dashboard-detail="pos_count">Cargando POS...</small>
                                    </article>
                                    <article class="metric-card metric-card--blue">
                                        <span>Transacciones</span>
                                        <strong data-dashboard-value="transactions">0</strong>
                                        <small data-dashboard-detail="cash_register">Cargando caja...</small>
                                    </article>
                                    <article class="metric-card metric-card--orange">
                                        <span>Cuentas pendientes</span>
                                        <strong data-dashboard-value="pending_balance">$0.00</strong>
                                        <small data-dashboard-detail="pending_counts">Cargando finanzas...</small>
                                    </article>
                                </div>

                                <section class="attention-panel">
                                    <div class="section-heading">
                                        <span aria-hidden="true">!</span>
                                        <strong>Requieren atencion</strong>
                                    </div>
                                    <p class="panel-status" id="dashboard-status" role="status" aria-live="polite"></p>
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
                                            <strong>Sesión y permisos</strong>
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
                                        <button class="secondary-button compact-button" type="button" data-logout-action>Cerrar sesión</button>
                                    </article>
                                </section>
                                </section>

                                <section class="workspace-panel inventory-view" data-panel="inventory" aria-labelledby="inventory-title" hidden>
                                    <div class="module-header">
                                        <div>
                                            <p class="eyebrow">Inventario</p>
                                            <h1 id="inventory-title">Centro de Inventario</h1>
                                            <p>Catálogo vivo con stock agregado por producto, seriales y disponibilidad para venta.</p>
                                        </div>
                                        <div class="module-actions">
                                            <button class="secondary-button compact-action" type="button">Exportar</button>
                                            <button class="primary-button compact-action" type="button" id="open-product-form" data-requires-any="products.create">Nuevo producto</button>
                                        </div>
                                    </div>

                                    <div class="module-tabs" aria-label="Secciones de inventario">
                                        <button class="module-tab is-active" type="button">Productos</button>
                                        <button class="module-tab" type="button">Seriales</button>
                                        <button class="module-tab" type="button">Kardex</button>
                                        <button class="module-tab" type="button">Traslados</button>
                                        <button class="module-tab" type="button">Almacenes</button>
                                    </div>

                                    <div class="inventory-toolbar">
                                        <label class="search-box" for="inventory-search">
                                            <span aria-hidden="true">@</span>
                                            <input id="inventory-search" type="search" placeholder="Buscar por nombre, SKU o serial...">
                                        </label>
                                        <div class="inventory-controls">
                                            <div class="filter-group" aria-label="Filtro de stock">
                                                <button class="filter-chip is-active" type="button" data-inventory-filter="all">Todos</button>
                                                <button class="filter-chip" type="button" data-inventory-filter="available">Disponibles</button>
                                                <button class="filter-chip" type="button" data-inventory-filter="low">Bajo stock</button>
                                                <button class="filter-chip" type="button" data-inventory-filter="out">Sin stock</button>
                                            </div>
                                            <div class="filter-group" aria-label="Tipo de control">
                                                <button class="filter-chip is-active" type="button" data-inventory-tracking="all">Todos</button>
                                                <button class="filter-chip" type="button" data-inventory-tracking="quantity">Cantidad</button>
                                                <button class="filter-chip" type="button" data-inventory-tracking="serialized">Serializados</button>
                                            </div>
                                            <div class="view-toggle" aria-label="Modo de visualización">
                                                <button class="view-toggle__button is-active" type="button" data-inventory-view="cards">Tarjetas</button>
                                                <button class="view-toggle__button" type="button" data-inventory-view="list">Lista</button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="inventory-metrics" aria-label="Metricas de inventario">
                                        <article class="inventory-metric">
                                            <span>Productos</span>
                                            <strong data-inventory-metric="total_products">0</strong>
                                            <small data-inventory-detail="serialized_products">0 serializados</small>
                                        </article>
                                        <article class="inventory-metric">
                                            <span>Disponible</span>
                                            <strong data-inventory-metric="available_quantity">0</strong>
                                            <small data-inventory-detail="reserved_quantity">0 reservados</small>
                                        </article>
                                        <article class="inventory-metric inventory-metric--warning">
                                            <span>Stock bajo</span>
                                            <strong data-inventory-metric="low_stock_count">0</strong>
                                            <small data-inventory-detail="without_stock_count">0 sin stock</small>
                                        </article>
                                        <article class="inventory-metric">
                                            <span>Dañados</span>
                                            <strong data-inventory-metric="damaged_quantity">0</strong>
                                            <small>Unidades no disponibles</small>
                                        </article>
                                    </div>

                                    <p class="panel-status" id="inventory-status" role="status" aria-live="polite"></p>
                                    <div class="product-grid" id="inventory-products"></div>
                                    <div class="inventory-pagination" id="inventory-pagination" hidden>
                                        <span id="inventory-pagination-summary"></span>
                                        <div>
                                            <button class="secondary-button compact-action" type="button" id="inventory-prev-page">Anterior</button>
                                            <button class="secondary-button compact-action" type="button" id="inventory-next-page">Siguiente</button>
                                        </div>
                                    </div>
                                </section>

                                <section class="workspace-panel stock-operations-view" data-panel="stock-operations" aria-labelledby="stock-operations-title" hidden>
                                    <div class="module-header">
                                        <div>
                                            <p class="eyebrow">Inventario</p>
                                            <h1 id="stock-operations-title">Entradas y salidas</h1>
                                            <p>Registra recepciones de stock por almacén. Los productos serializados aceptan carga múltiple de IMEIs.</p>
                                        </div>
                                        <div class="module-actions">
                                            <button class="secondary-button compact-action" type="button" id="refresh-entry-options">Actualizar datos</button>
                                        </div>
                                    </div>

                                    <div class="operation-layout">
                                        <section class="operation-card">
                                            <div class="operation-card__header">
                                                <div>
                                                    <p class="eyebrow">Entrada</p>
                                                    <h2>Recepción de productos</h2>
                                                </div>
                                                <span class="operation-pill" id="entry-tracking-pill">Cantidad</span>
                                            </div>

                                            <form class="product-form operation-form" id="product-entry-form">
                                                <div class="form-grid">
                                                    <label class="field">
                                                        <span>Almacén destino</span>
                                                        <select id="entry-warehouse" required>
                                                            <option value="">Cargando almacenes...</option>
                                                        </select>
                                                    </label>

                                                    <label class="field">
                                                        <span>Producto</span>
                                                        <select id="entry-product" required>
                                                            <option value="">Cargando productos...</option>
                                                        </select>
                                                    </label>

                                                    <label class="field">
                                                        <span>Motivo</span>
                                                        <input id="entry-reason" type="text" maxlength="150" value="Recepción de mercancía" required>
                                                    </label>

                                                    <label class="field">
                                                        <span>Referencia</span>
                                                        <input id="entry-reference" type="text" maxlength="150" placeholder="Factura, guía o nota">
                                                    </label>

                                                    <label class="field">
                                                        <span>Cantidad</span>
                                                        <input id="entry-quantity" type="number" min="0.0001" step="0.0001" value="1" required>
                                                        <small class="field-help" id="entry-quantity-help">Para productos serializados se calcula por los IMEIs escritos.</small>
                                                    </label>

                                                    <label class="field">
                                                        <span>Costo unitario</span>
                                                        <input id="entry-unit-cost" type="number" min="0" step="0.01" placeholder="0.00">
                                                    </label>

                                                    <label class="field form-grid__wide">
                                                        <span>IMEIs / seriales</span>
                                                        <textarea id="entry-serials" rows="7" placeholder="Un IMEI o serial por línea"></textarea>
                                                        <small class="field-help" id="entry-serials-help">Disponible cuando el producto seleccionado es serializado.</small>
                                                    </label>

                                                    <label class="field form-grid__wide">
                                                        <span>Notas</span>
                                                        <textarea id="entry-notes" rows="3" maxlength="1000" placeholder="Observaciones internas de la recepción"></textarea>
                                                    </label>
                                                </div>

                                                <p class="form-message" id="product-entry-message" role="status" aria-live="polite"></p>

                                                <div class="modal-actions">
                                                    <button class="secondary-button compact-action" type="button" id="clear-entry-form">Limpiar</button>
                                                    <button class="primary-button compact-action" type="submit" id="save-entry-button" data-requires-any="product_entries.create">Registrar entrada</button>
                                                </div>
                                            </form>
                                        </section>

                                        <aside class="operation-card operation-card--summary">
                                            <p class="eyebrow">Resumen</p>
                                            <dl class="entry-summary">
                                                <div>
                                                    <dt>Producto</dt>
                                                    <dd id="entry-summary-product">Selecciona un producto</dd>
                                                </div>
                                                <div>
                                                    <dt>Almacén</dt>
                                                    <dd id="entry-summary-warehouse">Selecciona un almacén</dd>
                                                </div>
                                                <div>
                                                    <dt>Unidades a recibir</dt>
                                                    <dd id="entry-summary-quantity">0</dd>
                                                </div>
                                                <div>
                                                    <dt>Tipo</dt>
                                                    <dd id="entry-summary-tracking">Por cantidad</dd>
                                                </div>
                                            </dl>
                                        </aside>
                                    </div>
                                </section>
                            </main>
                        </div>
                    </div>

                    <div class="modal-backdrop" id="product-modal" hidden>
                        <section class="product-modal" role="dialog" aria-modal="true" aria-labelledby="product-modal-title">
                            <div class="product-modal__header">
                                <div>
                                    <p class="eyebrow">Catálogo</p>
                                    <h2 id="product-modal-title">Nuevo producto</h2>
                                    <p id="product-modal-subtitle">Crea un producto para venderlo, moverlo y medirlo desde inventario.</p>
                                </div>
                                <button class="icon-button" type="button" id="close-product-form" aria-label="Cerrar formulario">×</button>
                            </div>

                            <form class="product-form" id="product-form">
                                <input type="hidden" id="product-id">

                                <div class="form-grid">
                                    <label class="field">
                                        <span>Nombre del producto</span>
                                        <input id="product-name" name="name" type="text" placeholder="Samsung A06 128GB" required>
                                    </label>

                                    <label class="field">
                                        <span>SKU</span>
                                        <input id="product-sku" name="sku" type="text" placeholder="SAMSUNG-A06" required>
                                    </label>

                                    <label class="field">
                                        <span>Tipo de control</span>
                                        <select id="product-tracking-type" name="tracking_type">
                                            <option value="quantity">Por cantidad</option>
                                            <option value="serialized">Serializado / IMEI</option>
                                        </select>
                                        <small class="field-help" id="product-tracking-help"></small>
                                    </label>

                                    <label class="field">
                                        <span>Precio base USD</span>
                                        <input id="product-base-price" name="base_price" type="number" min="0" step="0.01" placeholder="0.00">
                                    </label>

                                    <label class="field">
                                        <span>Moneda de venta</span>
                                        <select id="product-sale-currency" name="sale_currency">
                                            <option value="USD">Dólares</option>
                                            <option value="VES">Bolívares</option>
                                        </select>
                                    </label>

                                    <label class="field">
                                        <span>Tipo de tasa</span>
                                        <select id="product-rate-type" name="sale_exchange_rate_type_id">
                                            <option value="">Usar tasa predeterminada</option>
                                        </select>
                                    </label>

                                    <label class="field form-grid__wide">
                                        <span>Política de garantía</span>
                                        <select id="product-warranty-policy" name="warranty_policy_id">
                                            <option value="">Sin garantía asignada</option>
                                        </select>
                                    </label>

                                    <label class="switch-field form-grid__wide">
                                        <input id="product-is-active" name="is_active" type="checkbox" checked>
                                        <span>Producto activo</span>
                                    </label>
                                </div>

                                <p class="form-message" id="product-form-message" role="status" aria-live="polite"></p>

                                <div class="modal-actions">
                                    <button class="secondary-button compact-action" type="button" id="cancel-product-form">Cancelar</button>
                                    <button class="primary-button compact-action" type="submit" id="save-product-button">Guardar producto</button>
                                </div>
                            </form>
                        </section>
                    </div>

                    <div class="modal-backdrop" id="product-detail-modal" hidden>
                        <section class="product-modal product-detail-modal" role="dialog" aria-modal="true" aria-labelledby="product-detail-title">
                            <div class="product-modal__header">
                                <div>
                                    <p class="eyebrow">Detalle de producto</p>
                                    <h2 id="product-detail-title">Producto</h2>
                                    <p id="product-detail-subtitle">Consulta stock por almacén, seriales y movimientos recientes.</p>
                                </div>
                                <button class="icon-button" type="button" id="close-product-detail" aria-label="Cerrar detalle">×</button>
                            </div>

                            <div class="product-detail-body">
                                <p class="form-message" id="product-detail-message" role="status" aria-live="polite"></p>
                                <div id="product-detail-content"></div>
                            </div>
                        </section>
                    </div>

                    <p class="login-footnote">2026 {{ $appName }}</p>
                </section>
            </main>
        </div>
    </body>
</html>
