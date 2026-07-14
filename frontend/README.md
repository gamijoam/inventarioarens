# Frontend — INVENTARIOARENS

> **Estado (2026-07-13)**: Carpeta reservada. La implementación arranca con la **Fase 0 — Setup base**
> según `docs/FRONTEND_FASES.md`. Este README documenta los pasos de setup una vez implementado.

---

## Stack

- **Vite 6** + **React 18** + **TypeScript 5**
- **TanStack Router** + **TanStack Query v5** + **TanStack Table v8**
- **Tailwind CSS 4** + **Radix UI primitives** + componentes propios
- **Zustand** (UI state) + **React Hook Form + Zod** (forms) + **Axios** (HTTP)
- **Lucide React** (iconos) + **Sonner** (toasts) + **date-fns** (fechas)
- **Vitest** + **Testing Library** + **Playwright** (tests)

Más detalle en `docs/FRONTEND_ARQUITECTURA.md`.

---

## Setup (Fase 0 — pendiente)

```bash
cd frontend
pnpm install
pnpm dev           # http://localhost:5173
pnpm build         # genera dist/
pnpm preview       # sirve dist/ localmente
pnpm lint          # ESLint
pnpm typecheck     # tsc --noEmit
pnpm test          # Vitest
```

## Variables de entorno

```env
# frontend/.env.local (desarrollo)
VITE_API_BASE_URL=http://127.0.0.1:8000/api
VITE_APP_NAME=Sistema de Inventario

# frontend/.env.production
VITE_API_BASE_URL=https://app.miinventariofacil.com/api
```

---

## Estructura

(Ver `docs/FRONTEND_ARQUITECTURA.md` §3 para el detalle.)

```
frontend/
├── src/
│   ├── api/                # Cliente HTTP + endpoints tipados
│   ├── auth/               # Login + sesión
│   ├── components/         # UI base (Radix + Tailwind)
│   ├── features/           # Código por módulo de negocio
│   ├── hooks/
│   ├── lib/
│   ├── permissions/        # Sistema de permisos (ver docs/FRONTEND_PERMISSIONS.md)
│   ├── scopes/             # Sistema de scopes
│   ├── routes/             # TanStack Router
│   ├── stores/             # Zustand
│   ├── styles/
│   └── types/
├── public/
├── package.json
├── tsconfig.json
├── vite.config.ts
├── tailwind.config.ts
└── README.md
```

---

## Convenciones

(Ver `docs/FRONTEND_ARQUITECTURA.md` §4 para el detalle.)

- **Imports absolutos** con alias `@/` → `src/`.
- **Naming**: PascalCase componentes, camelCase hooks, UPPER_SNAKE_CASE constantes.
- **Tipado estricto** — sin `any` ni `unknown` salvo casos justificados.
- **Comentarios solo donde la lógica no es trivial**, en español.
- **Sin emojis** en código.

---

## Comandos rápidos

| Comando | Descripción |
|---|---|
| `pnpm dev` | Servidor de desarrollo con HMR |
| `pnpm build` | Build de producción → `dist/` |
| `pnpm preview` | Sirve `dist/` para verificar el build |
| `pnpm lint` | ESLint |
| `pnpm lint:fix` | ESLint con autofix |
| `pnpm typecheck` | TypeScript sin emitir |
| `pnpm test` | Vitest (unit + integration) |
| `pnpm test:watch` | Vitest en watch mode |
| `pnpm e2e` | Playwright (E2E) |
| `pnpm e2e:install` | Instala chromium para Playwright |

---

## Sistema de permisos

El frontend **NO calcula permisos en cliente**. Toda la lógica vive en el backend y se consume
declarativamente.

- **Catálogo**: `GET /api/access/permission-catalog` (101 permisos, 33 módulos).
- **Permisos efectivos**: vienen en `GET /api/auth/me` → `permissions[]`.
- **Scopes**: vienen en `GET /api/auth/me` → `scopes{}`.
- **Field masking**: el backend devuelve `null` en campos sensibles si el user no tiene permiso.

**Cómo se usa en el frontend**:

```tsx
import { Can } from '@/components/permissions/Can';
import { useCan } from '@/permissions/useCan';
import { PERMISSIONS } from '@/permissions/constants';

// En componentes:
<Can I={PERMISSIONS.PRODUCTS_CREATE}>
  <Button>Nuevo producto</Button>
</Can>

const canEdit = useCan(PERMISSIONS.PRODUCTS_UPDATE);
```

Ver `docs/FRONTEND_PERMISSIONS.md` para el sistema completo.

---

## Conexión con el backend

- **API base**: configurable vía `VITE_API_BASE_URL` (default `http://127.0.0.1:8000/api`).
- **Headers automáticos**: `Authorization: Bearer <token>` + `X-Tenant: <slug>` (inyectados por el
  Axios interceptor desde el store de sesión).
- **Errores manejados**:
  - `401` → limpiar sesión + redirigir a `/login`.
  - `403` → toast "No tienes permiso para esta acción".
  - `422` → exponer `errors` al formulario (validación server-side).

Ver `docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md` para el contrato API completo.

---

## Roadmap

| Fase | Estado | Alcance |
|---|---|---|
| 0 | ☐ Pendiente | Setup base (Vite + React + TS + Tailwind + Radix + TanStack) |
| 1 | ☐ Pendiente | Auth + multi-tenant + Dashboard + Centro de Inventario |
| 2 | ☐ Pendiente | Compras + POS + Caja registradora |
| 3 | ☐ Pendiente | Traslados + Clientes + Proveedores + CxC/CxP |
| 4 | ☐ Pendiente | Access Control (usuarios, roles, permisos, scopes) |
| 5 | ☐ Pendiente | SaaS Master (Platform Admin) |
| 6 | ☐ Pendiente | PWA + Offline |
| 7 | ☐ Pendiente | Reportes + Analytics |

Detalle en `docs/FRONTEND_FASES.md`.

---

## Referencias

- `docs/FRONTEND_ARQUITECTURA.md` — arquitectura completa del frontend.
- `docs/FRONTEND_PERMISSIONS.md` — sistema de permisos (3 niveles + scopes + field masking).
- `docs/FRONTEND_FASES.md` — roadmap por fases con entregables.
- `docs/API.md` — catálogo de endpoints del backend.
- `docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md` — contrato API para el frontend.
- `docs/INSTRUCCIONES_FRONTEND_PERMISSIONS.md` — contrato original de permisos (Nivel 1+2).
- `docs/INSTRUCCIONES_FRONTEND_SCOPES.md` — contrato original de scopes (Nivel 3).
- `docs/INSTRUCCIONES_FRONTEND_SAAS_MASTER.md` — contrato para Platform Admin.