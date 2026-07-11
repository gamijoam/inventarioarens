# Auditoría Backend INVENTARIOARENS — 2026-07-11

Auditoría read-only del backend (PHP/Laravel 13) realizada por opencode.
Scope: `app/`, `database/`, `routes/`, `tests/`. NO incluye desktop WPF ni frontend web.

## Índice de documentos

| # | Documento | Score | Descripción |
|---|---|---:|---|
| 00 | **[00_RESUMEN_EJECUTIVO.md](00_RESUMEN_EJECUTIVO.md)** | **6.8/10** | Veredicto global + top scores + bugs críticos + roadmap |
| 01 | [01_MULTI_TENANCY.md](01_MULTI_TENANCY.md) | 8.5/10 | Trait, scope, middleware, FKs compuestas, policies |
| 02 | [02_AUTH_SEGURIDAD.md](02_AUTH_SEGURIDAD.md) | 6.5/10 | Tokens, login, brute-force, headers, CORS |
| 03 | [03_SYNC_ENGINE.md](03_SYNC_ENGINE.md) | 6/10 | Outbox/inbox, idempotencia, applier, snapshot |
| 04 | [04_INVENTARIO_IMEI.md](04_INVENTARIO_IMEI.md) | 6/10 | Stock movements, balances, IMEIs, atomicidad |
| 05 | [05_POS_CAJA_TASAS.md](05_POS_CAJA_TASAS.md) | 7/10 | POS, caja, métodos pago, exchange rates |
| 06 | [06_TRASLADOS.md](06_TRASLADOS.md) | 7/10 | Traslados logísticos + inter-company |
| 07 | [07_CXC_CXP_GARANTIAS.md](07_CXC_CXP_GARANTIAS.md) | 6.5/10 | CxC, CxP, recibos, garantías, ajustes |
| 08 | [08_API_DESIGN.md](08_API_DESIGN.md) | 7/10 | REST, FormRequest, Resources, versionado, OpenAPI |
| 09 | [09_PERFORMANCE.md](09_PERFORMANCE.md) | 5.5/10 | N+1, índices, caching, queue, observability |
| 10 | [10_CALIDAD_TESTS.md](10_CALIDAD_TESTS.md) | 6/7 | God-services, code smells, cobertura de tests |

## Documentos operativos

- **[ROADMAP.md](ROADMAP.md)** — Checklist tachable con todos los fixes priorizados (P0/P1/P2/P3/P4). Actualizar después de cada cambio.
- **[CONTRATO_PARA_FRONTEND.md](CONTRATO_PARA_FRONTEND.md)** — Lo que la IA del frontend necesita saber del backend para consumir la API correctamente.

## Metodología

- 10 agentes en paralelo, cada uno con un scope delimitado.
- Read-only. Cero código modificado durante la auditoría.
- Cada hallazgo tiene referencia `file:line` específica.
- Score por dimensión (1-10) con justificación cuantitativa.
