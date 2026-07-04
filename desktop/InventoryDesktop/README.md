# Cliente de escritorio WPF

Este directorio contiene la base de la aplicacion de escritorio del Sistema de Inventario.

## Decision tecnica

- UI: WPF con C#.
- Backend: Laravel API.
- Base de datos: PostgreSQL, accesible solo desde Laravel.
- Autenticacion: token Bearer emitido por `/api/auth/login`.
- Empresa activa: header `X-Tenant`.

## Estructura

```txt
desktop/InventoryDesktop
├── Core
│   ├── Api
│   ├── Security
│   └── ViewModels
├── Modules
│   ├── Auth
│   └── InventoryCenter
├── App.xaml
└── MainWindow.xaml
```

## Primer flujo

1. El usuario indica la URL base de la API.
2. Escribe correo y contrasena.
3. La app consulta `/api/auth/tenants`.
4. El usuario selecciona empresa.
5. La app inicia sesion con `/api/auth/login`.
6. El token se guarda protegido con DPAPI para el usuario actual de Windows.

## Pendiente local

Para compilar se necesita instalar .NET SDK para Windows.

Comando esperado:

```powershell
dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj
```
