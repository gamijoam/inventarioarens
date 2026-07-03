<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php($appName = config('app.name', 'Sistema de Inventario'))

        <title>{{ $appName }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div id="app" class="app-shell">
            <main class="login-screen" aria-labelledby="login-title">
                <section class="login-workspace" aria-label="Acceso a {{ $appName }}">
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
                        </form>

                        <div class="login-card__footer">
                            <span>Token por empresa</span>
                            <span>Permisos validados por backend</span>
                        </div>
                    </div>

                    <div class="session-card" data-view="session" hidden>
                        <span class="secure-badge">
                            <span aria-hidden="true">+</span>
                            Sesion activa
                        </span>
                        <h1>Panel preparado</h1>
                        <p id="session-summary"></p>
                        <div class="session-grid">
                            <div>
                                <span>Empresa</span>
                                <strong id="session-tenant"></strong>
                            </div>
                            <div>
                                <span>Permisos</span>
                                <strong id="session-permissions"></strong>
                            </div>
                        </div>
                        <button class="secondary-button" type="button" id="logout-button">Cerrar sesion</button>
                    </div>

                    <p class="login-footnote">2026 {{ $appName }}</p>
                </section>
            </main>
        </div>
    </body>
</html>
